from __future__ import annotations

from policy_commands import scan_command
from policy_events import (
    extract_command,
    extract_file_paths,
    extract_prompt,
    load_event,
    normalize_tool_name,
    tool_requires_known_file_target,
)
from policy_files import file_write_findings
from policy_prompts import scan_prompt
from policy_types import Finding


def scan_file_write(path: str) -> list[Finding]:
    return file_write_findings(path)


def strongest_finding(findings: list[Finding]) -> Finding | None:
    if not findings:
        return None

    for finding in findings:
        if finding.action == "deny":
            return finding

    return findings[0]


def event_name(event: dict[str, object], fallback: str) -> str:
    value = event.get("hook_event_name")
    if isinstance(value, str) and value:
        return value

    return fallback
