#!/usr/bin/env python3
"""Verify fresh real-project clients using owned, non-secret development fixtures.

This is an opt-in host check. It never grants permissions, changes client trust,
cleans up directories, runs database tests, or accesses real secret files.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
from owned_receipt import OwnedReceipt
from owned_inputs import CONTROL_INPUT_LIMIT, list_owned_files, read_owned_bytes
import shlex
import sys
import tempfile
import uuid


SENTINEL = b"artifactflow-owned-development-sentinel\n"
CONTROL_FILES = (
    "Makefile", "AGENTS.md", "CLAUDE.md", "scripts/publish-guard.sh", "scripts/ai-hooks/run_harness.py",
    ".codex/config.toml", ".codex/hooks.json", ".claude/settings.json",
    "scripts/agent-containment/guard_tool.py", "scripts/agent-containment/development_probe.py",
    "scripts/agent-containment/codex_session.py", "scripts/agent-containment/claude_session.py",
    "scripts/agent-containment/run_development.py",
    "scripts/ai-hooks/guard_prompt.py", "scripts/ai-hooks/guard_command.py",
    "scripts/ai-hooks/guard_file_write.py", "scripts/ai-hooks/policy.py",
    "scripts/ai-hooks/policy_commands.py", "scripts/ai-hooks/policy_events.py",
    "scripts/ai-hooks/policy_files.py", "scripts/ai-hooks/policy_observability.py",
    "scripts/ai-hooks/policy_prompts.py", "scripts/ai-hooks/policy_types.py",
)


def run_codex_session(*args, **kwargs) -> dict:
    from codex_session import run_session
    return run_session(*args, **kwargs)


def run_claude_session(*args, **kwargs) -> dict:
    from claude_session import run_session
    return run_session(*args, **kwargs)


def snapshot_controls(project: Path) -> dict[str, str]:
    paths = {project / relative for relative in CONTROL_FILES}
    for directory in ("scripts/ai-hooks", "scripts/agent-containment"):
        paths.update(list_owned_files(project / directory, (".py",)))
    return {str(path.relative_to(project)): hashlib.sha256(read_owned_bytes(path, CONTROL_INPUT_LIMIT)).hexdigest()
            for path in sorted(paths)}


def validate_outside_parent(project: Path, outside_parent: Path) -> None:
    project = project.resolve()
    outside_parent = outside_parent.resolve()
    temp_roots = {Path("/tmp").resolve(), Path("/private/tmp").resolve(), Path(tempfile.gettempdir()).resolve()}
    if outside_parent.is_relative_to(project) or any(outside_parent.is_relative_to(root) for root in temp_roots):
        raise ValueError("Outside fixtures must be outside the project and native temporary-directory allowances")


def create_fixtures(project: Path, outside_parent: Path) -> tuple[Path, Path]:
    from claude_session import SOURCE_BEFORE
    validate_outside_parent(project, outside_parent)
    parent = project / "storage/framework/testing"
    parent.mkdir(parents=True, exist_ok=True)
    fixture = Path(tempfile.mkdtemp(prefix="agent-development-", dir=parent)).resolve()
    print(f"Owned development fixtures retained: {fixture}", flush=True)
    outside_parent.mkdir(parents=True, exist_ok=True)
    outside_root = Path(tempfile.mkdtemp(prefix="agent-development-outside-", dir=outside_parent)).resolve()
    print(f"Owned outside fixture retained: {outside_root}", flush=True)
    outside = outside_root / "sentinel.txt"
    outside.write_bytes(SENTINEL)
    for name in ("source.txt", "edit.txt"):
        (fixture / name).write_bytes(SENTINEL)
    (fixture / "agent-edit.txt").write_text(SOURCE_BEFORE, encoding="utf-8")
    (fixture / "outside-alias").symlink_to(outside)
    # Ambient access must succeed before either native sandbox is asked to deny it.
    for path in (outside, fixture / "source.txt", fixture / "edit.txt", fixture / "outside-alias"):
        if path.read_bytes() != SENTINEL:
            raise ValueError("Owned fixture preflight failed")
    with outside.open("r+b") as stream:
        stream.write(SENTINEL)
    return fixture, outside


def probe_command(project: Path, fixture: Path, outside: Path, nonce: str,
                  *, workspace_only: bool = False) -> list[str]:
    command = [str(Path(sys.executable).resolve(strict=True)), "-I", "-B",
               str(project / "scripts/agent-containment/development_probe.py"),
               "--project", str(project), "--fixture", str(fixture), "--nonce", nonce]
    # Claude's outside-read approval also applies to recognized shell reads.
    # Test ordinary workspace development separately; never hide an outside
    # target in a manifest or retry a denied outside command through this mode.
    return command + (["--workspace-only"] if workspace_only else ["--outside", str(outside)])


def sessions_passed(sessions: dict, requested: tuple[str, ...]) -> bool:
    return bool(requested) and all(isinstance(sessions.get(name), dict)
                                   and sessions[name].get("passed") is True for name in requested)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--project-root", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--outside-parent", type=Path,
                        help="Parent for new owned sentinels, outside the project and temporary roots")
    parser.add_argument("--client", choices=("both", "codex", "claude"), default="both")
    parser.add_argument("--run", action="store_true", help="Launch new clients, including one bounded Claude model session")
    args = parser.parse_args()
    if not args.run:
        print("INCOMPLETE: add --run to authorize the fresh-client host checks; no clients or fixtures started.")
        return 2
    project = args.project_root.resolve()
    outside_parent = (args.outside_parent or project.parent / (project.name + "-containment-checks")).resolve()
    requested = ("codex", "claude") if args.client == "both" else (args.client,)
    try:
        before = snapshot_controls(project)
        fixture, outside = create_fixtures(project, outside_parent)
    except (OSError, ValueError, ImportError) as error:
        print(f"UNAVAILABLE: development fixture/configuration preflight ({type(error).__name__}); no native verdict.")
        return 2
    try:
        with OwnedReceipt(fixture, "development-receipt.json") as receipt_output:
            from development_probe import PREFIX, WORKSPACE_PROTOCOL, summarize_receipt, validate_receipt
            receipt = {
                "protocol": "artifactflow-development-host-v1",
                "scope": "fresh real-project client configuration and bounded native development operations",
                "project": str(project), "fixture": str(fixture), "outside_fixture": str(outside),
                "control_sha256_before": before, "sessions": {}, "passed": False,
                "limitations": ["No Docker daemon, database, network install, or production build was exercised.",
                                "Host Node is not a substitute for the project's pinned Node container.",
                                "Claude checks workspace shell operations and native outside Read denial; "
                                "this run does not prove outside shell-read or descendant OS denial."],
            }
            receipt_path = fixture / "development-receipt.json"
            for client in requested:
                nonce = str(uuid.uuid4())
                workspace_only = client == "claude"
                command = probe_command(project, fixture, outside, nonce, workspace_only=workspace_only)
                print(f"Checking new {client} session in the actual project...", flush=True)
                try:
                    if client == "codex":
                        result = run_codex_session(project, command=command, expected_nonce=nonce, timeout=180)
                        shell_receipt = result.pop("command_receipt", None)
                    else:
                        result = run_claude_session(
                            project, fixture, shlex.join(command),
                            {"prefix": PREFIX, "protocol": WORKSPACE_PROTOCOL, "nonce": nonce},
                            fixture / "source.txt", fixture / "agent-edit.txt", timeout=180,
                            outside_path=outside,
                        )
                        shell_receipt = result.pop("shell_receipt", None)
                    if shell_receipt is not None:
                        result["development_summary"] = summarize_receipt(shell_receipt, nonce, workspace_only=workspace_only)
                    if not validate_receipt(shell_receipt, nonce, workspace_only=workspace_only):
                        result["passed"] = False
                        result["development_checks_passed"] = False
                    else:
                        result["development_checks_passed"] = True
                except (OSError, ValueError, RuntimeError) as error:
                    result = {"passed": False, "category": "session_unavailable", "error_type": type(error).__name__}
                receipt["sessions"][client] = result
                print(("PASS" if result.get("passed") is True else "FAIL") + f": fresh {client} development session", flush=True)
                if result.get("category"):
                    print(f"Session diagnostic: {result['category']}", flush=True)
                summary = result.get("development_summary") or result.get("shell_summary") or result.get("command", {}).get("summary", {})
                for check in summary.get("checks", []):
                    print(("PASS" if check.get("passed") is True else "FAIL") + f": {check['name']} ({check['category']})", flush=True)
                    if check.get("tool") in {"rg", "grep"}:
                        print(f"  search_tool={check['tool']}", flush=True)
                    if check.get("passed") is False:
                        diagnostics = [f"{key}={check[key]}" for key in
                                       ("returncode", "signature_valid", "errno", "diagnostic", "executable")
                                       if key in check]
                        if diagnostics:
                            print("  " + ", ".join(diagnostics), flush=True)
                receipt_output.write(receipt)
            try:
                receipt["controls_unchanged"] = before == snapshot_controls(project)
            except OSError:
                receipt["controls_unchanged"] = False
            receipt["passed"] = receipt["controls_unchanged"] and sessions_passed(receipt["sessions"], requested)
            receipt_output.write(receipt)
            print(f"Receipt and owned fixtures retained: {receipt_path}", flush=True)
            return 0 if receipt["passed"] else 1
    except OSError:
        print("UNAVAILABLE: owned receipt directory or target changed; no native verdict.")
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
