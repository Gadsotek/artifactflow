#!/usr/bin/env python3
"""Synthetic protocol regressions; these tests never start a model or client."""
from __future__ import annotations

import copy
import importlib
import json
import shlex
import unittest
from pathlib import Path


PROJECT = Path('/synthetic/artifactflow')
SOURCE = str(PROJECT / '.codex/hooks.json')
COMMAND = 'cat storage/framework/testing/owned/source.txt'
SENTINEL = 'owned-unpredictable-content\n'
THREAD = 'thread-1'
TURN = 'turn-1'
CALL = 'call-1'


def registrations():
    hooks = []
    for event, order, matcher in (('userPromptSubmit', 3, None),
                                  ('preToolUse', 7, '^Bash$'),
                                  ('preToolUse', 8, '*'),
                                  ('permissionRequest', 9, '^Bash$')):
        hooks.append({'sourcePath': SOURCE, 'source': 'project', 'eventName': event,
                      'displayOrder': order, 'handlerType': 'command', 'async': False,
                      'matcher': matcher, 'command': 'already-verified-hook',
                      'enabled': True, 'trustStatus': 'trusted', 'currentHash': 'a' * 64})
    return {'data': [{'cwd': str(PROJECT), 'errors': [], 'warnings': [], 'hooks': hooks}]}


def hook_event(method, event, order):
    label = {'userPromptSubmit': 'user-prompt-submit', 'preToolUse': 'pre-tool-use'}[event]
    identifier = f'{label}:{order}:{SOURCE}' + (f':{CALL}' if event == 'preToolUse' else '')
    completed = method == 'hook/completed'
    return {'method': method, 'params': {'threadId': THREAD, 'turnId': TURN, 'run': {
        'id': identifier, 'eventName': event, 'displayOrder': order, 'sourcePath': SOURCE,
        'source': 'project', 'handlerType': 'command', 'executionMode': 'sync',
        'scope': 'turn', 'status': 'completed' if completed else 'running',
        'startedAt': 1, 'completedAt': 2 if completed else None,
        'durationMs': 20 if completed else None, 'entries': [],
    }}}


def command_event(method, source='unifiedExecStartup'):
    completed = method == 'item/completed'
    return {'method': method, 'params': {'threadId': THREAD, 'turnId': TURN, 'item': {
        'type': 'commandExecution', 'id': CALL, 'command': COMMAND, 'cwd': str(PROJECT),
        'source': source, 'status': 'completed' if completed else 'inProgress',
        'exitCode': 0 if completed else None,
        'aggregatedOutput': SENTINEL if completed else None,
        'commandActions': [], 'processId': None,
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
        command_event('item/started'),
        command_event('item/completed'),
        {'method': 'turn/completed', 'params': {'threadId': THREAD,
                                              'turn': {'id': TURN, 'status': 'completed',
                                                       'error': None, 'items': []}}},
    ]


class CodexHookEvidenceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.module = importlib.import_module('codex_hook_evidence')

    def validate(self, evidence=None, registered=None):
        return self.module.validate_model_evidence(
            events() if evidence is None else evidence, THREAD, TURN, PROJECT,
            COMMAND, SENTINEL, registrations() if registered is None else registered)

    def rejected(self, evidence=None, registered=None):
        with self.assertRaises(self.module.SessionError) as caught:
            self.validate(evidence, registered)
        self.assertNotIn(SENTINEL.strip(), str(caught.exception))
        self.assertIsNone(caught.exception.details)

    def test_registered_hooks_bind_to_one_real_model_command(self):
        result = self.validate()
        self.assertIs(result['passed'], True)
        self.assertIs(result['execution_verified'], True)
        self.assertEqual(result['hook_runs_completed'], 3)
        self.assertEqual(result['command_count'], 1)

    def test_shell_and_unified_startup_are_model_sources(self):
        for source in ('agent', 'unifiedExecStartup'):
            value = events()
            for index in (7, 8):
                value[index]['params']['item']['source'] = source
            with self.subTest(source=source):
                self.assertTrue(self.validate(value)['passed'])

    def test_native_exact_shell_wrapper_preserves_reviewed_command(self):
        for shell in ('/bin/zsh', '/bin/bash', '/bin/sh'):
            for option in ('-c', '-lc'):
                value = events()
                for index in (7, 8):
                    value[index]['params']['item']['command'] = shlex.join([shell, option, COMMAND])
                with self.subTest(shell=shell, option=option):
                    self.assertTrue(self.validate(value)['passed'])

    def test_shell_wrapper_extra_arguments_and_changed_script_fail(self):
        for argv in (['/bin/zsh', '-c', COMMAND, 'extra'],
                     ['/bin/zsh', '-lic', COMMAND], ['/untrusted/zsh', '-c', COMMAND],
                     ['/usr/bin/env', '/bin/zsh', '-c', COMMAND],
                     ['/bin/zsh', '-c', COMMAND + '; echo extra'],
                     ['/bin/zsh', '-c', 'exec ' + COMMAND]):
            value = events()
            for index in (7, 8):
                value[index]['params']['item']['command'] = shlex.join(argv)
            with self.subTest(argv=argv):
                self.rejected(value)

    def test_wrapper_must_be_identical_at_command_start_and_completion(self):
        value = events()
        value[7]['params']['item']['command'] = shlex.join(['/bin/zsh', '-c', COMMAND])
        self.rejected(value)

    def test_missing_or_duplicate_native_events_fail(self):
        for index in range(len(events())):
            value = events()
            del value[index]
            with self.subTest(missing=index):
                self.rejected(value)
            value = events()
            value.insert(index, copy.deepcopy(value[index]))
            with self.subTest(duplicate=index):
                self.rejected(value)

    def test_hook_failed_blocked_stopped_async_or_wrong_scope_fail(self):
        for field, replacement in (('status', 'failed'), ('status', 'blocked'),
                                   ('status', 'stopped'), ('executionMode', 'async'),
                                   ('scope', 'thread'), ('handlerType', 'prompt'),
                                   ('source', 'user'), ('displayOrder', True),
                                   ('completedAt', None), ('durationMs', -1)):
            value = events()
            value[5]['params']['run'][field] = replacement
            with self.subTest(field=field, replacement=replacement):
                self.rejected(value)

    def test_registration_is_not_runtime_execution(self):
        self.rejected([value for value in events() if not value['method'].startswith('hook/')])

    def test_tool_call_suffix_binds_both_pretool_hooks(self):
        for index in (3, 4, 5, 6):
            value = events()
            value[index]['params']['run']['id'] += '-other-call'
            with self.subTest(index=index):
                self.rejected(value)

    def test_runtime_source_and_order_must_match_registered_handler(self):
        for field, replacement in (('sourcePath', '/another/hooks.json'),
                                   ('displayOrder', 1), ('eventName', 'permissionRequest')):
            value = events()
            value[5]['params']['run'][field] = replacement
            with self.subTest(field=field):
                self.rejected(value)

    def test_events_from_another_thread_or_turn_fail(self):
        for index in range(len(events())):
            for key in ('threadId', 'turnId'):
                value = events()
                params = value[index]['params']
                if key == 'turnId' and 'turn' in params:
                    params['turn']['id'] = 'another'
                else:
                    params[key] = 'another'
                with self.subTest(index=index, key=key):
                    self.rejected(value)

    def test_hook_completion_must_precede_command_start(self):
        value = events()
        value[5], value[7] = value[7], value[5]
        self.rejected(value)

    def test_hook_completion_before_its_start_fails(self):
        value = events()
        value[3], value[5] = value[5], value[3]
        self.rejected(value)

    def test_command_cwd_output_and_status_are_exact(self):
        replacements = (('command', COMMAND + '; echo extra'), ('cwd', '/another'),
                        ('aggregatedOutput', 'prefix' + SENTINEL), ('exitCode', 1),
                        ('exitCode', False), ('status', 'failed'), ('source', 'userShell'),
                        ('source', 'unifiedExecInteraction'), ('pluginId', 'plugin'))
        for field, replacement in replacements:
            value = events()
            value[8]['params']['item'][field] = replacement
            with self.subTest(field=field, replacement=replacement):
                self.rejected(value)

    def test_start_and_completion_must_describe_same_command(self):
        for field, replacement in (('id', 'another-call'), ('command', 'cat another'),
                                   ('source', 'agent')):
            value = events()
            value[7]['params']['item'][field] = replacement
            with self.subTest(field=field):
                self.rejected(value)

    def test_extra_command_or_tool_items_fail(self):
        for item_type in ('commandExecution', 'fileChange', 'mcpToolCall', 'dynamicToolCall',
                          'collabAgentToolCall', 'webSearch', 'unknownFutureTool'):
            value = events()
            extra = command_event('item/started')
            extra['params']['item'].update(type=item_type, id='another-call')
            value.insert(9, extra)
            with self.subTest(item_type=item_type):
                self.rejected(value)

    def test_agent_text_cannot_forge_native_evidence(self):
        value = events()
        value[8]['params']['item']['aggregatedOutput'] = 'different\n'
        value.insert(9, {'method': 'item/completed', 'params': {
            'threadId': THREAD, 'turnId': TURN,
            'item': {'id': 'text', 'type': 'agentMessage', 'text': SENTINEL}}})
        self.rejected(value)

    def test_model_text_and_hook_output_are_not_returned(self):
        value = events()
        value[5]['params']['run']['entries'] = [{'kind': 'context', 'text': 'private-hook-text'}]
        value.insert(9, {'method': 'item/completed', 'params': {
            'threadId': THREAD, 'turnId': TURN,
            'item': {'id': 'text', 'type': 'agentMessage', 'text': 'private-model-text'}}})
        result = json.dumps(self.validate(value))
        for forbidden in ('private-', SENTINEL.strip(), SOURCE, COMMAND, CALL):
            self.assertNotIn(forbidden, result)

    def test_completed_hook_rejects_error_stop_feedback_or_malformed_entries(self):
        entries = [
            [{'kind': kind, 'text': 'must-not-be-returned'}]
            for kind in ('error', 'stop', 'feedback', 'unknown')
        ] + [[None], ['malformed'], [{}], [{'kind': 'warning'}],
             [{'text': 'missing-kind'}], [{'kind': [], 'text': 'malformed'}],
             [{'kind': 'context', 'text': None}],
             [{'kind': 'warning', 'text': 'valid', 'extra': 'unexpected'}]]
        for index in (2, 5, 6):
            for replacement in entries:
                value = events()
                value[index]['params']['run']['entries'] = replacement
                with self.subTest(index=index, entries=replacement):
                    self.rejected(value)

    def test_completed_hook_allows_legitimate_warning_and_context_without_exposing_text(self):
        value = events()
        for index in (2, 5, 6):
            value[index]['params']['run']['entries'] = [
                {'kind': 'warning', 'text': 'private-warning-text'},
                {'kind': 'context', 'text': 'private-context-text'},
            ]
        result = self.validate(value)
        self.assertTrue(result['passed'])
        self.assertNotIn('private-', json.dumps(result))

    def test_approval_or_dynamic_server_request_fails(self):
        value = events()
        value.insert(7, {'id': 100, 'method': 'item/commandExecution/requestApproval',
                         'params': {'threadId': THREAD, 'turnId': TURN}})
        self.rejected(value)

    def test_incomplete_failed_or_interrupted_turn_fails(self):
        for status in ('failed', 'interrupted', 'inProgress'):
            value = events()
            value[-1]['params']['turn']['status'] = status
            with self.subTest(status=status):
                self.rejected(value)

    def test_turn_summary_cannot_hide_an_extra_tool(self):
        value = events()
        value[-1]['params']['turn']['items'] = [{'type': 'fileChange', 'id': 'hidden'}]
        self.rejected(value)

    def test_missing_untrusted_duplicate_or_async_registration_fails(self):
        for field, replacement in (('enabled', False), ('trustStatus', 'modified'),
                                   ('async', True), ('displayOrder', None),
                                   ('matcher', '^Other$'), ('currentHash', '')):
            registered = registrations()
            registered['data'][0]['hooks'][1][field] = replacement
            with self.subTest(field=field):
                self.rejected(registered=registered)
        registered = registrations()
        registered['data'][0]['hooks'].append(copy.deepcopy(registered['data'][0]['hooks'][1]))
        self.rejected(registered=registered)

    def test_malformed_payloads_fail_with_fixed_diagnostic(self):
        for value in (None, {}, [None], [{'method': 'hook/started', 'params': None}],
                      [{'method': 'unknown/newTool', 'params': {}}]):
            with self.subTest(value=value):
                with self.assertRaises(self.module.SessionError):
                    self.module.validate_model_evidence(value, THREAD, TURN, PROJECT,
                                                        COMMAND, SENTINEL, registrations())

    def test_malformed_item_types_and_sources_are_fixed_failures(self):
        for field, replacement in (('type', []), ('type', {}), ('source', [])):
            value = events()
            value[8]['params']['item'][field] = replacement
            with self.subTest(field=field, replacement=replacement):
                self.rejected(value)


if __name__ == '__main__':
    unittest.main()
