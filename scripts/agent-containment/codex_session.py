#!/usr/bin/env python3
"""Inspect a fresh Codex app-server session without selecting another policy.

No model turn is submitted. Normal client startup can initialize configured
integrations and write its usual logs/cache. Raw config, hook commands, client
notifications and stderr are never returned or saved by this diagnostic.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import selectors
import subprocess
import sys
import time
import tomllib
from pathlib import Path

from run_codex import resolve_codex
from process_cleanup import stop_owned_process_group


MAX_OUTPUT = 8 * 1024 * 1024
MARKER = 'ARTIFACTFLOW_DEVELOPMENT_RECEIPT '


class SessionError(Exception):
    """Only fixed diagnostic codes, never raw client messages."""

    def __init__(self, code: str, details: dict | None = None):
        super().__init__(code)
        self.details = details


def rpc_error_details(error: object) -> dict:
    """Retain typed code and fixed message signatures, never client text/data."""
    error = error if isinstance(error, dict) else {}
    code = error.get('code')
    message = error.get('message')
    message = message if isinstance(message, str) else ''
    normalized = message.lower()
    category = 'unclassified_client_error'
    signatures = (
        ('operation not permitted', 'os_permission_denied'),
        ('permission denied', 'os_permission_denied'),
        ('no such file or directory', 'required_path_missing'),
        ('missing field', 'required_protocol_field_missing'),
        ('not authenticated', 'authentication_required'),
        ('unauthorized', 'authentication_required'),
        ('login required', 'authentication_required'),
        ('invalid configuration', 'configuration_load_failed'),
        ('failed to load configuration', 'configuration_load_failed'),
        ('error creating thread:', 'thread_creation_failed'),
    )
    for signature, diagnostic in signatures:
        if signature in normalized:
            category = diagnostic
            break
    return {'rpc_error_code': code if type(code) is int else None,
            'rpc_error_diagnostic': category,
            'thread_creation_failure': 'error creating thread:' in normalized,
            'message_sha256': hashlib.sha256(message.encode()).hexdigest()}


class AppServer:
    def __init__(self, binary: str, project: Path, timeout: float):
        self.deadline = time.monotonic() + timeout
        self.sequence = 0
        self.buffer = b''
        self.received = 0
        self.warning_count = 0
        self.stderr_hash = hashlib.sha256()
        self.process = subprocess.Popen(
            [binary, 'app-server', '--stdio'], cwd=project, start_new_session=True,
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        )
        self.selector = selectors.DefaultSelector()
        try:
            self.selector.register(self.process.stdout, selectors.EVENT_READ, 'stdout')
            self.selector.register(self.process.stderr, selectors.EVENT_READ, 'stderr')
        except BaseException:
            self.close()
            raise

    def notify(self, method: str, params: dict | None = None) -> None:
        self._send({'method': method, **({'params': params} if params is not None else {})})

    def _send(self, message: dict) -> None:
        self.process.stdin.write(json.dumps(message, separators=(',', ':')).encode() + b'\n')
        self.process.stdin.flush()

    def _next(self) -> dict:
        while True:
            if b'\n' in self.buffer:
                line, self.buffer = self.buffer.split(b'\n', 1)
                try:
                    value = json.loads(line)
                except (ValueError, UnicodeError):
                    raise SessionError('invalid_protocol') from None
                if not isinstance(value, dict):
                    raise SessionError('invalid_protocol')
                return value
            remaining = self.deadline - time.monotonic()
            if remaining <= 0:
                raise TimeoutError
            events = self.selector.select(remaining)
            if not events:
                raise TimeoutError
            for key, _ in events:
                chunk = os.read(key.fileobj.fileno(), 65536)
                if not chunk:
                    self.selector.unregister(key.fileobj)
                    if key.data == 'stdout':
                        raise SessionError('client_closed')
                    continue
                self.received += len(chunk)
                if self.received > MAX_OUTPUT:
                    raise SessionError('output_limit')
                if key.data == 'stderr':
                    self.stderr_hash.update(chunk)
                else:
                    self.buffer += chunk

    def request(self, method: str, params: dict) -> dict:
        self.sequence += 1
        identifier = self.sequence
        self._send({'id': identifier, 'method': method, 'params': params})
        while True:
            message = self._next()
            if 'method' in message:
                if 'id' in message:
                    raise SessionError('unexpected_client_request')
                if message['method'] == 'configWarning':
                    self.warning_count += 1
                continue
            if message.get('id') != identifier:
                raise SessionError('invalid_protocol')
            if 'error' in message:
                raise SessionError('client_rpc_error', rpc_error_details(message['error']))
            if not isinstance(message.get('result'), dict):
                raise SessionError('invalid_protocol')
            return message['result']

    def close(self) -> None:
        try:
            stop_owned_process_group(self.process)
        finally:
            self.selector.close()
            for stream in (self.process.stdin, self.process.stdout, self.process.stderr):
                if stream is not None:
                    stream.close()


def without_nulls(value):
    if isinstance(value, dict):
        return {key: without_nulls(item) for key, item in value.items() if item is not None}
    return value


def leaf_paths(value: dict, prefix: str = '') -> list[str]:
    paths = []
    for key, item in value.items():
        path = prefix + key
        paths.extend(leaf_paths(item, path + '.') if isinstance(item, dict) else [path])
    return paths


def project_evidence(response: dict, expected: dict, project: Path) -> dict:
    config = response.get('config', {})
    origins = response.get('origins', {})
    profile_name = expected['default_permissions']
    profile = expected['permissions'][profile_name]
    if not isinstance(config, dict) or not isinstance(origins, dict):
        raise SessionError('invalid_configuration_response')
    checks = {
        'profile_name_matches': config.get('default_permissions') == profile_name,
        'profile_definition_matches': without_nulls(config.get('permissions', {}).get(profile_name)) == profile,
        'approval_policy_matches': config.get('approval_policy') == expected['approval_policy'],
        'reviewer_matches': config.get('approvals_reviewer') == expected['approvals_reviewer'],
        'legacy_sandbox_absent': config.get('sandbox_mode') is None,
    }
    if not all(checks.values()):
        raise SessionError('project_configuration_mismatch', checks)
    hooks = config.get('features', {}).get('hooks')
    if hooks is not True and hooks != {'enabled': True}:
        raise SessionError('project_hooks_disabled')
    paths = ['default_permissions', 'approval_policy', 'approvals_reviewer']
    paths += leaf_paths(profile, 'permissions.' + profile_name + '.')
    paths.append('features.hooks' if 'features.hooks' in origins else 'features.hooks.enabled')
    versions = set()
    for path in paths:
        origin = origins.get(path, {})
        name = origin.get('name', {})
        version = origin.get('version')
        if (name != {'type': 'project', 'dotCodexFolder': str(project / '.codex')}
                or not isinstance(version, str) or not re.fullmatch(r'sha256:[0-9a-f]{64}', version)):
            raise SessionError('project_configuration_not_active',
                               {'project_origin_matches': False, 'expected_project_field': path})
        versions.add(version)
    if len(versions) != 1:
        raise SessionError('project_configuration_mismatch')
    return {'configuration_matches_project': True, 'source': str(project / '.codex/config.toml'),
            'source_version': next(iter(versions)), 'source_kind': 'project',
            'profile': profile_name, 'permission_leaf_count': len(paths), 'hooks_enabled': True}


def hook_evidence(response: dict, expected: dict, project: Path) -> dict:
    entries = response.get('data', [])
    entries = [entry for entry in entries if isinstance(entry, dict) and entry.get('cwd') == str(project)]
    if len(entries) != 1 or entries[0].get('errors') != []:
        raise SessionError('project_hook_registration_failed')
    hooks = entries[0].get('hooks', [])
    source = str(project / '.codex/hooks.json')
    registered = []
    for event, groups in expected['hooks'].items():
        for group in groups:
            for wanted in group['hooks']:
                matches = [hook for hook in hooks if isinstance(hook, dict)
                           and hook.get('sourcePath') == source
                           and hook.get('eventName') == event[0].lower() + event[1:]
                           and hook.get('handlerType') == wanted['type']
                           and hook.get('matcher') == group.get('matcher')
                           and hook.get('command') == wanted['command']]
                if (len(matches) != 1 or matches[0].get('source') != 'project'
                        or matches[0].get('enabled') is not True
                        or matches[0].get('trustStatus') != 'trusted'):
                    raise SessionError('project_hook_registration_failed')
                registered.append({'event': event, 'trusted': True,
                                   'command_sha256': hashlib.sha256(wanted['command'].encode()).hexdigest()})
    if not registered:
        raise SessionError('project_hook_registration_failed')
    return {'registered': True, 'source': source, 'hooks': registered,
            'execution_verified': False, 'warning_count': len(entries[0].get('warnings', []))}


def thread_evidence(response: dict, expected: dict, project: Path) -> dict:
    profile_name = expected['default_permissions']
    active = {'id': profile_name, 'extends': expected['permissions'][profile_name].get('extends')}
    if (response.get('cwd') != str(project)
            or response.get('runtimeWorkspaceRoots') != [str(project)]
            or response.get('activePermissionProfile') != active
            or response.get('approvalPolicy') != expected['approval_policy']
            or response.get('approvalsReviewer') != expected['approvals_reviewer']
            or response.get('sandbox', {}).get('type') != 'workspaceWrite'
            or str(project / 'AGENTS.md') not in response.get('instructionSources', [])):
        raise SessionError('thread_permissions_mismatch')
    return {'active': True, 'cwd': str(project), 'runtime_workspace_roots': [str(project)],
            'active_permission_profile': active, 'approval_policy': response['approvalPolicy'],
            'approvals_reviewer': response['approvalsReviewer'],
            'instruction_file_loaded': str(project / 'AGENTS.md') in response.get('instructionSources', []),
            'effective_filesystem_entries_exposed': False}


def read_project(project: Path) -> tuple[dict, dict, str, str]:
    config_bytes = (project / '.codex/config.toml').read_bytes()
    hook_bytes = (project / '.codex/hooks.json').read_bytes()
    return (tomllib.loads(config_bytes.decode()), json.loads(hook_bytes),
            hashlib.sha256(config_bytes).hexdigest(), hashlib.sha256(hook_bytes).hexdigest())


def command_evidence(response: dict, nonce: str) -> dict:
    from development_probe import summarize_receipt

    stdout, stderr = response.get('stdout'), response.get('stderr')
    if not isinstance(stdout, str) or not isinstance(stderr, str):
        raise SessionError('invalid_command_result')
    markers = [line[len(MARKER):] for line in stdout.splitlines() if line.startswith(MARKER)]
    if len(markers) != 1:
        raise SessionError('invalid_command_receipt')
    try:
        payload = json.loads(markers[0])
    except ValueError:
        raise SessionError('invalid_command_receipt') from None
    summary = summarize_receipt(payload, nonce)
    valid = summary['passed'] is True
    return {'passed': type(response.get('exitCode')) is int and response['exitCode'] == 0 and valid,
            'receipt_validated': valid, 'stderr_sha256': hashlib.sha256(stderr.encode()).hexdigest(),
            'receipt': payload if valid else None, 'summary': summary,
            'scope': 'fresh_app_server_startup_policy; not a thread tool or hook execution'}


def run_session(project: Path, command: list[str] | None = None, timeout: float = 30,
                codex_bin: str | None = None, expected_nonce: str | None = None) -> dict:
    result = {'passed': False, 'scope': 'fresh_app_server_project_activation',
              'model_turn_submitted': False, 'stage': 'arguments'}
    rpc = None
    try:
        if not 0 < timeout <= 300 or (command is not None and not expected_nonce):
            raise SessionError('invalid_arguments')
        project = Path(project).resolve()
        result['stage'] = 'project_files'
        expected, hooks, digest, hook_digest = read_project(project)
        result.update({'project': str(project), 'config_sha256': digest, 'hooks_sha256': hook_digest})
        binary = resolve_codex(codex_bin)
        if binary is None:
            raise SessionError('client_unavailable')
        result['executable'] = binary
        result['stage'] = 'client_initialization'
        rpc = AppServer(binary, project, timeout)
        rpc.request('initialize', {'clientInfo': {'name': 'artifactflow_activation', 'version': '1'},
                                   'capabilities': {'experimentalApi': True}})
        rpc.notify('initialized')
        result['stage'] = 'project_configuration'
        config = rpc.request('config/read', {'cwd': str(project), 'includeLayers': False})
        result['configuration'] = project_evidence(config, expected, project)
        del config
        result['stage'] = 'hook_registration'
        result['hooks'] = hook_evidence(rpc.request('hooks/list', {'cwds': [str(project)]}), hooks, project)
        # Omitting cwd avoids thread/start's automatic project-trust persistence.
        # The server process already has the real cwd; verify the returned cwd.
        result['stage'] = 'thread_activation'
        result['session'] = thread_evidence(rpc.request('thread/start', {'ephemeral': True}), expected, project)
        if read_project(project)[2:] != (digest, hook_digest):
            raise SessionError('project_configuration_changed')
        if command is not None:
            result['stage'] = 'development_command'
            response = rpc.request('command/exec', {'command': command, 'cwd': str(project),
                                                   'timeoutMs': int(timeout * 1000), 'outputBytesCap': 524288})
            result['command'] = command_evidence(response, expected_nonce)
            result['command_receipt'] = result['command'].pop('receipt')
        result['config_warning_count'] = rpc.warning_count
        result['passed'] = command is None or result['command']['passed']
        if result['passed']:
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
                result.setdefault('category', 'cleanup_failed')
    return result


def inspect_session(project: Path, timeout: float = 30) -> dict:
    return run_session(project, timeout=timeout)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project-root', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--timeout', type=float, default=30)
    args = parser.parse_args()
    result = inspect_session(args.project_root, timeout=args.timeout)
    print(json.dumps(result, indent=2))
    return 0 if result['passed'] is True else 1


if __name__ == '__main__':
    raise SystemExit(main())
