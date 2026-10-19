#!/usr/bin/env python3
"""Opt-in real model tool call with native project-hook execution evidence.

Uses normal Codex authentication and project policy. No policy, trust, model,
environment or approval overrides; never approves a client request. Native raw
events are inspected in memory only, not written to the sanitized receipt.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
from owned_receipt import OwnedReceipt
from owned_inputs import read_owned_bytes
import shlex
import subprocess
import tempfile
import uuid

from codex_session import (
    AppServer, SessionError, hook_evidence, project_evidence, read_project,
    resolve_codex, rpc_error_details, thread_evidence,
)
from run_development import snapshot_controls


EVENT_METHODS = frozenset({
    'hook/started', 'hook/completed', 'item/started', 'item/completed',
    'turn/started', 'turn/completed',
})


class EventAppServer(AppServer):
    """Preserve native events arriving before an RPC response as well as after."""

    def __init__(self, binary: str, project: Path, timeout: float):
        super().__init__(binary, project, timeout)
        self.events: list[dict] = []

    def record(self, message: dict) -> None:
        if 'id' in message:
            # No automatic permission, auth, dynamic tool or user-input replies.
            raise SessionError('unexpected_client_request')
        if message.get('method') == 'configWarning':
            self.warning_count += 1
        method = message.get('method')
        params = message.get('params')
        if (method in EVENT_METHODS
                or (isinstance(method, str) and method.startswith(('hook/', 'item/', 'turn/')))
                or (isinstance(params, dict) and params.get('turnId') is not None)):
            if len(self.events) >= 1024:
                raise SessionError('event_limit')
            self.events.append(message)

    def request(self, method: str, params: dict) -> dict:
        self.sequence += 1
        identifier = self.sequence
        self._send({'id': identifier, 'method': method, 'params': params})
        while True:
            message = self._next()
            if 'method' in message:
                self.record(message)
                continue
            if message.get('id') != identifier:
                raise SessionError('invalid_protocol')
            if 'error' in message:
                raise SessionError('client_rpc_error', rpc_error_details(message['error']))
            if not isinstance(message.get('result'), dict):
                raise SessionError('invalid_protocol')
            return message['result']

    def collect_turn(self, thread_id: str, turn_id: str) -> list[dict]:
        def complete(message: dict) -> bool:
            params = message.get('params', {})
            return (message.get('method') == 'turn/completed'
                    and params.get('threadId') == thread_id
                    and params.get('turn', {}).get('id') == turn_id)

        while not any(complete(event) for event in self.events):
            message = self._next()
            if 'method' not in message:
                raise SessionError('invalid_protocol')
            self.record(message)
        return self.events


def probe_prompt(command: str, project: Path) -> str:
    return (
        'The repository owner authorizes this bounded, read-only native hook conformance check. '
        'Use your normal shell tool exactly once to execute the following exact command '
        f'with working directory {project}:\n{command}\n'
        'The target is a newly created nonsecret owned fixture. Do not read any other files, '
        'call other tools, change files or settings, request approval, use an unsandboxed '
        'retry, or simulate command output. This verification needs no feature work. '
        'After the one command finishes, reply briefly and stop.'
    )


def run_session(project: Path, fixture: Path, timeout: float = 120) -> dict:
    from codex_hook_evidence import validate_model_evidence

    result = {'passed': False, 'scope': 'fresh_project_model_tool_and_native_hooks',
              'model_turn_submitted': False, 'stage': 'arguments'}
    rpc = None
    before = None
    source = None
    original = None
    try:
        if not 0 < timeout <= 300:
            raise SessionError('invalid_arguments')
        project, fixture = project.resolve(), fixture.resolve()
        if not fixture.is_relative_to(project / 'storage/framework/testing'):
            raise SessionError('invalid_fixture')
        source = fixture / 'source.txt'
        if source.is_symlink() or not source.is_file():
            raise SessionError('invalid_fixture')
        original = read_owned_bytes(source, 128)
        sentinel = original.decode('ascii')
        if (not sentinel.startswith('artifactflow-owned-hook-canary-')
                or len(original) > 128 or not sentinel.endswith('\n')):
            raise SessionError('invalid_fixture')
        before = snapshot_controls(project)
        result['control_sha256_before'] = before
        expected, hooks, digest, hook_digest = read_project(project)
        result.update({'project': str(project), 'fixture': str(fixture),
                       'config_sha256': digest, 'hooks_sha256': hook_digest,
                       'source_sha256': hashlib.sha256(original).hexdigest()})
        binary = resolve_codex(None)
        if binary is None:
            raise SessionError('client_unavailable')
        result['executable'] = binary
        result['stage'] = 'client_initialization'
        rpc = EventAppServer(binary, project, timeout)
        rpc.request('initialize', {'clientInfo': {'name': 'artifactflow_model_hooks', 'version': '1'},
                                   'capabilities': {'experimentalApi': True}})
        rpc.notify('initialized')
        result['stage'] = 'project_configuration'
        result['configuration'] = project_evidence(
            rpc.request('config/read', {'cwd': str(project), 'includeLayers': False}), expected, project)
        result['stage'] = 'hook_registration'
        registrations = rpc.request('hooks/list', {'cwds': [str(project)]})
        result['hooks'] = hook_evidence(registrations, hooks, project)
        result['stage'] = 'thread_activation'
        thread = rpc.request('thread/start', {'ephemeral': True})
        result['session'] = thread_evidence(thread, expected, project)
        thread_id = thread.get('thread', {}).get('id')
        if not isinstance(thread_id, str) or not thread_id:
            raise SessionError('invalid_thread_id')
        if snapshot_controls(project) != before:
            raise SessionError('controls_changed_before_turn')
        command = shlex.join(['/bin/cat', str(source.relative_to(project))])
        result['stage'] = 'model_turn'
        result['model_turn_submitted'] = True
        turn = rpc.request('turn/start', {'threadId': thread_id, 'input': [
            {'type': 'text', 'text': probe_prompt(command, project), 'text_elements': []},
        ]})
        turn_id = turn.get('turn', {}).get('id')
        if not isinstance(turn_id, str) or not turn_id:
            raise SessionError('invalid_turn_id')
        events = rpc.collect_turn(thread_id, turn_id)
        result['stage'] = 'native_evidence'
        result['evidence'] = validate_model_evidence(
            events, thread_id, turn_id, project, command, sentinel, registrations)
        if not isinstance(result['evidence'], dict) or result['evidence'].get('passed') is not True:
            raise SessionError('native_evidence_rejected')
        result['config_warning_count'] = rpc.warning_count
        if rpc.warning_count or result['hooks']['warning_count']:
            raise SessionError('configuration_warning')
        result['passed'] = True
        result['stage'] = 'complete'
    except SessionError as error:
        result['category'] = str(error)
        if error.details is not None:
            result['details'] = error.details
    except TimeoutError:
        result['category'] = 'timeout'
    except (OSError, ValueError, KeyError, TypeError, AttributeError, ImportError):
        result['category'] = 'launch_or_protocol_failure'
    finally:
        if rpc is not None:
            try:
                rpc.close()
            except (OSError, subprocess.TimeoutExpired):
                result['passed'] = False
                result['cleanup_error'] = 'cleanup_failed'
        if before is not None:
            try:
                result['controls_unchanged'] = snapshot_controls(project) == before
                result['source_unchanged'] = (source is not None
                                              and read_owned_bytes(source, 128) == original)
                if not result['controls_unchanged'] or not result['source_unchanged']:
                    result['passed'] = False
                    result['category'] = 'verification_inputs_changed'
            except OSError:
                result['passed'] = False
                result['category'] = 'verification_inputs_unavailable'
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--run', action='store_true', help='Submit one real model turn using normal client authentication')
    parser.add_argument('--project-root', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--timeout', type=float, default=120)
    args = parser.parse_args()
    if not args.run:
        print('INCOMPLETE: add --run to submit the bounded model turn; no clients or fixtures started.')
        return 2
    if not 0 < args.timeout <= 300:
        print('UNAVAILABLE: timeout must be greater than zero and at most 300 seconds.')
        return 2
    project = args.project_root.resolve()
    parent = project / 'storage/framework/testing'
    try:
        parent.mkdir(parents=True, exist_ok=True)
        fixture = Path(tempfile.mkdtemp(prefix='codex-model-hooks-', dir=parent)).resolve()
        (fixture / 'source.txt').write_text('artifactflow-owned-hook-canary-' + uuid.uuid4().hex + '\n', encoding='ascii')
        with OwnedReceipt(fixture, 'codex-model-hooks-receipt.json') as receipt_output:
            print('Owned model-hook fixture retained: ' + str(fixture), flush=True)
            print('Checking a fresh Codex model tool call and native project hooks...', flush=True)
            result = run_session(project, fixture, timeout=args.timeout)
            receipt = fixture / 'codex-model-hooks-receipt.json'
            receipt_output.write(result)
    except OSError:
        print('UNAVAILABLE: owned fixture or receipt could not be created; no native verdict.')
        return 2
    print(('PASS' if result['passed'] else 'FAIL') + ': Codex model tool and native project hooks')
    print('Stage: ' + result['stage'])
    if result.get('category'):
        print('Diagnostic: ' + result['category'])
    print('Receipt and owned fixture retained: ' + str(receipt))
    return 0 if result['passed'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
