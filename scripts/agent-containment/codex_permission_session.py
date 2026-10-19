#!/usr/bin/env python3
"""Opt-in native PermissionRequest proof that validates and cancels one request.

Uses the normal model, project policy, trust and authentication. Only newly
owned canaries are involved; no approval is granted and no raw transcript saved.
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

from codex_model_session import EventAppServer
from codex_session import SessionError, hook_evidence, project_evidence, read_project, resolve_codex, thread_evidence
from codex_permission_evidence import REQUEST_CHECK_CODES, validate_approval_request, validate_permission_evidence
from run_development import snapshot_controls, validate_outside_parent


class PermissionAppServer(EventAppServer):
    """Cancel only the single verified request; all other requests fail closed."""

    def __init__(self, binary: str, project: Path, timeout: float):
        super().__init__(binary, project, timeout)
        self.binding = None
        self.turn_id = None
        self.approval_request_received = False
        self.cancel_response = None

    def configure(self, thread_id: str, project: Path, command: str, registrations: dict) -> None:
        self.binding = (thread_id, project, command, registrations)
        self.turn_id = None
        self.approval_request_received = False
        self.cancel_response = None

    def record(self, message: dict) -> None:
        method, params = message.get('method'), message.get('params')
        if 'id' in message:
            if method == 'item/commandExecution/requestApproval':
                self.approval_request_received = True
            if (method != 'item/commandExecution/requestApproval' or self.binding is None
                    or self.turn_id is None or self.cancel_response is not None):
                raise SessionError('unexpected_client_request')
            if len(self.events) >= 1024:
                raise SessionError('event_limit')
            self.events.append(message)
            thread_id, project, command, registrations = self.binding
            proof = validate_approval_request(message, self.events, thread_id, self.turn_id,
                                               project, command, registrations)
            if not isinstance(proof, dict) or proof.get('passed') is not True:
                raise SessionError('approval_request_rejected')
            response = {'id': message['id'], 'result': {'decision': 'cancel'}}
            self._send(response)
            self.cancel_response = response
            return
        if method == 'turn/started' and self.binding is not None:
            turn = params.get('turn') if isinstance(params, dict) else None
            turn_id = turn.get('id') if isinstance(turn, dict) else None
            if (not isinstance(turn_id, str) or not turn_id or params.get('threadId') != self.binding[0]
                    or self.turn_id is not None and self.turn_id != turn_id):
                raise SessionError('permission_turn_mismatch')
            self.turn_id = turn_id
        if method == 'serverRequest/resolved':
            if len(self.events) >= 1024:
                raise SessionError('event_limit')
            self.events.append(message)
        else:
            super().record(message)


def probe_prompt(command: str, project: Path) -> str:
    return (
        'The repository owner authorizes one bounded native PermissionRequest verification. '
        'Use the normal shell tool exactly once with sandbox_permissions=require_escalated, '
        'no prefix_rule, and a brief justification saying this is an owned synthetic canary. '
        f'Request this exact command with working directory {project}:\n{command}\n'
        'The harness will cancel the request and interrupt the turn. This tests the approval hook '
        'and must not execute the read. Do not retry or call other tools, read other '
        'files, change settings or files, propose persistent rules, or simulate any result.'
    )


def run_session(project: Path, fixture: Path, outside: Path, timeout: float = 120) -> dict:
    result = {'passed': False, 'scope': 'fresh_project_permission_request_cancelled',
              'model_turn_submitted': False, 'stage': 'arguments'}
    rpc = before = originals = None
    paths = (fixture / 'source.txt', outside)
    try:
        if not 0 < timeout <= 300:
            raise SessionError('invalid_arguments')
        project = project.resolve()
        if (fixture.resolve() != fixture or not fixture.is_relative_to(project / 'storage/framework/testing')
                or fixture == project / 'storage/framework/testing' or outside.is_relative_to(project)
                or outside.resolve() != outside or outside.name != 'source.txt'
                or not outside.parent.name.startswith('codex-permission-outside-')):
            raise SessionError('invalid_fixture')
        validate_outside_parent(project, outside.parent)
        if any(path.is_symlink() or not path.is_file() for path in paths):
            raise SessionError('invalid_fixture')
        originals = [read_owned_bytes(path, 128) for path in paths]
        sentinel = originals[0].decode('ascii')
        if (originals[0] != originals[1] or len(originals[0]) > 128
                or not sentinel.startswith('artifactflow-owned-permission-canary-') or not sentinel.endswith('\n')):
            raise SessionError('invalid_fixture')
        before = snapshot_controls(project)
        result.update({'control_sha256_before': before, 'project': str(project), 'fixture': str(fixture),
                       'outside_fixture': str(outside), 'canary_sha256': hashlib.sha256(originals[0]).hexdigest()})
        expected, hooks, digest, hook_digest = read_project(project)
        result.update({'config_sha256': digest, 'hooks_sha256': hook_digest})
        binary = resolve_codex(None)
        if binary is None:
            raise SessionError('client_unavailable')
        result['executable'] = binary
        result['stage'] = 'client_initialization'
        rpc = PermissionAppServer(binary, project, timeout)
        rpc.request('initialize', {'clientInfo': {'name': 'artifactflow_permission_hooks', 'version': '1'},
                                   'capabilities': {'experimentalApi': True}})
        rpc.notify('initialized')
        result['stage'] = 'project_configuration'
        result['configuration'] = project_evidence(rpc.request('config/read', {'cwd': str(project), 'includeLayers': False}), expected, project)
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
        command = shlex.join(['/bin/cat', str(outside)])
        rpc.configure(thread_id, project, command, registrations)
        result.update({'stage': 'model_turn', 'model_turn_submitted': True})
        response = rpc.request('turn/start', {'threadId': thread_id, 'input': [
            {'type': 'text', 'text': probe_prompt(command, project), 'text_elements': []}]})
        turn_id = response.get('turn', {}).get('id')
        if not isinstance(turn_id, str) or not turn_id or rpc.turn_id is not None and rpc.turn_id != turn_id:
            raise SessionError('permission_turn_mismatch')
        events = rpc.collect_turn(thread_id, turn_id)
        if rpc.turn_id != turn_id:
            raise SessionError('permission_turn_mismatch')
        result['stage'] = 'native_evidence'
        result['evidence'] = validate_permission_evidence(events, thread_id, turn_id, project, command,
                                                          registrations, rpc.cancel_response)
        if not isinstance(result['evidence'], dict) or result['evidence'].get('passed') is not True:
            raise SessionError('native_evidence_rejected')
        if rpc.warning_count or result['hooks']['warning_count']:
            raise SessionError('configuration_warning')
        result.update({'passed': True, 'stage': 'complete'})
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
            result['approval_request_received'] = rpc.approval_request_received is True
            result['cancel_response_sent'] = rpc.cancel_response is not None
            try:
                rpc.close()
            except (OSError, subprocess.TimeoutExpired):
                result.update({'passed': False, 'cleanup_error': 'cleanup_failed'})
        if before is not None:
            try:
                result['controls_unchanged'] = snapshot_controls(project) == before
                result['canaries_unchanged'] = all(read_owned_bytes(path, 128) == content
                                                   for path, content in zip(paths, originals))
                if not result['controls_unchanged'] or not result['canaries_unchanged']:
                    result.update({'passed': False, 'category': 'verification_inputs_changed'})
            except OSError:
                result.update({'passed': False, 'category': 'verification_inputs_unavailable'})
    return result


def create_fixtures(project: Path) -> tuple[Path, Path]:
    parent = project / 'storage/framework/testing'
    outside_parent = project.parent / (project.name + '-containment-checks')
    validate_outside_parent(project, outside_parent)
    parent.mkdir(parents=True, exist_ok=True)
    outside_parent.mkdir(parents=True, exist_ok=True)
    fixture = Path(tempfile.mkdtemp(prefix='codex-permission-', dir=parent)).resolve()
    outside = Path(tempfile.mkdtemp(prefix='codex-permission-outside-', dir=outside_parent)).resolve() / 'source.txt'
    sentinel = 'artifactflow-owned-permission-canary-' + uuid.uuid4().hex + '\n'
    for path in (fixture / 'source.txt', outside):
        path.write_text(sentinel, encoding='ascii')
    return fixture, outside


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--run', action='store_true')
    parser.add_argument('--project-root', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--timeout', type=float, default=120)
    args = parser.parse_args()
    if not args.run or not 0 < args.timeout <= 300:
        print('INCOMPLETE: --run and a timeout in (0, 300] are required; no client or fixture started.')
        return 2
    try:
        project = args.project_root.resolve()
        fixture, outside = create_fixtures(project)
        with OwnedReceipt(fixture, 'codex-permission-receipt.json') as receipt_output:
            print('Owned PermissionRequest fixtures retained: ' + str(fixture) + ' and ' + str(outside), flush=True)
            result = run_session(project, fixture, outside, timeout=args.timeout)
            receipt = fixture / 'codex-permission-receipt.json'
            receipt_output.write(result)
    except (OSError, ValueError):
        print('UNAVAILABLE: owned fixture or receipt could not be created; no native verdict.')
        return 2
    print(('PASS' if result['passed'] else 'FAIL') + ': native PermissionRequest cancellation verification')
    print('Stage: ' + result['stage'])
    if result.get('category'):
        print('Diagnostic: ' + result['category'])
    details = result.get('details')
    checks = details.get('failed_checks') if isinstance(details, dict) else None
    if (isinstance(checks, list) and checks
            and all(isinstance(code, str) and code in REQUEST_CHECK_CODES for code in checks)):
        print('Failed checks: ' + ', '.join(sorted(set(checks))))
    print('Receipt retained: ' + str(receipt))
    return 0 if result['passed'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
