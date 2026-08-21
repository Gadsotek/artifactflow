#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import sys

from policy import event_name, extract_command, load_event, scan_command, strongest_finding
from policy_observability import emit_hook_trace


CODEX_EXECPOLICY_PROMPT_CODES = frozenset({
    "docker_compose_down_volumes",
    "docker_prune",
    "docker_volume_delete",
    "git_checkout_discard",
    "git_clean",
    "git_push",
    "git_reset_hard",
    "git_restore",
    "make_reset",
    "recursive_rm",
})


def emit_claude_decision(event: str, action: str, reason: str) -> int:
    if event == "PermissionRequest":
        if action == "deny":
            print(json.dumps({
                "hookSpecificOutput": {
                    "hookEventName": event,
                    "decision": {
                        "behavior": "deny",
                        "message": reason,
                    },
                },
            }))
        return 0

    print(json.dumps({
        "hookSpecificOutput": {
            "hookEventName": event,
            "permissionDecision": "deny" if action == "deny" else "ask",
            "permissionDecisionReason": reason,
        },
    }))
    return 0


def emit_codex_decision(action: str, code: str, reason: str) -> int:
    if action == "deny":
        print(reason, file=sys.stderr)
        return 2

    if code in CODEX_EXECPOLICY_PROMPT_CODES:
        return 0

    print(
        f"{reason} Codex PreToolUse cannot request approval for this action, so it is blocked.",
        file=sys.stderr,
    )
    return 2


def main() -> int:
    parser = argparse.ArgumentParser(description="Guard AI shell commands.")
    parser.add_argument("--agent", choices=("claude", "codex"), required=True)
    parser.add_argument("--event", default="PreToolUse")
    args = parser.parse_args()

    event = load_event()
    command = extract_command(event)
    finding = strongest_finding(scan_command(command))
    hook_event = event_name(event, args.event)
    raw_tool_name = event.get("tool_name")
    tool_name = raw_tool_name if isinstance(raw_tool_name, str) else "Bash"
    decision = finding.action if finding is not None else "allow"
    if args.agent == "codex" and finding is not None and finding.action == "ask":
        decision = "delegate" if finding.code in CODEX_EXECPOLICY_PROMPT_CODES else "deny"

    emit_hook_trace(
        hook="guard_command.py",
        agent=args.agent,
        event=hook_event,
        tool=tool_name,
        decision=decision,
        code=finding.code if finding is not None else None,
    )
    if finding is None:
        return 0

    if args.agent == "claude":
        return emit_claude_decision(hook_event, finding.action, finding.reason)

    return emit_codex_decision(finding.action, finding.code, finding.reason)


if __name__ == "__main__":
    raise SystemExit(main())
