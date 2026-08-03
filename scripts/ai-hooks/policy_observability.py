from __future__ import annotations

import json
import os
import re
import sys
from datetime import UTC, datetime
from pathlib import Path


REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_TRACE_FILE = REPOSITORY_ROOT / "storage" / "logs" / "ai-hooks.jsonl"


def safe_label(value: str, fallback: str) -> str:
    normalized = re.sub(r"[^A-Za-z0-9_.:/-]+", "_", value.strip())[:80]

    return normalized or fallback


def emit_hook_trace(
    *,
    hook: str,
    agent: str,
    event: str,
    tool: str,
    decision: str,
    code: str | None,
) -> None:
    record = {
        "timestamp": datetime.now(UTC).isoformat(),
        "hook": safe_label(hook, "unknown"),
        "agent": safe_label(agent, "unknown"),
        "event": safe_label(event, "unknown"),
        "tool": safe_label(tool, "none"),
        "decision": safe_label(decision, "unknown"),
        "code": safe_label(code or "none", "none"),
    }
    print(
        "ArtifactFlow AI Called: "
        f"{record['hook']} Output: {record['decision']} Agent: {record['agent']} "
        f"Event: {record['event']} Tool: {record['tool']} Code: {record['code']}",
        file=sys.stderr,
    )

    configured_path = os.environ.get("ARTIFACTFLOW_AI_HOOK_TRACE_FILE")
    trace_path = Path(configured_path) if configured_path else DEFAULT_TRACE_FILE
    try:
        trace_path.parent.mkdir(parents=True, exist_ok=True)
        with trace_path.open("a", encoding="utf-8") as trace_file:
            trace_file.write(json.dumps(record, sort_keys=True) + "\n")
    except OSError:
        print("ArtifactFlow AI Hook Trace: local JSONL output unavailable.", file=sys.stderr)
