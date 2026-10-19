#!/usr/bin/env python3
"""Validate installed Codex native hook events for one reviewed model command.

The caller validates project registration/configuration, owns the nonsecret fixture,
and binds its exact command and output. This pure validator neither starts a client
nor trusts model text. Run-ID syntax is pinned to Codex 0.162.0-alpha.17.2:
hooks/src/engine/mod.rs (event:order:path), events/common.rs (tool-use suffix),
core/tools/registry.rs and tools/events.rs (same invocation/item call ID).
"""
from __future__ import annotations

import shlex
from pathlib import Path

from codex_session import SessionError


TEXT_ITEMS = {'userMessage', 'agentMessage', 'reasoning'}
MODEL_COMMAND_SOURCES = {'agent', 'unifiedExecStartup'}
PASSIVE_NOTIFICATIONS = {
    'thread/status/changed', 'thread/tokenUsage/updated',
    'item/agentMessage/delta', 'item/reasoning/summaryTextDelta',
    'item/reasoning/textDelta', 'item/reasoning/summaryPartAdded',
    'turn/moderationMetadata', 'model/safetyBuffering/updated',
}
LABELS = {'userPromptSubmit': 'user-prompt-submit', 'preToolUse': 'pre-tool-use'}


def _fail(code: str) -> None:
    raise SessionError(code)


def _registered_runs(registrations: dict, project: Path) -> dict[str, dict]:
    data = registrations.get('data') if isinstance(registrations, dict) else None
    if not isinstance(data, list):
        _fail('model_hook_registration_invalid')
    matching = [entry for entry in data if isinstance(entry, dict)
                and entry.get('cwd') == str(project)]
    if len(matching) != 1 or matching[0].get('errors') != []:
        _fail('model_hook_registration_invalid')
    hooks = matching[0].get('hooks')
    if not isinstance(hooks, list):
        _fail('model_hook_registration_invalid')
    source = str(project / '.codex/hooks.json')
    wanted = [('userPromptSubmit', None), ('preToolUse', '^Bash$'), ('preToolUse', '*')]
    result = {}
    for event, matcher in wanted:
        matches = [hook for hook in hooks if isinstance(hook, dict)
                   and hook.get('sourcePath') == source and hook.get('eventName') == event
                   and hook.get('matcher') == matcher]
        if len(matches) != 1:
            _fail('model_hook_registration_invalid')
        hook = matches[0]
        if (hook.get('source') != 'project' or hook.get('handlerType') != 'command'
                or hook.get('enabled') is not True or hook.get('trustStatus') != 'trusted'
                or hook.get('async', False) is not False
                or type(hook.get('displayOrder')) is not int or hook['displayOrder'] < 0
                or not isinstance(hook.get('currentHash'), str) or not hook['currentHash']
                or not isinstance(hook.get('command'), str) or not hook['command']):
            _fail('model_hook_registration_invalid')
        identifier = f'{LABELS[event]}:{hook["displayOrder"]}:{source}'
        if identifier in result:
            _fail('model_hook_registration_invalid')
        result[identifier] = hook
    return result


def _same_command(presentation: object, command: str) -> bool:
    if presentation == command:
        return True
    if not isinstance(presentation, str):
        return False
    try:
        argv = shlex.split(presentation)
    except ValueError:
        return False
    return (len(argv) == 3 and argv[0] in ('/bin/zsh', '/bin/bash', '/bin/sh')
            and argv[1] in ('-c', '-lc') and argv[2] == command)


def _command(item: dict, command: str, project: Path, completed: bool,
             sentinel: str) -> None:
    if (not isinstance(item.get('id'), str) or not item['id']
            or not _same_command(item.get('command'), command) or item.get('cwd') != str(project)
            or not isinstance(item.get('source'), str)
            or item.get('source') not in MODEL_COMMAND_SOURCES
            or item.get('pluginId') is not None or item.get('scriptPath') is not None
            or item.get('status') != ('completed' if completed else 'inProgress')):
        _fail('model_command_mismatch')
    if completed and (type(item.get('exitCode')) is not int or item['exitCode'] != 0
                      or item.get('aggregatedOutput') != sentinel):
        _fail('model_command_result_invalid')


def validate_model_evidence(events: list[dict], thread_id: str, turn_id: str,
                            project: Path, command: str, sentinel: str,
                            registrations: dict) -> dict:
    """Return fixed metadata only, or a SessionError with a fixed diagnostic code.

    Supply every notification from the model turn, including those received before
    turn/start responds. Unknown notifications/tools fail closed; registration alone
    or a command/exec response cannot satisfy this proof. No approval is granted.
    """
    if (not isinstance(events, list) or not events or len(events) > 10000
            or not isinstance(thread_id, str) or not thread_id
            or not isinstance(turn_id, str) or not turn_id
            or not isinstance(command, str) or not command
            or not isinstance(sentinel, str) or not sentinel):
        _fail('model_evidence_invalid')
    registered = _registered_runs(registrations, project)
    command_started = command_completed = None
    command_start_index = command_end_index = None
    hooks_started, hooks_completed = {}, {}
    turn_started = turn_completed = None
    deltas = []
    for index, event in enumerate(events):
        if not isinstance(event, dict) or 'id' in event:
            _fail('model_evidence_invalid')
        method, params = event.get('method'), event.get('params')
        if not isinstance(method, str) or not isinstance(params, dict):
            _fail('model_evidence_invalid')
        if params.get('threadId', thread_id) != thread_id:
            _fail('model_evidence_scope_mismatch')
        if params.get('turnId', turn_id) != turn_id:
            _fail('model_evidence_scope_mismatch')
        if method in PASSIVE_NOTIFICATIONS:
            continue
        if params.get('threadId') != thread_id:
            _fail('model_evidence_scope_mismatch')
        if method in ('turn/started', 'turn/completed'):
            turn = params.get('turn')
            completed = method == 'turn/completed'
            if (not isinstance(turn, dict) or turn.get('id') != turn_id
                    or turn.get('status') != ('completed' if completed else 'inProgress')
                    or turn.get('error') is not None):
                _fail('model_turn_invalid')
            if completed:
                if turn_completed is not None:
                    _fail('model_turn_invalid')
                turn_completed = (index, turn)
            else:
                if turn_started is not None:
                    _fail('model_turn_invalid')
                turn_started = index
            continue
        if params.get('turnId') != turn_id:
            _fail('model_evidence_scope_mismatch')
        if method in ('hook/started', 'hook/completed'):
            run = params.get('run')
            if not isinstance(run, dict) or not isinstance(run.get('id'), str):
                _fail('model_hook_event_invalid')
            completed = method == 'hook/completed'
            target = hooks_completed if completed else hooks_started
            if (run['id'] in target or run.get('status') != ('completed' if completed else 'running')
                    or run.get('handlerType') != 'command' or run.get('executionMode') != 'sync'
                    or run.get('scope') != 'turn' or run.get('source') != 'project'
                    or type(run.get('displayOrder')) is not int
                    or type(run.get('startedAt')) is not int
                    or not isinstance(run.get('entries'), list)):
                _fail('model_hook_event_invalid')
            if completed and (type(run.get('completedAt')) is not int
                              or run['completedAt'] < run['startedAt']
                              or type(run.get('durationMs')) is not int or run['durationMs'] < 0):
                _fail('model_hook_event_invalid')
            if completed and any(
                    not isinstance(entry, dict) or set(entry) != {'kind', 'text'}
                    or entry.get('kind') not in ('warning', 'context')
                    or not isinstance(entry.get('text'), str)
                    for entry in run['entries']):
                _fail('model_hook_event_invalid')
            target[run['id']] = (index, run)
        elif method in ('item/started', 'item/completed'):
            item = params.get('item')
            if not isinstance(item, dict) or not isinstance(item.get('type'), str):
                _fail('model_item_invalid')
            if item.get('type') in TEXT_ITEMS:
                continue
            if item.get('type') != 'commandExecution':
                _fail('unexpected_model_tool')
            completed = method == 'item/completed'
            _command(item, command, project, completed, sentinel)
            if completed:
                if command_completed is not None:
                    _fail('unexpected_model_tool')
                command_completed, command_end_index = item, index
            else:
                if command_started is not None:
                    _fail('unexpected_model_tool')
                command_started, command_start_index = item, index
        elif method == 'item/commandExecution/outputDelta':
            if not isinstance(params.get('delta'), str) or not isinstance(params.get('itemId'), str):
                _fail('model_command_result_invalid')
            deltas.append((index, params['itemId']))
        else:
            _fail('unexpected_model_notification')

    if (command_started is None or command_completed is None or turn_started is None
            or turn_completed is None or not turn_started < command_start_index < command_end_index
            or command_end_index >= turn_completed[0]):
        _fail('model_evidence_incomplete')
    for key in ('id', 'command', 'cwd', 'source'):
        if command_started.get(key) != command_completed.get(key):
            _fail('model_command_mismatch')
    call_id = command_completed['id']
    if any(item_id != call_id or not command_start_index < index < command_end_index
           for index, item_id in deltas):
        _fail('model_command_result_invalid')
    expected = {base + (f':{call_id}' if hook['eventName'] == 'preToolUse' else ''): hook
                for base, hook in registered.items()}
    if set(hooks_started) != set(expected) or set(hooks_completed) != set(expected):
        _fail('model_hook_execution_incomplete')
    prompt_completed = None
    pretool_starts = []
    for identifier, hook in expected.items():
        started_index, start = hooks_started[identifier]
        completed_index, end = hooks_completed[identifier]
        if not started_index < completed_index < command_start_index:
            _fail('model_hook_order_invalid')
        for field in ('sourcePath', 'source', 'eventName', 'displayOrder'):
            if start.get(field) != hook.get(field) or end.get(field) != hook.get(field):
                _fail('model_hook_binding_invalid')
        if hook['eventName'] == 'userPromptSubmit':
            prompt_completed = completed_index
        else:
            pretool_starts.append(started_index)
    if prompt_completed is None or any(index <= prompt_completed for index in pretool_starts):
        _fail('model_hook_order_invalid')
    summary_items = turn_completed[1].get('items', [])
    if not isinstance(summary_items, list):
        _fail('model_turn_invalid')
    summary_commands = 0
    for item in summary_items:
        if not isinstance(item, dict) or not isinstance(item.get('type'), str):
            _fail('model_turn_invalid')
        if item.get('type') in TEXT_ITEMS:
            continue
        if item.get('type') != 'commandExecution' or item.get('id') != call_id:
            _fail('unexpected_model_tool')
        summary_commands += 1
        _command(item, command, project, True, sentinel)
    if summary_commands > 1:
        _fail('unexpected_model_tool')
    return {'passed': True, 'execution_verified': True, 'hook_runs_completed': 3,
            'user_prompt_submit_verified': True, 'pre_tool_use_verified': True,
            'command_count': 1, 'command_output_verified': True, 'turn_completed': True,
            'permission_request_exercised': False}
