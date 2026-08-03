#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import sys

from policy import (
    Finding,
    event_name,
    extract_file_paths,
    load_event,
    normalize_tool_name,
    scan_file_write,
    strongest_finding,
    tool_requires_known_file_target,
)
from policy_observability import emit_hook_trace


def emit_claude_decision(event: str, action: str, reason: str) -> int:
    print(json.dumps({
        "hookSpecificOutput": {
            "hookEventName": event,
            "permissionDecision": "deny" if action == "deny" else "ask",
            "permissionDecisionReason": reason,
        },
    }))
    return 0


def emit_codex_decision(action: str, reason: str) -> int:
    if action == "deny":
        print(reason, file=sys.stderr)
        return 2

    print(json.dumps({
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "permissionDecision": "ask",
            "permissionDecisionReason": reason,
        },
    }))
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Guard AI file writes.")
    parser.add_argument("--agent", choices=("claude", "codex"), required=True)
    parser.add_argument("--event", default="PreToolUse")
    args = parser.parse_args()

    event = load_event()
    raw_tool_name = event.get("tool_name")
    tool_name = normalize_tool_name(raw_tool_name) if isinstance(raw_tool_name, str) else ""
    if tool_name == "functions_exec":
        finding = Finding(
            code="composite_tool_execution",
            action="deny",
            reason=(
                "Refusing the functions.exec composite tool because nested operations are not "
                "reliably visible to project hooks. Use native hook-visible tools instead."
            ),
        )
    else:
        paths = extract_file_paths(event)
        findings = [finding for path in paths for finding in scan_file_write(path)]
        if tool_requires_known_file_target(tool_name) and not paths:
            findings.append(Finding(
                code="unknown_write_target",
                action="deny",
                reason="Refusing a mutating file tool whose target cannot be determined.",
            ))
        finding = strongest_finding(findings)

    hook_event = event_name(event, args.event)
    emit_hook_trace(
        hook="guard_file_write.py",
        agent=args.agent,
        event=hook_event,
        tool=tool_name or "unknown",
        decision=finding.action if finding is not None else "allow",
        code=finding.code if finding is not None else None,
    )
    if finding is None:
        return 0

    if args.agent == "claude":
        return emit_claude_decision(hook_event, finding.action, finding.reason)

    return emit_codex_decision(finding.action, finding.reason)


if __name__ == "__main__":
    raise SystemExit(main())
