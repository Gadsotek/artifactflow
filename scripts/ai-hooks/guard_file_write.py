#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import sys

from policy import event_name, extract_file_path, load_event, scan_file_write, strongest_finding


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
    finding = strongest_finding(scan_file_write(extract_file_path(event)))
    if finding is None:
        return 0

    if args.agent == "claude":
        return emit_claude_decision(event_name(event, args.event), finding.reason)

    return emit_codex_decision(finding.reason)


if __name__ == "__main__":
    raise SystemExit(main())
