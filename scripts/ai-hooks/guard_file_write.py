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
)


def emit_claude_decision(event: str, reason: str) -> int:
    print(json.dumps({
        "hookSpecificOutput": {
            "hookEventName": event,
            "permissionDecision": "deny",
            "permissionDecisionReason": reason,
        },
    }))
    return 0


def emit_codex_decision(reason: str) -> int:
    print(reason, file=sys.stderr)
    return 2


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
        if tool_name == "apply_patch" and not paths:
            findings.append(Finding(
                code="unknown_patch_target",
                action="deny",
                reason="Refusing an apply_patch operation whose file target cannot be determined.",
            ))
        finding = strongest_finding(findings)

    if finding is None:
        return 0

    if args.agent == "claude":
        return emit_claude_decision(event_name(event, args.event), finding.reason)

    return emit_codex_decision(finding.reason)


if __name__ == "__main__":
    raise SystemExit(main())
