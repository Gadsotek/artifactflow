#!/usr/bin/env python3
"""Synthetic native-approval protocol tests; no clients or model calls."""
from __future__ import annotations

import copy
import importlib
import json
import shlex
import unittest
from pathlib import Path

from test_codex_hook_evidence import hook_event, registrations


PROJECT = Path('/synthetic/artifactflow')
THREAD, TURN, CALL = 'thread-1', 'turn-1', 'call-1'
COMMAND = '/bin/cat /synthetic/owned-outside/source.txt'
REQUEST_ID = 12


def approval():
    return {'id': REQUEST_ID, 'method': 'item/commandExecution/requestApproval', 'params': {
        'threadId': THREAD, 'turnId': TURN, 'itemId': CALL, 'startedAtMs': 4,
        'kind': 'command', 'approvalId': None, 'command': COMMAND, 'cwd': str(PROJECT),
        'networkApprovalContext': None, 'additionalPermissions': None,
        'proposedExecpolicyAmendment': None, 'proposedNetworkPolicyAmendments': None,
        'availableDecisions': ['accept', 'cancel'],
    }}


def cancelled_response():
    return {'id': REQUEST_ID, 'result': {'decision': 'cancel'}}


def permission_hook(method):
    value = hook_event(method, 'preToolUse', 9)
    value['params']['run'].update(
        eventName='permissionRequest',
        id=f'permission-request:9:{PROJECT}/.codex/hooks.json:{CALL}')
    return value


def command_item(method):
    return {'method': method, 'params': {'threadId': THREAD, 'turnId': TURN, 'item': {
        'type': 'commandExecution', 'id': CALL, 'command': COMMAND, 'cwd': str(PROJECT),
        'source': 'agent', 'status': 'declined' if method == 'item/completed' else 'inProgress',
        'processId': None, 'aggregatedOutput': None, 'exitCode': None, 'durationMs': None,
    }}}


def events():
    return [
        {'method': 'turn/started', 'params': {'threadId': THREAD,
                                            'turn': {'id': TURN, 'status': 'inProgress'}}},
        hook_event('hook/started', 'userPromptSubmit', 3),
        hook_event('hook/completed', 'userPromptSubmit', 3),
        hook_event('hook/started', 'preToolUse', 7),
        hook_event('hook/started', 'preToolUse', 8),
        hook_event('hook/completed', 'preToolUse', 7),
        hook_event('hook/completed', 'preToolUse', 8),
        permission_hook('hook/started'), permission_hook('hook/completed'),
        command_item('item/started'), approval(),
        {'method': 'serverRequest/resolved', 'params': {'threadId': THREAD, 'requestId': REQUEST_ID}},
        command_item('item/completed'),
        {'method': 'turn/completed', 'params': {'threadId': THREAD,
                                              'turn': {'id': TURN, 'status': 'interrupted', 'items': []}}},
    ]


class CodexPermissionEvidenceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.module = importlib.import_module('codex_permission_evidence')

    def validate(self, evidence=None, response=None, registered=None):
        return self.module.validate_permission_evidence(
            events() if evidence is None else evidence, THREAD, TURN, PROJECT, COMMAND,
            registrations() if registered is None else registered,
            cancelled_response() if response is None else response)

    def rejected(self, evidence=None, response=None, registered=None):
        with self.assertRaises(self.module.SessionError) as caught:
            self.validate(evidence, response, registered)
        if caught.exception.details is not None:
            self.assertEqual(set(caught.exception.details), {'failed_checks'})
            checks = caught.exception.details['failed_checks']
            self.assertIsInstance(checks, list)
            self.assertTrue(checks)
            self.assertTrue(all(check in self.module.REQUEST_CHECK_CODES for check in checks))
        self.assertNotIn(COMMAND, str(caught.exception))

    def test_schema_defaults_allow_omitted_kind_and_optional_decisions(self):
        for field in ('kind', 'availableDecisions'):
            value = events()
            del value[10]['params'][field]
            with self.subTest(omitted=field):
                self.assertTrue(self.validate(value)['passed'])
        value = events()
        value[10]['params']['availableDecisions'] = None
        self.assertTrue(self.validate(value)['passed'])

    def test_present_invalid_kind_and_decisions_stay_rejected(self):
        for field, replacement, code in (
                ('kind', None, 'permission_request_kind_mismatch'),
                ('kind', 'writeStdin', 'permission_request_kind_mismatch'),
                ('availableDecisions', {}, 'permission_request_decisions_invalid'),
                ('availableDecisions', [], 'permission_request_cancel_unavailable'),
                ('availableDecisions', ['accept'], 'permission_request_cancel_unavailable'),
                ('availableDecisions', ['accept', 'decline'], 'permission_request_cancel_unavailable')):
            value = events()
            value[10]['params'][field] = replacement
            with self.subTest(field=field, replacement=replacement), self.assertRaises(self.module.SessionError) as caught:
                self.validate(value)
            self.assertEqual(str(caught.exception), code)

    def test_timestamp_remains_required_and_typed(self):
        for missing, replacement in ((True, None), (False, None), (False, True), (False, '4')):
            value = events()
            if missing:
                del value[10]['params']['startedAtMs']
            else:
                value[10]['params']['startedAtMs'] = replacement
            with self.subTest(missing=missing, replacement=replacement), self.assertRaises(self.module.SessionError) as caught:
                self.validate(value)
            self.assertEqual(str(caught.exception), 'permission_request_timestamp_invalid')

    def test_all_failed_request_checks_are_fixed_names_without_values(self):
        value = events()
        value[10]['params'].update(command='private-command', cwd='/private-directory',
                                  additionalPermissions={'private-permission': 'do-not-copy'})
        with self.assertRaises(self.module.SessionError) as caught:
            self.validate(value)
        self.assertEqual(str(caught.exception), 'permission_request_cwd_mismatch')
        self.assertEqual(caught.exception.details, {'failed_checks': [
            'permission_request_cwd_mismatch', 'permission_request_command_mismatch',
            'permission_request_additional_permissions_not_allowed',
        ]})
        self.assertNotIn('private-', json.dumps(caught.exception.details))

    def test_scope_and_command_checks_keep_distinct_diagnostics(self):
        for field, replacement, code in (
                ('threadId', 'foreign', 'permission_request_thread_mismatch'),
                ('turnId', 'foreign', 'permission_request_turn_mismatch'),
                ('itemId', None, 'permission_request_item_invalid'),
                ('command', COMMAND + '; echo other', 'permission_request_command_mismatch'),
                ('networkApprovalContext', {}, 'permission_request_network_not_allowed')):
            value = events()
            value[10]['params'][field] = replacement
            with self.subTest(field=field), self.assertRaises(self.module.SessionError) as caught:
                self.validate(value)
            self.assertEqual(str(caught.exception), code)

    def test_native_four_hook_approval_cancel_and_resolution_pass(self):
        result = self.validate()
        self.assertTrue(result['passed'])
        self.assertTrue(result['permission_request_verified'])
        self.assertTrue(result['approval_cancelled'])
        self.assertTrue(result['turn_interrupted'])
        self.assertNotIn('approval_declined', result)
        self.assertNotIn('turn_completed', result)
        self.assertTrue(result['server_request_resolved'])
        self.assertEqual(result['hook_runs_completed'], 4)
        self.assertFalse(result['command_executed'])

    def test_precheck_requires_native_hook_chain_before_cancel(self):
        result = self.module.validate_approval_request(
            approval(), events()[:11], THREAD, TURN, PROJECT, COMMAND, registrations())
        self.assertEqual(result, {'passed': True})

    def test_precheck_rejects_missing_hook_or_mismatched_request(self):
        for position in (1, 2, 3, 4, 5, 6, 7, 8, 9, 10):
            value = events()[:11]
            del value[position]
            with self.subTest(position=position), self.assertRaises(self.module.SessionError):
                self.module.validate_approval_request(
                    approval(), value, THREAD, TURN, PROJECT, COMMAND, registrations())
        request = approval()
        request['id'] += 1
        with self.assertRaises(self.module.SessionError):
            self.module.validate_approval_request(
                request, events()[:11], THREAD, TURN, PROJECT, COMMAND, registrations())

    def test_missing_or_duplicate_native_steps_fail(self):
        for index in range(len(events())):
            value = events()
            del value[index]
            with self.subTest(missing=index):
                self.rejected(value)
            value = events()
            value.insert(index, copy.deepcopy(value[index]))
            with self.subTest(duplicate=index):
                self.rejected(value)

    def test_permission_hook_binds_item_not_retry_or_subcommand(self):
        for replacement in ('another-call', CALL + ':retry', 'approval-uuid'):
            value = events()
            for index in (7, 8):
                value[index]['params']['run']['id'] = (
                    f'permission-request:9:{PROJECT}/.codex/hooks.json:{replacement}')
            with self.subTest(replacement=replacement):
                self.rejected(value)

    def test_permission_registration_must_be_trusted_synchronous_exact_project_hook(self):
        for field, replacement in (('enabled', False), ('trustStatus', 'modified'),
                                   ('async', True), ('source', 'user'), ('handlerType', 'prompt'),
                                   ('displayOrder', True), ('currentHash', ''),
                                   ('sourcePath', '/foreign/hooks.json'), ('matcher', '*')):
            registered = registrations()
            registered['data'][0]['hooks'][3][field] = replacement
            with self.subTest(field=field):
                self.rejected(registered=registered)

    def test_failed_blocked_or_malformed_hook_cannot_pass(self):
        for field, replacement in (('status', 'failed'), ('status', 'blocked'),
                                   ('status', 'stopped'), ('executionMode', 'async'),
                                   ('scope', 'thread'), ('source', 'user'),
                                   ('completedAt', None), ('durationMs', -1),
                                   ('entries', [{'kind': 'error', 'text': 'private'}]),
                                   ('entries', [{'kind': 'warning'}])):
            value = events()
            value[8]['params']['run'][field] = replacement
            with self.subTest(field=field):
                self.rejected(value)

    def test_every_pretool_hook_binds_same_requested_call(self):
        value = events()
        value[5]['params']['run']['id'] += '-other'
        self.rejected(value)

    def test_foreign_scope_fails(self):
        for index in range(len(events())):
            value = events()
            value[index]['params']['threadId'] = 'another'
            with self.subTest(index=index):
                self.rejected(value)
        value = events()
        value[10]['params']['turnId'] = 'another'
        self.rejected(value)

    def test_request_rejects_amendments_additional_permissions_network_and_stdin(self):
        for field, replacement in (
                ('kind', 'writeStdin'), ('approvalId', 'subcommand'),
                ('additionalPermissions', {'fileSystem': {'read': ['/outside']}}),
                ('networkApprovalContext', {'host': 'example.test'}),
                ('proposedExecpolicyAmendment', ['/bin/cat']),
                ('proposedNetworkPolicyAmendments', [{'action': 'allow', 'host': 'example.test'}]),
                ('availableDecisions', ['accept']), ('startedAtMs', True)):
            value = events()
            value[10]['params'][field] = replacement
            with self.subTest(field=field):
                self.rejected(value)

    def test_request_command_cwd_or_item_mismatch_fails(self):
        for field, replacement in (('command', COMMAND + '; echo other'),
                                   ('cwd', '/another'), ('itemId', 'another')):
            value = events()
            value[10]['params'][field] = replacement
            with self.subTest(field=field):
                self.rejected(value)

    def test_exact_full_command_policy_suggestion_is_only_an_offer(self):
        for suggestion in ([], shlex.split(COMMAND)):
            value = events()
            value[10]['params']['proposedExecpolicyAmendment'] = suggestion
            with self.subTest(suggestion=suggestion):
                result = self.validate(value)
                self.assertTrue(result['passed'])
                self.assertIs(result['policy_amendment_applied'], False)

    def test_exact_validated_wrapper_policy_suggestion_is_only_an_offer(self):
        value = events()
        argv = ['/bin/zsh', '-lc', COMMAND]
        for index in (9, 12):
            value[index]['params']['item']['command'] = shlex.join(argv)
        value[10]['params']['command'] = shlex.join(argv)
        value[10]['params']['proposedExecpolicyAmendment'] = argv
        self.assertTrue(self.validate(value)['passed'])

    def test_only_exact_cancel_response_to_native_rpc_id_passes(self):
        for response in ({}, {'id': REQUEST_ID, 'result': {'decision': 'accept'}},
                         {'id': REQUEST_ID, 'result': {'decision': 'decline'}},
                         {'id': REQUEST_ID, 'result': {'decision': 'acceptForSession'}},
                         {'id': REQUEST_ID + 1, 'result': {'decision': 'cancel'}},
                         {'id': str(REQUEST_ID), 'result': {'decision': 'cancel'}},
                         {'id': REQUEST_ID, 'result': {'decision': 'cancel', 'extra': True}},
                         {'id': REQUEST_ID, 'error': {'message': 'cancel'}}):
            with self.subTest(response=response):
                self.rejected(response=response)

    def test_completed_failed_or_in_progress_turn_cannot_prove_cancel(self):
        for status in ('completed', 'failed', 'inProgress'):
            value = events()
            value[-1]['params']['turn']['status'] = status
            with self.subTest(status=status):
                self.rejected(value)

    def test_resolved_id_must_match_request_id_and_type(self):
        for identifier in (REQUEST_ID + 1, str(REQUEST_ID), True, None):
            value = events()
            value[11]['params']['requestId'] = identifier
            with self.subTest(identifier=identifier):
                self.rejected(value)

    def test_declined_item_cannot_have_process_output_exit_or_duration(self):
        for field, replacement in (('status', 'completed'), ('source', 'unifiedExecStartup'),
                                   ('processId', '123'), ('exitCode', 1), ('durationMs', 0),
                                   ('aggregatedOutput', ''), ('aggregatedOutput', 'private-output')):
            value = events()
            value[12]['params']['item'][field] = replacement
            with self.subTest(field=field):
                self.rejected(value)

    def test_extra_commands_tools_requests_or_output_fail(self):
        additions = [
            {'id': 99, 'method': 'item/permissions/requestApproval', 'params': {}},
            {'method': 'item/commandExecution/outputDelta', 'params': {
                'threadId': THREAD, 'turnId': TURN, 'itemId': CALL, 'delta': 'private'}},
            {'method': 'item/started', 'params': {'threadId': THREAD, 'turnId': TURN,
                                                'item': {'type': 'fileChange', 'id': 'other'}}},
            {'method': 'turn/plan/updated', 'params': {'threadId': THREAD, 'turnId': TURN}},
        ]
        for extra in additions:
            value = events()
            value.insert(12, extra)
            with self.subTest(extra=extra):
                self.rejected(value)

    def test_lifecycle_order_is_required(self):
        for first, second in ((5, 7), (8, 9), (9, 10), (10, 11), (11, 12), (12, 13)):
            value = events()
            value[first], value[second] = value[second], value[first]
            with self.subTest(first=first, second=second):
                self.rejected(value)

    def test_exact_native_shell_wrapper_is_accepted(self):
        value = events()
        wrapped = shlex.join(['/bin/zsh', '-lc', COMMAND])
        for index in (9, 12):
            value[index]['params']['item']['command'] = wrapped
        value[10]['params']['command'] = wrapped
        self.assertTrue(self.validate(value)['passed'])

    def test_wrapper_mismatch_between_request_start_and_decline_fails(self):
        value = events()
        value[10]['params']['command'] = shlex.join(['/bin/zsh', '-c', COMMAND])
        self.rejected(value)

    def test_narrative_and_hook_text_are_not_evidence_or_returned(self):
        value = events()
        value[8]['params']['run']['entries'] = [{'kind': 'warning', 'text': 'private-hook'}]
        value.insert(13, {'method': 'item/completed', 'params': {
            'threadId': THREAD, 'turnId': TURN,
            'item': {'type': 'agentMessage', 'id': 'message', 'text': 'private-agent'}}})
        result = self.validate(value)
        for forbidden in ('private-', COMMAND, 'thread-1', 'call-1', '.codex'):
            self.assertNotIn(forbidden, json.dumps(result))

    def test_turn_summary_cannot_contradict_decline_or_hide_tool(self):
        for item in ({'type': 'fileChange', 'id': 'hidden'},
                     {**command_item('item/completed')['params']['item'], 'status': 'completed'}):
            value = events()
            value[-1]['params']['turn']['items'] = [item]
            with self.subTest(item=item):
                self.rejected(value)

    def test_interrupted_turn_with_error_cannot_pass_cancel_flow(self):
        for error in ({}, {'message': 'private failure'}):
            value = events()
            value[-1]['params']['turn']['error'] = error
            with self.subTest(error=error):
                self.rejected(value)

    def test_malformed_events_fail_with_fixed_diagnostics(self):
        for evidence in (None, {}, [], [None], [{'method': 'hook/started', 'params': []}]):
            with self.subTest(evidence=evidence), self.assertRaises(self.module.SessionError):
                self.module.validate_permission_evidence(
                    evidence, THREAD, TURN, PROJECT, COMMAND, registrations(), cancelled_response())

    def test_malformed_request_and_item_shapes_fail_with_fixed_diagnostics(self):
        for field, replacement in (('itemId', []), ('command', None), ('kind', []),
                                   ('availableDecisions', {}), ('startedAtMs', None)):
            value = events()
            value[10]['params'][field] = replacement
            with self.subTest(field=field):
                self.rejected(value)
        for identifier in (None, True, [], {}):
            value = events()
            value[10]['id'] = identifier
            with self.subTest(identifier=identifier):
                self.rejected(value)
        value = events()
        value[12]['params']['item']['type'] = []
        self.rejected(value)

    def test_missing_nullable_process_field_cannot_hide_execution_state(self):
        for index in (9, 12):
            for field in ('processId', 'aggregatedOutput', 'exitCode', 'durationMs'):
                value = events()
                del value[index]['params']['item'][field]
                with self.subTest(index=index, field=field):
                    self.rejected(value)


if __name__ == '__main__':
    unittest.main()
