from __future__ import annotations

import json
import re
import shlex
import sys
from typing import Any

def load_event() -> dict[str, Any]:
    raw = sys.stdin.read()
    if not raw.strip():
        return {}

    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        return {"raw": raw}

    if isinstance(data, dict):
        return data

    return {"value": data}


def get_nested(data: dict[str, Any], path: tuple[str, ...]) -> Any:
    current: Any = data
    for key in path:
        if not isinstance(current, dict) or key not in current:
            return None
        current = current[key]

    return current


def extract_command(event: dict[str, Any]) -> str:
    candidates = (
        ("tool_input", "command"),
        ("tool_input", "cmd"),
        ("input", "command"),
        ("input", "cmd"),
        ("parameters", "command"),
        ("parameters", "cmd"),
        ("command",),
        ("cmd",),
    )

    for path in candidates:
        value = get_nested(event, path)
        if isinstance(value, str):
            return value
        if isinstance(value, list):
            return " ".join(shlex.quote(str(part)) for part in value)

    return ""


def extract_prompt(event: dict[str, Any]) -> str:
    candidates = (
        ("prompt",),
        ("user_prompt",),
        ("message",),
        ("input", "prompt"),
        ("input", "message"),
        ("tool_input", "prompt"),
    )

    for path in candidates:
        value = get_nested(event, path)
        if isinstance(value, str):
            return value

    raw = event.get("raw")
    return raw if isinstance(raw, str) else ""


def extract_file_paths(event: dict[str, Any]) -> list[str]:
    candidates: tuple[tuple[str, ...], ...] = (
        ("tool_input", "file_path"),
        ("tool_input", "path"),
        ("tool_input", "filename"),
        ("input", "file_path"),
        ("input", "path"),
        ("parameters", "file_path"),
        ("parameters", "path"),
        ("file_path",),
        ("path",),
    )

    raw_tool_name = event.get("tool_name")
    if isinstance(raw_tool_name, str) and tool_requires_known_file_target(raw_tool_name):
        candidates += tuple(
            (container, key) if container else (key,)
            for container in ("tool_input", "input", "parameters", "")
            for key in ("target", "destination", "new_path", "paths", "files")
        )

    paths: list[str] = []
    for path in candidates:
        value = get_nested(event, path)
        if isinstance(value, str) and value not in paths:
            paths.append(value)
        if isinstance(value, list):
            for candidate in value:
                if isinstance(candidate, str) and candidate not in paths:
                    paths.append(candidate)

    tool_name = event.get("tool_name")
    if isinstance(tool_name, str) and normalize_tool_name(tool_name) == "apply_patch":
        for path in extract_apply_patch_paths(extract_patch_payload(event)):
            if path not in paths:
                paths.append(path)

    return paths


def tool_requires_known_file_target(tool_name: str) -> bool:
    return normalize_tool_name(tool_name) in {
        "apply_patch",
        "create_file",
        "delete_file",
        "edit",
        "edit_file",
        "move_file",
        "multi_edit",
        "notebook_edit",
        "update_file",
        "write",
        "write_file",
    }


def normalize_tool_name(tool_name: str) -> str:
    snake_case = re.sub(r"(?<=[a-z0-9])(?=[A-Z])", "_", tool_name.strip())

    return re.sub(r"[./:_]+", "_", snake_case.lower())


def extract_patch_payload(event: dict[str, Any]) -> str:
    candidates = (
        ("tool_input", "patch"),
        ("tool_input", "command"),
        ("tool_input", "input"),
        ("input", "patch"),
        ("input", "command"),
        ("parameters", "patch"),
        ("parameters", "command"),
        ("patch",),
        ("command",),
    )

    for path in candidates:
        value = get_nested(event, path)
        if isinstance(value, str):
            return value

    return ""


def extract_apply_patch_paths(patch: str) -> list[str]:
    target_prefixes = (
        "*** Add File: ",
        "*** Update File: ",
        "*** Delete File: ",
        "*** Move to: ",
    )
    paths: list[str] = []

    for line in patch.splitlines():
        for prefix in target_prefixes:
            if not line.startswith(prefix):
                continue

            path = line[len(prefix):].strip()
            if path and path not in paths:
                paths.append(path)
            break

    return paths
