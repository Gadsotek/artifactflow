#!/usr/bin/env python3
"""Synthetic driver tests; no client process, model turn, or fixture is created."""
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


FIXTURE = PROJECT / 'storage/framework/testing/synthetic-hook-fixture'
SOURCE = FIXTURE / 'source.txt'
SENTINEL = b'artifactflow-owned-hook-canary-synthetic\n'
CONTROLS = {'synthetic-control': 'a' * 64}


class CodexModelSessionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.module = importlib.import_module('codex_model_session')

    @contextmanager
    def driver(self, *, evidence=None):
        rpc = MagicMock()
        rpc.warning_count = 0
        rpc.request.side_effect = [{}, config_response(), hooks_response(), thread_response(),
                                   {'turn': {'id': 'synthetic-turn'}}]
        rpc.collect_turn.return_value = [{'method': 'synthetic-event'}]
        with ExitStack() as stack:
            stack.enter_context(patch.object(Path, 'resolve', autospec=True, side_effect=lambda path, *args, **kwargs: path))
            stack.enter_context(patch.object(Path, 'is_symlink', return_value=False))
            stack.enter_context(patch.object(Path, 'is_file', return_value=True))
            read = stack.enter_context(patch.object(self.module, 'read_owned_bytes', return_value=SENTINEL))
            controls = stack.enter_context(patch.object(self.module, 'snapshot_controls', return_value=copy.deepcopy(CONTROLS)))
            stack.enter_context(patch.object(self.module, 'read_project', return_value=(EXPECTED, HOOKS, 'c' * 64, 'd' * 64)))
            stack.enter_context(patch.object(self.module, 'resolve_codex', return_value='/synthetic/codex'))
            launch = stack.enter_context(patch.object(self.module, 'EventAppServer', return_value=rpc))
            validate = stack.enter_context(patch('codex_hook_evidence.validate_model_evidence',
                                                  return_value={'passed': True} if evidence is None else evidence))
            yield rpc, controls, read, validate, launch

    def test_rejected_validator_evidence_never_becomes_driver_pass(self):
        with self.driver(evidence={'passed': False}):
            result = self.module.run_session(PROJECT, FIXTURE)
        self.assertIs(result['passed'], False)

    def test_invalid_or_truthy_validator_values_never_pass(self):
        for evidence in (None, [], True, {}, {'passed': 1}):
            with self.subTest(evidence=evidence), self.driver() as (_, _, _, validate, _):
                validate.return_value = evidence
                result = self.module.run_session(PROJECT, FIXTURE)
            self.assertIs(result['passed'], False)

    def test_normal_flow_preserves_default_policy_model_and_exact_binding(self):
        with self.driver() as (rpc, _, _, validate, launch):
            result = self.module.run_session(PROJECT, FIXTURE, timeout=45)
        self.assertTrue(result['passed'], result)
        self.assertTrue(result['controls_unchanged'])
        self.assertTrue(result['source_unchanged'])
        self.assertTrue(result['model_turn_submitted'])
        launch.assert_called_once_with('/synthetic/codex', PROJECT, 45)
        self.assertEqual(['initialize', 'config/read', 'hooks/list', 'thread/start', 'turn/start'],
                         [call.args[0] for call in rpc.request.call_args_list])
        self.assertEqual({'ephemeral': True}, rpc.request.call_args_list[3].args[1])
        method, turn = rpc.request.call_args_list[4].args
        self.assertEqual('turn/start', method)
        self.assertEqual({'threadId', 'input'}, set(turn))
        self.assertEqual('synthetic-thread', turn['threadId'])
        self.assertEqual(1, len(turn['input']))
        self.assertEqual({'type', 'text', 'text_elements'}, set(turn['input'][0]))
        self.assertEqual('text', turn['input'][0]['type'])
        self.assertEqual([], turn['input'][0]['text_elements'])
        command = '/bin/cat storage/framework/testing/synthetic-hook-fixture/source.txt'
        self.assertIn(command, turn['input'][0]['text'])
        validate.assert_called_once_with(rpc.collect_turn.return_value, 'synthetic-thread', 'synthetic-turn',
                                         PROJECT, command, SENTINEL.decode('ascii'), hooks_response())
        rpc.collect_turn.assert_called_once_with('synthetic-thread', 'synthetic-turn')
        rpc.notify.assert_called_once_with('initialized')
        rpc.close.assert_called_once()

    def test_configuration_drift_stops_before_model_turn(self):
        with self.driver() as (rpc, _, _, validate, _):
            changed = config_response()
            changed['config']['approval_policy'] = 'never'
            rpc.request.side_effect = [{}, changed]
            result = self.module.run_session(PROJECT, FIXTURE)
        self.assertFalse(result['passed'])
        self.assertFalse(result['model_turn_submitted'])
        self.assertEqual(['initialize', 'config/read'], [call.args[0] for call in rpc.request.call_args_list])
        validate.assert_not_called()
        rpc.close.assert_called_once()

    def test_controls_changed_before_turn_prevent_model_submission(self):
        with self.driver() as (rpc, controls, _, validate, _):
            controls.side_effect = [CONTROLS, {'synthetic-control': 'b' * 64}, {'synthetic-control': 'b' * 64}]
            result = self.module.run_session(PROJECT, FIXTURE)
        self.assertFalse(result['passed'])
        self.assertFalse(result['model_turn_submitted'])
        self.assertFalse(result['controls_unchanged'])
        self.assertNotIn('turn/start', [call.args[0] for call in rpc.request.call_args_list])
        validate.assert_not_called()

    def test_controls_changed_during_turn_override_valid_evidence(self):
        with self.driver() as (_, controls, _, _, _):
            controls.side_effect = [CONTROLS, CONTROLS, {'synthetic-control': 'b' * 64}]
            result = self.module.run_session(PROJECT, FIXTURE)
        self.assertFalse(result['passed'])
        self.assertFalse(result['controls_unchanged'])
        self.assertEqual('verification_inputs_changed', result['category'])

    def test_canary_changed_during_turn_overrides_valid_evidence_without_content_leak(self):
        with self.driver() as (_, _, read, _, _):
            read.side_effect = [SENTINEL, b'synthetic private replacement']
            result = self.module.run_session(PROJECT, FIXTURE)
        self.assertFalse(result['passed'])
        self.assertFalse(result['source_unchanged'])
        self.assertEqual('verification_inputs_changed', result['category'])
        self.assertNotIn('synthetic private replacement', json.dumps(result))

    def test_unavailable_final_integrity_check_cannot_pass(self):
        for target in ('controls', 'source'):
            with self.subTest(target=target), self.driver() as (_, controls, read, _, _):
                if target == 'controls':
                    controls.side_effect = [CONTROLS, CONTROLS, OSError('synthetic private error')]
                else:
                    read.side_effect = [SENTINEL, OSError('synthetic private error')]
                result = self.module.run_session(PROJECT, FIXTURE)
            self.assertFalse(result['passed'])
            self.assertEqual('verification_inputs_unavailable', result['category'])
            self.assertNotIn('synthetic private error', json.dumps(result))

    def test_cleanup_failure_overrides_valid_evidence(self):
        with self.driver() as (rpc, _, _, _, _):
            rpc.close.side_effect = OSError('synthetic private cleanup error')
            result = self.module.run_session(PROJECT, FIXTURE)
        self.assertFalse(result['passed'])
        self.assertEqual('cleanup_failed', result['cleanup_error'])
        self.assertTrue(result['controls_unchanged'])
        self.assertTrue(result['source_unchanged'])
        self.assertNotIn('synthetic private cleanup error', json.dumps(result))

    def test_timeout_and_failed_turn_evidence_never_pass_and_always_cleanup(self):
        for error in (TimeoutError(), self.module.SessionError('model_turn_invalid')):
            with self.subTest(error=type(error).__name__), self.driver() as (rpc, _, _, validate, _):
                if isinstance(error, TimeoutError):
                    rpc.collect_turn.side_effect = error
                else:
                    validate.side_effect = error
                result = self.module.run_session(PROJECT, FIXTURE)
            self.assertFalse(result['passed'])
            self.assertIn(result['category'], ('timeout', 'model_turn_invalid'))
            rpc.close.assert_called_once()
            self.assertTrue(result['controls_unchanged'])
            self.assertTrue(result['source_unchanged'])

    def test_malformed_turn_response_never_collects_or_validates_evidence(self):
        for turn in (None, [], {}, {'turn': []}, {'turn': {'id': ''}}, {'turn': {'id': 1}}):
            with self.subTest(turn=turn), self.driver() as (rpc, _, _, validate, _):
                rpc.request.side_effect = [{}, config_response(), hooks_response(), thread_response(), turn]
                result = self.module.run_session(PROJECT, FIXTURE)
            self.assertFalse(result['passed'])
            rpc.collect_turn.assert_not_called()
            validate.assert_not_called()
            rpc.close.assert_called_once()

    def test_invalid_fixture_or_timeout_never_launches_client(self):
        for fixture, timeout in ((Path('/synthetic/elsewhere'), 120), (FIXTURE, 0), (FIXTURE, 301)):
            with self.subTest(fixture=fixture, timeout=timeout), self.driver() as (_, _, _, _, launch):
                result = self.module.run_session(PROJECT, fixture, timeout=timeout)
            self.assertFalse(result['passed'])
            launch.assert_not_called()

    def test_nonascii_or_unrecognized_canary_never_launches_client(self):
        for content in (b'wrong-sentinel\n', b'\xff\n', b'artifactflow-owned-hook-canary-no-newline'):
            with self.subTest(content=content), self.driver() as (_, _, read, _, launch):
                read.return_value = content
                result = self.module.run_session(PROJECT, FIXTURE)
            self.assertFalse(result['passed'])
            launch.assert_not_called()

    def transport(self):
        rpc = self.module.EventAppServer.__new__(self.module.EventAppServer)
        rpc.sequence = 0
        rpc.warning_count = 0
        rpc.events = []
        rpc._send = MagicMock()
        rpc._next = MagicMock()
        return rpc

    def test_native_events_before_turn_response_are_preserved(self):
        rpc = self.transport()
        started = {'method': 'hook/started', 'params': {'threadId': 'thread', 'turnId': 'turn'}}
        completed = {'method': 'turn/completed', 'params': {'threadId': 'thread', 'turn': {'id': 'turn'}}}
        rpc._next.side_effect = [started, completed, {'id': 1, 'result': {'turn': {'id': 'turn'}}}]
        self.assertEqual({'turn': {'id': 'turn'}}, rpc.request('turn/start', {'threadId': 'thread'}))
        self.assertEqual([started, completed], rpc.collect_turn('thread', 'turn'))
        self.assertEqual(3, rpc._next.call_count)

    def test_collect_turn_waits_for_exact_thread_and_turn_binding(self):
        rpc = self.transport()
        foreign_thread = {'method': 'turn/completed', 'params': {'threadId': 'foreign', 'turn': {'id': 'turn'}}}
        foreign_turn = {'method': 'turn/completed', 'params': {'threadId': 'thread', 'turn': {'id': 'foreign'}}}
        expected = {'method': 'turn/completed', 'params': {'threadId': 'thread', 'turn': {'id': 'turn'}}}
        rpc._next.side_effect = [foreign_thread, foreign_turn, expected]
        self.assertEqual([foreign_thread, foreign_turn, expected], rpc.collect_turn('thread', 'turn'))
        self.assertEqual(3, rpc._next.call_count)

    def test_other_tool_bearing_and_unknown_turn_events_reach_validator(self):
        for method in ('turn/plan/updated', 'turn/diff/updated',
                       'item/commandExecution/terminalInteraction', 'unknown/turn-scoped-event'):
            rpc = self.transport()
            event = {'method': method, 'params': {'threadId': 'thread', 'turnId': 'turn'}}
            with self.subTest(method=method):
                rpc.record(event)
                self.assertEqual([event], rpc.events)

    def test_unexpected_client_requests_are_rejected_without_approval_response(self):
        for method in ('item/commandExecution/requestApproval', 'item/fileChange/requestApproval', 'item/tool/requestUserInput'):
            rpc = self.transport()
            rpc._next.return_value = {'id': 99, 'method': method, 'params': {'private': 'never-echo-this'}}
            with self.subTest(method=method), self.assertRaises(self.module.SessionError) as error:
                rpc.request('turn/start', {'threadId': 'thread'})
            self.assertEqual('unexpected_client_request', str(error.exception))
            self.assertNotIn('never-echo-this', str(error.exception))
            self.assertEqual(1, rpc._send.call_count)
            self.assertEqual('turn/start', rpc._send.call_args.args[0]['method'])

    def test_cli_without_run_creates_no_client_or_fixture(self):
        with patch.object(sys, 'argv', ['codex_model_session.py']), patch.object(sys, 'stdout', io.StringIO()), \
                patch.object(Path, 'mkdir') as mkdir, patch.object(self.module.tempfile, 'mkdtemp') as create, \
                patch.object(self.module, 'run_session') as run:
            self.assertEqual(2, self.module.main())
        mkdir.assert_not_called()
        create.assert_not_called()
        run.assert_not_called()

    def test_cli_invalid_timeout_creates_no_client_or_fixture(self):
        with patch.object(sys, 'argv', ['codex_model_session.py', '--run', '--timeout', '301']), \
                patch.object(sys, 'stdout', io.StringIO()), patch.object(Path, 'mkdir') as mkdir, \
                patch.object(self.module.tempfile, 'mkdtemp') as create, patch.object(self.module, 'run_session') as run:
            self.assertEqual(2, self.module.main())
        mkdir.assert_not_called()
        create.assert_not_called()
        run.assert_not_called()


if __name__ == '__main__':
    unittest.main(verbosity=2)
