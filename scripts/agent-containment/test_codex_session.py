#!/usr/bin/env python3
"""Fresh-session diagnostics tests; every app-server exchange is synthetic."""
from __future__ import annotations

import copy
import importlib
import io
import json
import signal
import subprocess
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch


PROJECT = Path('/synthetic/artifactflow')
PROFILE = {'extends': ':workspace', 'filesystem': {':root': 'deny', ':minimal': 'read'}}
EXPECTED = {'default_permissions': 'artifactflow-edit', 'permissions': {'artifactflow-edit': PROFILE},
            'approval_policy': 'on-request', 'approvals_reviewer': 'user', 'features': {'hooks': True}}
HOOKS = {'hooks': {'PreToolUse': [{'matcher': '*', 'hooks': [
    {'type': 'command', 'command': 'synthetic-safe-hook', 'timeout': 10}]}]}}


def config_response():
    paths = ['default_permissions', 'approval_policy', 'approvals_reviewer', 'features.hooks',
             'permissions.artifactflow-edit.extends',
             'permissions.artifactflow-edit.filesystem.:root',
             'permissions.artifactflow-edit.filesystem.:minimal']
    origin = {'name': {'type': 'project', 'dotCodexFolder': str(PROJECT / '.codex')},
              'version': 'sha256:' + 'a' * 64}
    return {'config': copy.deepcopy(EXPECTED), 'origins': {p: copy.deepcopy(origin) for p in paths}}


def hooks_response():
    return {'data': [{'cwd': str(PROJECT), 'errors': [], 'warnings': [], 'hooks': [{
        'eventName': 'preToolUse', 'handlerType': 'command', 'matcher': '*',
        'command': 'synthetic-safe-hook', 'source': 'project',
        'sourcePath': str(PROJECT / '.codex/hooks.json'), 'enabled': True,
        'trustStatus': 'trusted', 'currentHash': 'b' * 64,
    }]}]}


def thread_response():
    return {'cwd': str(PROJECT), 'runtimeWorkspaceRoots': [str(PROJECT)],
            'activePermissionProfile': {'id': 'artifactflow-edit', 'extends': ':workspace'},
            'approvalPolicy': 'on-request', 'approvalsReviewer': 'user',
            'sandbox': {'type': 'workspaceWrite'}, 'thread': {'id': 'synthetic-thread'},
            'instructionSources': [str(PROJECT / 'AGENTS.md')]}


class CodexSessionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.module = importlib.import_module('codex_session')

    def test_project_provenance_filters_unrelated_private_configuration(self):
        value = config_response()
        value['config']['private_unrelated'] = 'never-return-private-value'
        value['origins']['private_unrelated'] = {'name': {'type': 'user', 'file': '/private/config'}}
        evidence = self.module.project_evidence(value, EXPECTED, PROJECT)
        self.assertTrue(evidence['configuration_matches_project'])
        self.assertNotIn('never-return', json.dumps(evidence))
        self.assertNotIn('/private/config', json.dumps(evidence))

    def test_project_provenance_rejects_missing_user_or_session_origin(self):
        for replacement in (None, {'type': 'user'}, {'type': 'sessionFlags'},
                            {'type': 'project', 'dotCodexFolder': '/another/.codex'}):
            value = config_response()
            if replacement is None:
                del value['origins']['default_permissions']
            else:
                value['origins']['default_permissions']['name'] = replacement
            with self.subTest(replacement=replacement), self.assertRaises(self.module.SessionError):
                self.module.project_evidence(value, EXPECTED, PROJECT)

    def test_replaced_or_extended_filesystem_policy_fails(self):
        for path, access in ((':root', 'read'), ('/unrelated', 'read')):
            value = config_response()
            value['config']['permissions']['artifactflow-edit']['filesystem'][path] = access
            with self.subTest(path=path), self.assertRaises(self.module.SessionError):
                self.module.project_evidence(value, EXPECTED, PROJECT)

    def test_mismatch_diagnostic_reports_booleans_without_unrelated_values(self):
        value = config_response()
        value['config']['default_permissions'] = 'private-not-project-profile'
        with self.assertRaises(self.module.SessionError) as caught:
            self.module.project_evidence(value, EXPECTED, PROJECT)
        self.assertIs(caught.exception.details['profile_name_matches'], False)
        self.assertNotIn('private-not-project-profile', json.dumps(caught.exception.details))

    def test_serialized_null_defaults_are_not_false_profile_mismatches(self):
        value = config_response()
        value['config']['permissions']['artifactflow-edit']['workspace_roots'] = None
        value['config']['permissions']['artifactflow-edit']['filesystem']['glob_scan_max_depth'] = None
        self.assertTrue(self.module.project_evidence(value, EXPECTED, PROJECT)['configuration_matches_project'])

    def test_hook_registration_requires_actual_enabled_trusted_project_commands(self):
        result = self.module.hook_evidence(hooks_response(), HOOKS, PROJECT)
        self.assertTrue(result['registered'])
        for field, replacement in (('enabled', False), ('trustStatus', 'modified'),
                                   ('source', 'user'), ('command', 'other-command')):
            value = hooks_response()
            value['data'][0]['hooks'][0][field] = replacement
            with self.subTest(field=field), self.assertRaises(self.module.SessionError):
                self.module.hook_evidence(value, HOOKS, PROJECT)

    def test_hook_errors_fail_without_exposing_private_text(self):
        value = hooks_response()
        value['data'][0]['errors'] = [{'message': 'private-detail', 'path': '/private/file'}]
        with self.assertRaises(self.module.SessionError) as caught:
            self.module.hook_evidence(value, HOOKS, PROJECT)
        self.assertNotIn('private', str(caught.exception))

    def test_thread_requires_selected_profile_approval_and_exact_workspace(self):
        self.assertTrue(self.module.thread_evidence(thread_response(), EXPECTED, PROJECT)['active'])
        replacements = {'activePermissionProfile': {'id': ':workspace'},
                        'approvalPolicy': 'never', 'approvalsReviewer': 'guardian',
                        'cwd': '/elsewhere', 'runtimeWorkspaceRoots': [str(PROJECT), '/elsewhere'],
                        'sandbox': {'type': 'dangerFullAccess'}, 'instructionSources': []}
        for key, value in replacements.items():
            response = thread_response()
            response[key] = value
            with self.subTest(key=key), self.assertRaises(self.module.SessionError):
                self.module.thread_evidence(response, EXPECTED, PROJECT)

    def setup_driver(self, rpc):
        rpc.request.side_effect = [{}, config_response(), hooks_response(), thread_response()]
        rpc.warning_count = 0
        return patch.multiple(self.module, resolve_codex=MagicMock(return_value='/synthetic/codex'),
                              read_project=MagicMock(return_value=(EXPECTED, HOOKS, 'c' * 64, 'd' * 64)),
                              AppServer=MagicMock(return_value=rpc))

    def test_fresh_start_does_not_inject_settings_or_trigger_cwd_autotrust(self):
        rpc = MagicMock()
        with self.setup_driver(rpc):
            result = self.module.run_session(PROJECT)
        self.assertTrue(result['passed'], result)
        self.assertEqual({'ephemeral': True}, rpc.request.call_args_list[3].args[1])
        self.assertEqual({'cwd': str(PROJECT), 'includeLayers': False}, rpc.request.call_args_list[1].args[1])
        self.assertEqual('initialized', rpc.notify.call_args_list[0].args[0])
        self.assertNotIn('command/exec', [c.args[0] for c in rpc.request.call_args_list])
        rpc.close.assert_called_once()

    def test_inactive_project_stops_before_thread_or_command(self):
        rpc = MagicMock()
        with self.setup_driver(rpc):
            bad = config_response()
            bad['origins'] = {}
            rpc.request.side_effect = [{}, bad]
            result = self.module.run_session(PROJECT, ['/bin/echo', 'unused'], expected_nonce='test')
        self.assertFalse(result['passed'])
        self.assertEqual('project_configuration', result['stage'])
        self.assertIs(result['details']['project_origin_matches'], False)
        self.assertEqual(['initialize', 'config/read'], [c.args[0] for c in rpc.request.call_args_list])
        rpc.close.assert_called_once()

    def test_launch_and_protocol_failures_never_pass_or_echo_errors(self):
        for error in (OSError('private-detail'), self.module.SessionError('invalid_protocol'), TimeoutError()):
            rpc = MagicMock()
            with self.setup_driver(rpc):
                rpc.request.side_effect = error
                result = self.module.run_session(PROJECT)
            self.assertFalse(result['passed'])
            self.assertNotIn('private-detail', json.dumps(result))

    def test_command_requires_nonce_before_launch(self):
        with patch.object(self.module, 'AppServer') as server:
            result = self.module.run_session(PROJECT, ['/bin/echo', 'unused'])
        self.assertFalse(result['passed'])
        server.assert_not_called()

    def test_command_result_requires_integer_zero_exit_and_valid_bound_receipt(self):
        response = {'exitCode': 0, 'stdout': 'ARTIFACTFLOW_DEVELOPMENT_RECEIPT {"safe":true}\n',
                    'stderr': 'unrelated-private-stderr'}
        with patch('development_probe.validate_receipt', return_value=True) as validate:
            evidence = self.module.command_evidence(response, 'expected-nonce')
        validate.assert_called_once_with({'safe': True}, 'expected-nonce', workspace_only=False)
        self.assertTrue(evidence['passed'])
        self.assertNotIn('unrelated-private-stderr', json.dumps(evidence))
        for invalid_exit in (False, None, '0', 1):
            with self.subTest(exit=invalid_exit), patch('development_probe.validate_receipt', return_value=True):
                self.assertFalse(self.module.command_evidence({**response, 'exitCode': invalid_exit}, 'nonce')['passed'])

    def test_duplicate_missing_and_malformed_command_markers_fail(self):
        for stdout in ('private-output', 'ARTIFACTFLOW_DEVELOPMENT_RECEIPT {bad',
                       'ARTIFACTFLOW_DEVELOPMENT_RECEIPT {}\nARTIFACTFLOW_DEVELOPMENT_RECEIPT {}'):
            with self.subTest(stdout=stdout), self.assertRaises(self.module.SessionError):
                self.module.command_evidence({'exitCode': 0, 'stdout': stdout, 'stderr': ''}, 'nonce')

    def test_optional_command_uses_startup_permissions_without_policy_overrides(self):
        rpc = MagicMock()
        response = {'exitCode': 0, 'stdout': 'ARTIFACTFLOW_DEVELOPMENT_RECEIPT {}', 'stderr': ''}
        with self.setup_driver(rpc), patch('development_probe.validate_receipt', return_value=True):
            rpc.request.side_effect = [{}, config_response(), hooks_response(), thread_response(), response]
            result = self.module.run_session(PROJECT, ['/synthetic/probe'], timeout=180, expected_nonce='nonce')
        self.assertTrue(result['passed'], result)
        self.assertEqual({}, result['command_receipt'])
        method, params = rpc.request.call_args_list[-1].args
        self.assertEqual('command/exec', method)
        self.assertEqual({'command', 'cwd', 'timeoutMs', 'outputBytesCap'}, set(params))
        self.assertEqual(str(PROJECT), params['cwd'])

    def test_failed_command_receipt_exposes_only_common_safe_summary(self):
        response = {'exitCode': 1, 'stdout': 'ARTIFACTFLOW_DEVELOPMENT_RECEIPT {"private":"do-not-copy"}', 'stderr': ''}
        with patch('development_probe.validate_receipt', return_value=False):
            result = self.module.command_evidence(response, 'nonce')
        self.assertIsNone(result['receipt'])
        self.assertFalse(result['summary']['passed'])
        self.assertNotIn('do-not-copy', json.dumps(result))

    def test_rpc_error_and_unexpected_request_never_echo_client_content(self):
        for message in ({'id': 1, 'error': {'message': 'private-detail'}},
                        {'id': 1, 'method': 'private-method', 'params': {'secret': 'private-detail'}},
                        {'id': 44, 'result': {}}, {'id': 1, 'result': []}):
            rpc = self.module.AppServer.__new__(self.module.AppServer)
            rpc.sequence = 0
            rpc._send = MagicMock()
            rpc._next = MagicMock(return_value=message)
            with self.subTest(message=message), self.assertRaises(self.module.SessionError) as caught:
                rpc.request('config/read', {})
            self.assertNotIn('private', str(caught.exception))

    def test_rpc_failure_reports_numeric_code_and_fixed_diagnostic_only(self):
        rpc = self.module.AppServer.__new__(self.module.AppServer)
        rpc.sequence = 0
        rpc._send = MagicMock()
        rpc._next = MagicMock(return_value={'id': 1, 'error': {
            'code': -32603, 'message': 'error creating thread: Operation not permitted at /private/value',
            'data': {'secret': 'never-copy-private-data'}}})
        with self.assertRaises(self.module.SessionError) as caught:
            rpc.request('thread/start', {'ephemeral': True})
        details = caught.exception.details
        self.assertEqual(-32603, details['rpc_error_code'])
        self.assertEqual('os_permission_denied', details['rpc_error_diagnostic'])
        self.assertTrue(details['thread_creation_failure'])
        self.assertEqual(64, len(details['message_sha256']))
        self.assertNotIn('private', json.dumps(details))

    def test_unclassified_rpc_message_is_hashed_without_echoing_it(self):
        details = self.module.rpc_error_details({'code': False, 'message': 'unrelated-private-error'})
        self.assertIsNone(details['rpc_error_code'])
        self.assertEqual('unclassified_client_error', details['rpc_error_diagnostic'])
        self.assertNotIn('private', json.dumps(details))

    def test_cleanup_failure_preserves_primary_failure_and_stage(self):
        rpc = MagicMock()
        with self.setup_driver(rpc):
            rpc.request.side_effect = [{}, config_response(), hooks_response(),
                                       self.module.SessionError('client_rpc_error', {'rpc_error_code': -32603})]
            rpc.close.side_effect = OSError('private-cleanup-detail')
            result = self.module.run_session(PROJECT)
        self.assertFalse(result['passed'])
        self.assertEqual('client_rpc_error', result['category'])
        self.assertEqual('thread_activation', result['stage'])
        self.assertEqual(-32603, result['details']['rpc_error_code'])
        self.assertEqual('cleanup_failed', result['cleanup_error'])
        self.assertNotIn('private-cleanup-detail', json.dumps(result))

    def test_transport_registration_failure_still_stops_owned_process(self):
        process = MagicMock(pid=12345)
        selector = MagicMock()
        selector.register.side_effect = OSError('synthetic registration failure')
        with patch.object(self.module.subprocess, 'Popen', return_value=process), \
                patch.object(self.module.selectors, 'DefaultSelector', return_value=selector), \
                patch.object(self.module, 'stop_owned_process_group') as cleanup:
            with self.assertRaises(OSError):
                self.module.AppServer('/synthetic/codex', PROJECT, 2)
        cleanup.assert_called_once_with(process)

    def test_process_group_is_owned_and_cleaned_on_close(self):
        process = MagicMock(pid=12345)
        with patch.object(self.module.subprocess, 'Popen', return_value=process) as popen, \
                patch.object(self.module.selectors, 'DefaultSelector'), \
                patch.object(self.module, 'stop_owned_process_group') as cleanup:
            rpc = self.module.AppServer('/synthetic/codex', PROJECT, 2)
            rpc.close()
        self.assertEqual(['/synthetic/codex', 'app-server', '--stdio'], popen.call_args.args[0])
        self.assertTrue(popen.call_args.kwargs['start_new_session'])
        self.assertEqual(PROJECT, popen.call_args.kwargs['cwd'])
        cleanup.assert_called_once_with(process)

    def test_cleanup_failure_is_reported_and_transport_streams_close(self):
        process = MagicMock(pid=12345)
        with patch.object(self.module.subprocess, 'Popen', return_value=process), \
                patch.object(self.module.selectors, 'DefaultSelector'), \
                patch.object(self.module, 'stop_owned_process_group', side_effect=PermissionError('synthetic denial')):
            rpc = self.module.AppServer('/synthetic/codex', PROJECT, 2)
            with self.assertRaises(PermissionError):
                rpc.close()
        for stream in (process.stdin, process.stdout, process.stderr):
            stream.close.assert_called_once_with()

    def test_metadata_cli_prints_only_inspection_result_and_returns_failed_status(self):
        with patch.object(self.module, 'inspect_session', return_value={'passed': False, 'category': 'synthetic'}) as inspect, \
                patch.object(self.module.sys, 'argv', ['codex_session.py', '--project-root', str(PROJECT), '--timeout', '15']), \
                patch('sys.stdout', new_callable=io.StringIO) as output:
            self.assertEqual(1, self.module.main())
        inspect.assert_called_once_with(PROJECT, timeout=15)
        self.assertEqual({'passed': False, 'category': 'synthetic'}, json.loads(output.getvalue()))


if __name__ == '__main__':
    unittest.main()
