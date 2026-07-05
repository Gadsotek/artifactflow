#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import sys

from policy import extract_prompt, load_event, scan_prompt, strongest_finding


def emit_claude_block(reason: str) -> int:
    print(json.dumps({
        "decision": "block",
        "reason": reason,
    }))
    return 0


def emit_codex_block(reason: str) -> int:
    print(reason, file=sys.stderr)
    return 2


def main() -> int:
    parser = argparse.ArgumentParser(description="Guard AI prompts for accidental secret paste.")
    parser.add_argument("--agent", choices=("claude", "codex"), required=True)
    args = parser.parse_args()

    finding = strongest_finding(scan_prompt(extract_prompt(load_event())))
    if finding is None:
        return 0

    if args.agent == "claude":
        return emit_claude_block(finding.reason)

    return emit_codex_block(finding.reason)


if __name__ == "__main__":
    raise SystemExit(main())
