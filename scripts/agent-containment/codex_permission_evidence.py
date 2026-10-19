#!/usr/bin/env python3
"""Prove a native Codex PermissionRequest hook ran before cancelling a request.

Pinned protocol semantics: Codex 0.162.0-alpha.17.2 approvals.rs uses the initial
call ID as the hook-run suffix; bespoke_event_handling.rs publishes an approval
presentation item before the request, resolves it, then emits a declined item
before the cancel response interrupts the turn. That item's lifecycle is not
evidence that a process executed.
"""
from __future__ import annotations

import shlex
from pathlib import Path

from codex_hook_evidence import PASSIVE_NOTIFICATIONS, TEXT_ITEMS, _registered_runs, _same_command
from codex_session import SessionError


APPROVAL_METHOD = 'item/commandExecution/requestApproval'
NULL_PROCESS_FIELDS = ('processId', 'aggregatedOutput', 'exitCode', 'durationMs')
REQUEST_CHECK_CODES = frozenset({
    'permission_request_method_invalid', 'permission_request_id_invalid',
    'permission_request_params_invalid', 'permission_request_thread_mismatch',
    'permission_request_turn_mismatch', 'permission_request_item_invalid',
    'permission_request_kind_mismatch', 'permission_request_subcommand_not_supported',
    'permission_request_cwd_mismatch', 'permission_request_command_mismatch',
    'permission_request_timestamp_invalid', 'permission_request_network_not_allowed',
    'permission_request_additional_permissions_not_allowed',
    'permission_request_network_amendment_not_allowed',
    'permission_request_decisions_invalid', 'permission_request_cancel_unavailable',
    'permission_request_amendment_mismatch',
})


def _fail(code: str) -> None:
    raise SessionError(code)


def _same_id(first: object, second: object) -> bool:
    return type(first) is type(second) and first == second


def _valid_id(value: object) -> bool:
    return type(value) is int or (isinstance(value, str) and bool(value))


def _registrations(registrations: dict, project: Path) -> dict[str, dict]:
    result = _registered_runs(registrations, project)
    entry = next(entry for entry in registrations['data']
                 if isinstance(entry, dict) and entry.get('cwd') == str(project))
    source = str(project / '.codex/hooks.json')
    matches = [hook for hook in entry['hooks'] if isinstance(hook, dict)
               and hook.get('sourcePath') == source and hook.get('eventName') == 'permissionRequest'
               and hook.get('matcher') == '^Bash$']
    if len(matches) != 1:
        _fail('permission_hook_registration_invalid')
    hook = matches[0]
    if (hook.get('source') != 'project' or hook.get('handlerType') != 'command'
            or hook.get('enabled') is not True or hook.get('trustStatus') != 'trusted'
            or hook.get('async', False) is not False
            or type(hook.get('displayOrder')) is not int or hook['displayOrder'] < 0
            or not isinstance(hook.get('currentHash'), str) or not hook['currentHash']
            or not isinstance(hook.get('command'), str) or not hook['command']):
        _fail('permission_hook_registration_invalid')
    result[f'permission-request:{hook["displayOrder"]}:{source}'] = hook
    return result


def _request(message: dict, thread_id: str, turn_id: str, project: Path, command: str) -> dict:
    params = message.get('params')
    checks = [
        ('permission_request_method_invalid', message.get('method') == APPROVAL_METHOD),
        ('permission_request_id_invalid', _valid_id(message.get('id'))),
        ('permission_request_params_invalid', isinstance(params, dict)),
    ]
    if not isinstance(params, dict):
        failed = [code for code, passed in checks if not passed]
        raise SessionError(failed[0], {'failed_checks': failed})
    native_command = params.get('command')
    command_matches = _same_command(native_command, command)
    checks.extend([
        ('permission_request_thread_mismatch', params.get('threadId') == thread_id),
        ('permission_request_turn_mismatch', params.get('turnId') == turn_id),
        ('permission_request_item_invalid', isinstance(params.get('itemId'), str)
         and bool(params['itemId'])),
        # The installed schema defaults an omitted kind to command, not null.
        ('permission_request_kind_mismatch', params.get('kind', 'command') == 'command'),
        ('permission_request_subcommand_not_supported', params.get('approvalId') is None),
        ('permission_request_cwd_mismatch', params.get('cwd') == str(project)),
        ('permission_request_command_mismatch', command_matches),
        ('permission_request_timestamp_invalid', type(params.get('startedAtMs')) is int),
        ('permission_request_network_not_allowed', params.get('networkApprovalContext') is None),
        ('permission_request_additional_permissions_not_allowed', params.get('additionalPermissions') is None),
        ('permission_request_network_amendment_not_allowed',
         params.get('proposedNetworkPolicyAmendments') in (None, [])),
    ])
    # protocol/approvals.rs defaults ordinary command decisions to Approved,
    # optional execpolicy amendment, and Abort (the app-server's cancel). An
    # explicit list must offer cancel; absent/null uses that documented fallback.
    decisions = params.get('availableDecisions')
    checks.append(('permission_request_decisions_invalid', decisions is None or isinstance(decisions, list)))
    if isinstance(decisions, list):
        checks.append(('permission_request_cancel_unavailable', 'cancel' in decisions))
    # Native execpolicy may offer a full-command suggestion even without a model
    # prefix_rule. It is not a grant; this driver can send only a plain cancel.
    suggestion = params.get('proposedExecpolicyAmendment')
    if suggestion not in (None, []):
        suggestions = ()
        if command_matches:
            try:
                suggestions = (shlex.split(command), shlex.split(native_command))
            except ValueError:
                pass
        checks.append(('permission_request_amendment_mismatch', suggestion in suggestions))
    failed = [code for code, passed in checks if not passed]
    if failed:
        raise SessionError(failed[0], {'failed_checks': failed})
    return params


def _item(item: dict, command: str, project: Path, completed: bool) -> None:
    if (not isinstance(item.get('id'), str) or not item['id']
            or not _same_command(item.get('command'), command) or item.get('cwd') != str(project)
            or item.get('source') != 'agent'
            or item.get('status') != ('declined' if completed else 'inProgress')
            or item.get('pluginId') is not None or item.get('scriptPath') is not None
            or any(field not in item or item[field] is not None for field in NULL_PROCESS_FIELDS)):
        _fail('permission_command_presentation_invalid')


def _analyze(events: list[dict], thread_id: str, turn_id: str, project: Path,
             command: str, registrations: dict, final: bool) -> dict:
    if (not isinstance(events, list) or not events or len(events) > 10000
            or not isinstance(thread_id, str) or not thread_id
            or not isinstance(turn_id, str) or not turn_id
            or not isinstance(command, str) or not command):
        _fail('permission_evidence_invalid')
    registered = _registrations(registrations, project)
    starts, ends = {}, {}
    turn_start = turn_end = command_start = command_end = request = resolved = None
    for index, event in enumerate(events):
        if not isinstance(event, dict):
            _fail('permission_evidence_invalid')
        method, params = event.get('method'), event.get('params')
        if not isinstance(method, str) or not isinstance(params, dict):
            _fail('permission_evidence_invalid')
        if 'id' in event:
            if request is not None:
                _fail('unexpected_permission_request')
            _request(event, thread_id, turn_id, project, command)
            request = (index, event)
            continue
        if (params.get('threadId', thread_id) != thread_id
                or params.get('turnId', turn_id) != turn_id):
            _fail('permission_evidence_scope_mismatch')
        if method in PASSIVE_NOTIFICATIONS:
            continue
        if params.get('threadId') != thread_id:
            _fail('permission_evidence_scope_mismatch')
        if method == 'serverRequest/resolved':
            if resolved is not None or not _valid_id(params.get('requestId')):
                _fail('permission_resolution_invalid')
            resolved = (index, params)
            continue
        if method in ('turn/started', 'turn/completed'):
            completed = method == 'turn/completed'
            turn = params.get('turn')
            if (not isinstance(turn, dict) or turn.get('id') != turn_id
                    or turn.get('status') != ('interrupted' if completed else 'inProgress')
                    or turn.get('error') is not None):
                _fail('permission_turn_invalid')
            if completed:
                if turn_end is not None:
                    _fail('permission_turn_invalid')
                turn_end = (index, turn)
            else:
                if turn_start is not None:
                    _fail('permission_turn_invalid')
                turn_start = index
            continue
        if params.get('turnId') != turn_id:
            _fail('permission_evidence_scope_mismatch')
        if method in ('hook/started', 'hook/completed'):
            completed = method == 'hook/completed'
            run = params.get('run')
            target = ends if completed else starts
            if (not isinstance(run, dict) or not isinstance(run.get('id'), str)
                    or run['id'] in target or run.get('source') != 'project'
                    or run.get('handlerType') != 'command' or run.get('executionMode') != 'sync'
                    or run.get('scope') != 'turn'
                    or run.get('status') != ('completed' if completed else 'running')
                    or type(run.get('displayOrder')) is not int
                    or type(run.get('startedAt')) is not int
                    or not isinstance(run.get('entries'), list)):
                _fail('permission_hook_event_invalid')
            if completed and (type(run.get('completedAt')) is not int
                              or run['completedAt'] < run['startedAt']
                              or type(run.get('durationMs')) is not int or run['durationMs'] < 0
                              or any(not isinstance(entry, dict) or set(entry) != {'kind', 'text'}
                                     or entry.get('kind') not in ('warning', 'context')
                                     or not isinstance(entry.get('text'), str)
                                     for entry in run['entries'])):
                _fail('permission_hook_event_invalid')
            target[run['id']] = (index, run)
        elif method in ('item/started', 'item/completed'):
            item = params.get('item')
            if not isinstance(item, dict) or not isinstance(item.get('type'), str):
                _fail('permission_item_invalid')
            if item['type'] in TEXT_ITEMS:
                continue
            if item['type'] != 'commandExecution':
                _fail('unexpected_permission_tool')
            completed = method == 'item/completed'
            _item(item, command, project, completed)
            if completed:
                if command_end is not None:
                    _fail('unexpected_permission_tool')
                command_end = (index, item)
            else:
                if command_start is not None:
                    _fail('unexpected_permission_tool')
                command_start = (index, item)
        else:
            _fail('unexpected_permission_notification')
    if turn_start is None or command_start is None or request is None:
        _fail('permission_evidence_incomplete')
    req = request[1]['params']
    call_id = req['itemId']
    if (not turn_start < command_start[0] < request[0]
            or command_start[1]['id'] != call_id
            or command_start[1]['command'] != req['command']):
        _fail('permission_request_binding_invalid')
    expected = {base + ('' if hook['eventName'] == 'userPromptSubmit' else f':{call_id}'): hook
                for base, hook in registered.items()}
    if set(starts) != set(expected) or set(ends) != set(expected):
        _fail('permission_hook_execution_incomplete')
    by_event = {'userPromptSubmit': [], 'preToolUse': [], 'permissionRequest': []}
    for identifier, hook in expected.items():
        start_index, start = starts[identifier]
        end_index, end = ends[identifier]
        if not start_index < end_index < command_start[0]:
            _fail('permission_hook_order_invalid')
        for field in ('sourcePath', 'source', 'eventName', 'displayOrder'):
            if start.get(field) != hook.get(field) or end.get(field) != hook.get(field):
                _fail('permission_hook_binding_invalid')
        by_event[hook['eventName']].append((start_index, end_index))
    prompt_end = by_event['userPromptSubmit'][0][1]
    permission_start = by_event['permissionRequest'][0][0]
    if any(not prompt_end < start < end < permission_start
           for start, end in by_event['preToolUse']):
        _fail('permission_hook_order_invalid')
    if not final:
        if resolved is not None or command_end is not None or turn_end is not None:
            _fail('permission_precheck_after_resolution')
        return request[1]
    if resolved is None or command_end is None or turn_end is None:
        _fail('permission_evidence_incomplete')
    if (not request[0] < resolved[0] < command_end[0] < turn_end[0]
            or not _same_id(resolved[1]['requestId'], request[1]['id'])
            or any(command_start[1].get(field) != command_end[1].get(field)
                   for field in ('id', 'command', 'cwd', 'source'))):
        _fail('permission_resolution_invalid')
    items = turn_end[1].get('items', [])
    if not isinstance(items, list):
        _fail('permission_turn_invalid')
    commands = 0
    for item in items:
        if not isinstance(item, dict) or not isinstance(item.get('type'), str):
            _fail('permission_turn_invalid')
        if item['type'] in TEXT_ITEMS:
            continue
        if item['type'] != 'commandExecution' or item.get('id') != call_id:
            _fail('unexpected_permission_tool')
        _item(item, command, project, True)
        if item['command'] != req['command']:
            _fail('permission_request_binding_invalid')
        commands += 1
    if commands > 1:
        _fail('unexpected_permission_tool')
    return request[1]


def validate_approval_request(message: dict, events: list[dict], thread_id: str,
                              turn_id: str, project: Path, command: str,
                              registrations: dict) -> dict:
    """Verify the exact native request and four completed hooks before cancelling."""
    request = _analyze(events, thread_id, turn_id, project, command, registrations, False)
    if request != message or not _same_id(request.get('id'), message.get('id')):
        _fail('permission_request_binding_invalid')
    return {'passed': True}


def validate_permission_evidence(events: list[dict], thread_id: str, turn_id: str,
                                 project: Path, command: str, registrations: dict,
                                 cancel_response: dict) -> dict:
    """Require the actual sent cancel plus native resolution; return no raw text.

    The driver supplies cancel_response only after successfully sending that exact
    object to the native RPC request. No accepted or persisted approval can pass.
    """
    request = _analyze(events, thread_id, turn_id, project, command, registrations, True)
    expected = {'id': request['id'], 'result': {'decision': 'cancel'}}
    if (not isinstance(cancel_response, dict) or cancel_response != expected
            or not _same_id(cancel_response.get('id'), request['id'])):
        _fail('permission_cancel_not_proven')
    return {'passed': True, 'execution_verified': True, 'hook_runs_completed': 4,
            'user_prompt_submit_verified': True, 'pre_tool_use_verified': True,
            'permission_request_verified': True, 'approval_request_count': 1,
            'approval_cancelled': True, 'server_request_resolved': True,
            'command_executed': False, 'turn_interrupted': True,
            'policy_suggestion_offered': bool(request['params'].get('proposedExecpolicyAmendment')),
            'policy_amendment_applied': False}
