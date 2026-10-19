#!/usr/bin/env python3
"""Route native read tools separately from the unchanged shared write guard.

Filesystem containment is enforced by the client, not by this adapter. The
adapter preserves secret checks without treating a read as a control-file edit.
Claude searches must use sandboxed Bash instead of native Grep or Glob.
"""
from __future__ import annotations

import argparse
import json
import subprocess
import sys
from pathlib import Path


READ_TOOLS = frozenset({"read", "grep", "glob", "lsp"})


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--agent", required=True, choices=("codex", "claude"))
    parser.add_argument("--write-guard", required=True, type=Path)
    args = parser.parse_args()
    # The path is supplied by the protected hook configuration, never tool input.
    write_guard = args.write_guard.resolve(strict=True)
    sys.path.insert(0, str(write_guard.parent))
    from policy_events import normalize_tool_name
    from policy_files import is_secret_path
    from policy_observability import emit_hook_trace

    raw = sys.stdin.read()
    try:
        event = json.loads(raw)
        if not isinstance(event, dict) or not isinstance(event.get("tool_name"), str):
            raise ValueError("missing tool name")
    except (ValueError, TypeError):
        print("ArtifactFlow tool guard requires a valid tool event.", file=sys.stderr)
        return 2

    tool = normalize_tool_name(event["tool_name"])
    if tool not in READ_TOOLS:
        return subprocess.run(
            [sys.executable, str(write_guard), "--agent", args.agent],
            input=raw, text=True, check=False,
        ).returncode

    tool_input = event.get("tool_input")
    paths: list[str] = []
    if isinstance(tool_input, dict):
        paths = [
            value for name in ("file_path", "path", "filePath")
            if isinstance(value := tool_input.get(name), str) and value
        ]
    # Codex searches may omit the directory. Claude native search filtering is
    # insufficient for this policy, regardless of the selector or input shape.
    search_disabled = args.agent == "claude" and tool in {"grep", "glob"}
    missing = not paths and tool in {"read", "lsp"}
    secret = not search_disabled and any(is_secret_path(path) for path in paths)
    code = ("native_search_disabled" if search_disabled else
            "unknown_read_target" if missing else "secret_file_read" if secret else None)
    emit_hook_trace(
        hook="guard_tool.py", agent=args.agent,
        event="PreToolUse", tool=tool,
        decision="deny" if code else "allow", code=code,
    )
    if code:
        reason = (
            "Native Grep and Glob are disabled. Use sandboxed Bash for searches."
            if search_disabled else
            "Refusing a read tool whose target cannot be determined."
            if missing else "Refusing to read secret-bearing files."
        )
        if args.agent == "claude":
            print(json.dumps({"hookSpecificOutput": {
                "hookEventName": "PreToolUse", "permissionDecision": "deny",
                "permissionDecisionReason": reason,
            }}))
            return 0
        print(reason, file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
