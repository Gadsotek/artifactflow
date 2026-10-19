#!/usr/bin/env python3
"""Synthetic PermissionRequest runner tests: no clients, models, or fixtures."""
from __future__ import annotations

from contextlib import ExitStack, contextmanager
import copy
import importlib
import io
import json
import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

from test_codex_session import EXPECTED, HOOKS, PROJECT, config_response, hooks_response, thread_response


FIXTURE = PROJECT / 'storage/framework/testing/codex-permission-synthetic'
OUTSIDE = PROJECT.parent / 'artifactflow-containment-checks/codex-permission-outside-synthetic/source.txt'
SENTINEL = b'artifactflow-owned-permission-canary-synthetic\n'
CONTROLS = {'synthetic-control': 'a' * 64}


class CodexPermissionSessionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.module = importlib.import_module('codex_permission_session')

    @contextmanager
    def driver(self):
        rpc = MagicMock()
        rpc.warning_count = 0
        rpc.approval_request_received = True
        rpc.turn_id = 'synthetic-turn'
        rpc.cancel_response = {'id': 91, 'result': {'decision': 'cancel'}}
        rpc.request.side_effect = [{}, config_response(), hooks_response(), thread_response(),
                                   {'turn': {'id': 'synthetic-turn'}}]
        rpc.collect_turn.return_value = [{'method': 'synthetic-native-event'}]
        with ExitStack() as stack:
            stack.enter_context(patch.object(Path, 'resolve', autospec=True, side_effect=lambda path, *args, **kwargs: path))
            stack.enter_context(patch.object(Path, 'is_symlink', return_value=False))
            stack.enter_context(patch.object(Path, 'is_file', return_value=True))
            read = stack.enter_context(patch.object(self.module, 'read_owned_bytes', return_value=SENTINEL))
            controls = stack.enter_context(patch.object(self.module, 'snapshot_controls', return_value=copy.deepcopy(CONTROLS)))
            stack.enter_context(patch.object(self.module, 'read_project', return_value=(EXPECTED, HOOKS, 'c' * 64, 'd' * 64)))
            stack.enter_context(patch.object(self.module, 'resolve_codex', return_value='/synthetic/codex'))
            stack.enter_context(patch.object(self.module, 'validate_outside_parent'))
            launch = stack.enter_context(patch.object(self.module, 'PermissionAppServer', return_value=rpc))
            validate = stack.enter_context(patch.object(self.module, 'validate_permission_evidence', return_value={'passed': True}))
            yield rpc, controls, read, validate, launch

    def transport(self):
        rpc = self.module.PermissionAppServer.__new__(self.module.PermissionAppServer)
        rpc.events, rpc.sequence, rpc.warning_count = [], 0, 0
        rpc._send, rpc._next = MagicMock(), MagicMock()
        rpc.configure('thread', PROJECT, '/bin/cat /synthetic/outside/source.txt', hooks_response())
        return rpc

    def started(self):
        return {'method': 'turn/started', 'params': {'threadId': 'thread', 'turn': {'id': 'turn', 'status': 'inProgress'}}}

    def approval(self):
        return {'id': 91, 'method': 'item/commandExecution/requestApproval',
                'params': {'threadId': 'thread', 'turnId': 'turn', 'itemId': 'call',
                           'command': '/bin/cat /synthetic/outside/source.txt', 'cwd': str(PROJECT)}}

    def test_success_preserves_client_defaults_and_binds_native_evidence(self):
        with self.driver() as (rpc, _, _, validate, launch):
            result = self.module.run_session(PROJECT, FIXTURE, OUTSIDE, timeout=45)
        self.assertTrue(result['passed'], result)
        self.assertEqual('fresh_project_permission_request_cancelled', result['scope'])
        self.assertTrue(result['controls_unchanged'])
        self.assertTrue(result['canaries_unchanged'])
        self.assertEqual(['initialize', 'config/read', 'hooks/list', 'thread/start', 'turn/start'],
                         [call.args[0] for call in rpc.request.call_args_list])
        self.assertEqual({'ephemeral': True}, rpc.request.call_args_list[3].args[1])
        params = rpc.request.call_args_list[4].args[1]
        self.assertEqual({'threadId', 'input'}, set(params))
        self.assertEqual('text', params['input'][0]['type'])
        self.assertIn('sandbox_permissions=require_escalated', params['input'][0]['text'])
        self.assertIn('no prefix_rule', params['input'][0]['text'])
        self.assertIn('cancel the request', params['input'][0]['text'])
        self.assertNotIn('decline', params['input'][0]['text'])
        command = '/bin/cat ' + str(OUTSIDE)
        rpc.configure.assert_called_once_with('synthetic-thread', PROJECT, command, hooks_response())
        validate.assert_called_once_with(rpc.collect_turn.return_value, 'synthetic-thread', 'synthetic-turn',
                                         PROJECT, command, hooks_response(), rpc.cancel_response)
        launch.assert_called_once_with('/synthetic/codex', PROJECT, 45)
        rpc.close.assert_called_once()

    def test_rejected_or_malformed_evidence_never_passes(self):
        for evidence in ({'passed': False}, {'passed': 1}, None, []):
            with self.subTest(evidence=evidence), self.driver() as (_, _, _, validate, _):
                validate.return_value = evidence
                result = self.module.run_session(PROJECT, FIXTURE, OUTSIDE)
            self.assertFalse(result['passed'])

    def test_changed_controls_or_canary_fail_closed_without_content_output(self):
        for changed in ('controls', 'inside', 'outside'):
            with self.subTest(changed=changed), self.driver() as (_, controls, read, _, _):
                if changed == 'controls':
                    controls.side_effect = [CONTROLS, CONTROLS, {'synthetic-control': 'b' * 64}]
                else:
                    read.side_effect = [SENTINEL, SENTINEL,
                                        b'private-changed-content' if changed == 'inside' else SENTINEL,
                                        b'private-changed-content' if changed == 'outside' else SENTINEL]
                result = self.module.run_session(PROJECT, FIXTURE, OUTSIDE)
            self.assertFalse(result['passed'])
            self.assertNotIn('private-changed-content', json.dumps(result))

    def test_timeout_and_cleanup_failure_cannot_pass(self):
        for failure in ('timeout', 'cleanup'):
            with self.subTest(failure=failure), self.driver() as (rpc, _, _, _, _):
                if failure == 'timeout':
                    rpc.collect_turn.side_effect = TimeoutError()
                else:
                    rpc.close.side_effect = OSError('synthetic-private-error')
                result = self.module.run_session(PROJECT, FIXTURE, OUTSIDE)
            self.assertFalse(result['passed'])
            rpc.close.assert_called_once()
            self.assertNotIn('synthetic-private-error', json.dumps(result))

    def test_failure_receipt_reports_received_and_sent_stages_without_raw_request(self):
        for received, sent in ((False, False), (True, False), (True, True)):
            with self.subTest(received=received, sent=sent), self.driver() as (rpc, _, _, _, _):
                rpc.approval_request_received = received
                rpc.cancel_response = {'id': 91, 'result': {'decision': 'cancel'}} if sent else None
                rpc.collect_turn.side_effect = self.module.SessionError('permission_request_mismatch')
                result = self.module.run_session(PROJECT, FIXTURE, OUTSIDE)
            self.assertFalse(result['passed'])
            self.assertIs(received, result['approval_request_received'])
            self.assertIs(sent, result['cancel_response_sent'])
            self.assertEqual('/synthetic/codex', result['executable'])
            self.assertNotIn('cancel_response', result)
            self.assertNotIn('decline_response_sent', result)
            self.assertNotIn('events', result)

    def test_request_received_is_recorded_before_validation_but_not_for_other_requests(self):
        for method, expected in (('item/commandExecution/requestApproval', True), ('item/fileChange/requestApproval', False)):
            rpc = self.transport()
            request = self.approval()
            request['method'] = method
            with self.subTest(method=method), self.assertRaises(self.module.SessionError):
                rpc.record(request)
            self.assertIs(expected, rpc.approval_request_received)
            self.assertIsNone(rpc.cancel_response)
            rpc._send.assert_not_called()

    def test_returned_turn_id_must_match_native_turn_binding(self):
        with self.driver() as (rpc, _, _, validate, _):
            rpc.turn_id = 'foreign-turn'
            result = self.module.run_session(PROJECT, FIXTURE, OUTSIDE)
        self.assertFalse(result['passed'])
        validate.assert_not_called()

    def test_invalid_fixture_or_timeout_never_starts_client(self):
        for fixture, outside, timeout in ((Path('/unowned'), OUTSIDE, 120), (FIXTURE, PROJECT / 'inside', 120), (FIXTURE, OUTSIDE, 301)):
            with self.subTest(fixture=fixture, outside=outside, timeout=timeout), self.driver() as (_, _, _, _, launch):
                result = self.module.run_session(PROJECT, fixture, outside, timeout=timeout)
            self.assertFalse(result['passed'])
            launch.assert_not_called()

    def test_matching_validated_request_gets_only_exact_cancel(self):
        rpc = self.transport()
        rpc.record(self.started())
        request = self.approval()
        with patch.object(self.module, 'validate_approval_request', return_value={'passed': True}) as validate:
            rpc.record(request)
        validate.assert_called_once_with(request, rpc.events, 'thread', 'turn', PROJECT,
                                         '/bin/cat /synthetic/outside/source.txt', hooks_response())
        rpc._send.assert_called_once_with({'id': 91, 'result': {'decision': 'cancel'}})
        self.assertEqual({'id': 91, 'result': {'decision': 'cancel'}}, rpc.cancel_response)
        self.assertIn(request, rpc.events)

    def test_request_before_turn_response_is_cancelled_after_native_turn_binding(self):
        rpc = self.transport()
        rpc._next.side_effect = [self.started(), self.approval(), {'id': 1, 'result': {'turn': {'id': 'turn'}}}]
        with patch.object(self.module, 'validate_approval_request', return_value={'passed': True}):
            response = rpc.request('turn/start', {'threadId': 'thread'})
        self.assertEqual('turn', response['turn']['id'])
        self.assertEqual('turn', rpc.turn_id)
        self.assertEqual(2, rpc._send.call_count)
        self.assertEqual({'id': 91, 'result': {'decision': 'cancel'}}, rpc._send.call_args.args[0])

    def test_unbound_unexpected_duplicate_or_rejected_requests_get_no_response(self):
        for kind in ('unbound', 'unexpected', 'duplicate', 'rejected'):
            rpc = self.transport()
            if kind != 'unbound':
                rpc.record(self.started())
            request = self.approval()
            if kind == 'unexpected':
                request['method'] = 'item/fileChange/requestApproval'
            if kind == 'duplicate':
                rpc.cancel_response = {'id': 90, 'result': {'decision': 'cancel'}}
            with self.subTest(kind=kind), patch.object(self.module, 'validate_approval_request',
                                                      return_value={'passed': kind != 'rejected'}), \
                    self.assertRaises(self.module.SessionError):
                rpc.record(request)
            rpc._send.assert_not_called()

    def test_failed_cancel_send_never_records_successful_response(self):
        rpc = self.transport()
        rpc.record(self.started())
        rpc._send.side_effect = OSError('synthetic-private-error')
        with patch.object(self.module, 'validate_approval_request', return_value={'passed': True}), self.assertRaises(OSError):
            rpc.record(self.approval())
        self.assertIs(rpc.approval_request_received, True)
        self.assertIsNone(rpc.cancel_response)

    def test_native_request_resolved_event_is_preserved_for_final_validator(self):
        rpc = self.transport()
        event = {'method': 'serverRequest/resolved', 'params': {'threadId': 'thread', 'requestId': 91}}
        rpc.record(event)
        self.assertEqual([event], rpc.events)

    def test_transport_integrates_with_real_validators_using_synthetic_native_events(self):
        from test_codex_permission_evidence import COMMAND, THREAD, TURN, cancelled_response, events, registrations
        rpc = self.transport()
        rpc.configure(THREAD, PROJECT, COMMAND, registrations())
        for event in events():
            rpc.record(event)
        rpc._send.assert_called_once_with(cancelled_response())
        proof = self.module.validate_permission_evidence(rpc.events, THREAD, TURN, PROJECT, COMMAND,
                                                         registrations(), rpc.cancel_response)
        self.assertTrue(proof['passed'])
        self.assertTrue(proof['approval_cancelled'])
        self.assertTrue(proof['turn_interrupted'])
        self.assertFalse(proof['command_executed'])

    def test_cli_without_run_creates_no_client_or_fixture(self):
        with patch.object(sys, 'argv', ['codex_permission_session.py']), patch.object(sys, 'stdout', io.StringIO()), \
                patch.object(self.module, 'create_fixtures') as create, patch.object(self.module, 'run_session') as run:
            self.assertEqual(2, self.module.main())
        create.assert_not_called()
        run.assert_not_called()

    def test_cli_prints_only_fixed_request_check_names(self):
        known = ['permission_request_cwd_mismatch', 'permission_request_command_mismatch']
        for checks, show in ((known, True), (known + ['synthetic-private-detail'], False),
                             ([{'secret': 'synthetic-private-detail'}], False),
                             ('synthetic-private-detail', False), ([], False)):
            result = {'passed': False, 'stage': 'model_turn', 'category': known[0],
                      'details': {'failed_checks': checks}}
            output = io.StringIO()
            with self.subTest(checks=checks), \
                    patch.object(sys, 'argv', ['codex_permission_session.py', '--run']), \
                    patch.object(sys, 'stdout', output), \
                    patch.object(self.module, 'create_fixtures', return_value=(FIXTURE, OUTSIDE)), \
                    patch.object(self.module, 'run_session', return_value=result), \
                    patch.object(self.module, 'OwnedReceipt'):
                self.assertEqual(1, self.module.main())
            self.assertEqual(show, 'Failed checks:' in output.getvalue())
            if show:
                self.assertIn('permission_request_command_mismatch', output.getvalue())
            self.assertNotIn('synthetic-private-detail', output.getvalue())

    def test_fixture_creation_is_bounded_to_owned_project_and_sibling_paths(self):
        with patch.object(self.module, 'validate_outside_parent') as validate, \
                patch.object(Path, 'mkdir') as mkdir, \
                patch.object(Path, 'resolve', autospec=True, side_effect=lambda path, *args, **kwargs: path), \
                patch.object(self.module.tempfile, 'mkdtemp', side_effect=[str(FIXTURE), str(OUTSIDE.parent)]) as create, \
                patch.object(Path, 'write_text', autospec=True) as write:
            fixture, outside = self.module.create_fixtures(PROJECT)
        self.assertEqual((FIXTURE, OUTSIDE), (fixture, outside))
        validate.assert_called_once_with(PROJECT, PROJECT.parent / 'artifactflow-containment-checks')
        self.assertEqual([PROJECT / 'storage/framework/testing', PROJECT.parent / 'artifactflow-containment-checks'],
                         [call.kwargs['dir'] for call in create.call_args_list])
        self.assertEqual([FIXTURE / 'source.txt', OUTSIDE], [call.args[0] for call in write.call_args_list])
        self.assertEqual(write.call_args_list[0].args[1], write.call_args_list[1].args[1])
        self.assertTrue(write.call_args_list[0].args[1].startswith('artifactflow-owned-permission-canary-'))
        self.assertEqual(2, mkdir.call_count)


if __name__ == '__main__':
    unittest.main(verbosity=2)
