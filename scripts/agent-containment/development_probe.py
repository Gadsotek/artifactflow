#!/usr/bin/env python3
"""Run reviewed development checks inside a native sandbox on owned fixtures.

The caller creates and verifies fixtures before launch. This program neither
creates a sandbox nor retries without one, and never prints subprocess output.
"""
from __future__ import annotations

import argparse
import errno
import hashlib
import importlib.util
import json
import os
import re
import shutil
import signal
import subprocess
import sys
from pathlib import Path

# The native probe runs with -I, so load only this reviewed adjacent module;
# do not add the workspace or environment-controlled paths to sys.path.
_cleanup_spec = importlib.util.spec_from_file_location(
    "artifactflow_process_cleanup", Path(__file__).resolve().with_name("process_cleanup.py"),
)
if _cleanup_spec is None or _cleanup_spec.loader is None:
    raise ImportError("Owned process cleanup helper is unavailable")
_cleanup_module = importlib.util.module_from_spec(_cleanup_spec)
_cleanup_spec.loader.exec_module(_cleanup_module)
stop_owned_process_group = _cleanup_module.stop_owned_process_group


PROTOCOL = "artifactflow-development-v3"
WORKSPACE_PROTOCOL = "artifactflow-workspace-development-v3"
PREFIX = "ARTIFACTFLOW_DEVELOPMENT_RECEIPT "
CHILD_PROTOCOL = "artifactflow-development-child-v1"
CHILD_PREFIX = "ARTIFACTFLOW_DEVELOPMENT_CHILD "
SENTINEL = b"artifactflow-owned-development-sentinel\n"
VENDOR_SOURCE = "vendor/laravel/framework/src/Illuminate/Support/Str.php"
CHECKS = {
    "project README read": "allow", "vendor source read": "allow",
    "owned write": "allow", "owned read": "allow", "owned rename": "allow",
    "outside direct read": "deny", "outside child read": "deny",
    "outside grandchild read": "deny", "outside symlink read": "deny",
    "git version": "command", "search tool version": "command", "make version": "command",
    "python3 version": "command", "git status": "command", "owned sentinel search": "command",
    "shasum owned SHA256": "command", "shasum owned manifest": "command",
    "make publish-guard": "command", "make ai-hooks-test": "command",
}
WORKSPACE_CHECKS = {name: kind for name, kind in CHECKS.items() if kind != "deny"}
COMMAND_DIAGNOSTICS = {"developer_tools_unavailable", "developer_tools_license_required",
                       "system_access_denied", "command_missing", "command_timeout",
                       "unclassified_command_error"}
SEARCH_CHECKS = {"search tool version", "owned sentinel search"}
SEARCH_VERSION_SIGNATURES = {"rg": ("ripgrep ",), "grep": ("grep (BSD grep, GNU compatible) ", "grep (GNU grep) ")}
SIGNATURES = {"git version": "git version ",
              "make version": "GNU Make ", "python3 version": "Python 3.",
              "owned sentinel search": SENTINEL.decode().strip()}


def successful_check(check: object) -> bool:
    if not isinstance(check, dict) or not isinstance(check.get("name"), str):
        return False
    name = check["name"]
    if name not in CHECKS or check.get("passed") is not True:
        return False
    if CHECKS[name] == "command":
        keys = {"name", "passed", "category", "returncode", "signature_valid", "git_access_warning"}
        if name in SEARCH_CHECKS:
            if not isinstance(check.get("tool"), str) or check["tool"] not in SEARCH_VERSION_SIGNATURES:
                return False
            keys.add("tool")
        return (set(check) == keys
                and check["category"] == "command_completed" and type(check["returncode"]) is int
                and check["returncode"] == 0 and check["signature_valid"] is True
                and check["git_access_warning"] is False)
    if set(check) != {"name", "passed", "category", "operation", "success", "errno"}:
        return False
    operation = "write" if name == "owned write" else "rename" if name == "owned rename" else "read"
    if check["operation"] != operation or type(check["success"]) is not bool:
        return False
    if CHECKS[name] == "deny":
        return (check["category"] == "os_access_denied" and not check["success"]
                and type(check["errno"]) is int and check["errno"] in (errno.EACCES, errno.EPERM))
    return check["category"] == "access_allowed" and check["success"] and check["errno"] is None


def validate_receipt(payload: object, nonce: str, *, workspace_only: bool = False) -> bool:
    """Accept complete successful typed evidence only, never narrative claims."""
    if not isinstance(payload, dict) or set(payload) != {"protocol", "nonce", "checks"}:
        return False
    expected = WORKSPACE_CHECKS if workspace_only else CHECKS
    protocol = WORKSPACE_PROTOCOL if workspace_only else PROTOCOL
    if payload["protocol"] != protocol or not nonce or payload["nonce"] != nonce:
        return False
    checks = payload["checks"]
    if not isinstance(checks, list) or len(checks) != len(expected):
        return False
    seen = set()
    search_tools = set()
    for check in checks:
        if not successful_check(check):
            return False
        name = check["name"]
        if name in seen:
            return False
        seen.add(name)
        if name in SEARCH_CHECKS:
            search_tools.add(check["tool"])
    return seen == set(expected) and len(search_tools) == 1


def summarize_receipt(payload: object, nonce: str, *, workspace_only: bool = False) -> dict:
    """Project only fixed labels and validated status; never copy arbitrary data."""
    supplied = []
    expected = WORKSPACE_CHECKS if workspace_only else CHECKS
    protocol = WORKSPACE_PROTOCOL if workspace_only else PROTOCOL
    if isinstance(payload, dict) and set(payload) == {"protocol", "nonce", "checks"} \
            and payload["protocol"] == protocol and nonce and payload["nonce"] == nonce \
            and isinstance(payload["checks"], list):
        supplied = payload["checks"]
    projected = []
    failure_categories = {"command_unavailable", "command_failed", "fixture_invalid", "unexpected_access_result"}
    for name in expected:
        matches = [item for item in supplied if isinstance(item, dict) and item.get("name") == name]
        check = matches[0] if len(matches) == 1 else {}
        passed = successful_check(check)
        category = check.get("category")
        if not passed and not (check.get("passed") is False and isinstance(category, str) and category in failure_categories):
            category = "invalid_receipt"
        item = {"name": name, "passed": passed, "category": category}
        tool = check.get("tool") if name in SEARCH_CHECKS else name.split()[0]
        if name in SEARCH_CHECKS and isinstance(tool, str) and tool in SEARCH_VERSION_SIGNATURES:
            item["tool"] = tool
        if not passed and category in {"command_failed", "command_unavailable"} and expected[name] == "command":
            if type(check.get("returncode")) is int:
                item["returncode"] = check["returncode"]
            if type(check.get("signature_valid")) is bool:
                item["signature_valid"] = check["signature_valid"]
            if type(check.get("git_access_warning")) is bool:
                item["git_access_warning"] = check["git_access_warning"]
            if type(check.get("errno")) is int:
                item["errno"] = check["errno"]
            diagnostic = check.get("diagnostic")
            if isinstance(diagnostic, str) and diagnostic in COMMAND_DIAGNOSTICS:
                item["diagnostic"] = diagnostic
            executable = safe_tool_executable(check.get("executable"), tool)
            if executable is not None:
                item["executable"] = executable
        projected.append(item)
    return {"passed": validate_receipt(payload, nonce, workspace_only=workspace_only), "checks": projected}


def failed_access(error: BaseException) -> dict:
    if isinstance(error, OSError) and type(error.errno) is int:
        return {"success": False, "errno": error.errno}
    return {"success": False, "errno": None, "error_type": type(error).__name__}


def read_access(path: Path) -> dict:
    try:
        with path.open("rb") as source:
            source.read(1)
        return {"success": True, "errno": None}
    except OSError as error:
        return failed_access(error)


def owned_read(path: Path) -> dict:
    try:
        if path.read_bytes() != SENTINEL:
            raise ValueError("owned sentinel mismatch")
        return {"success": True, "errno": None}
    except (OSError, ValueError) as error:
        return failed_access(error)


def owned_write(path: Path) -> dict:
    try:
        if path.read_bytes() != SENTINEL:
            raise ValueError("owned sentinel mismatch")
        path.write_bytes(SENTINEL)
        return {"success": True, "errno": None}
    except (OSError, ValueError) as error:
        return failed_access(error)


def owned_rename(path: Path, destination: Path) -> dict:
    try:
        if path.read_bytes() != SENTINEL or destination.exists() or destination.is_symlink():
            raise ValueError("owned rename precondition failed")
        path.rename(destination)
        destination.rename(path)
        return {"success": True, "errno": None}
    except (OSError, ValueError) as error:
        return failed_access(error)


def verify_layout(project: Path, fixture: Path, outside: Path | None, *, workspace_only: bool = False) -> None:
    if workspace_only and outside is not None or not workspace_only and outside is None:
        raise ValueError("outside path must match the selected mode")
    paths = (project, fixture) if workspace_only else (project, fixture, outside)
    if not all(path.is_absolute() for path in paths):
        raise ValueError("absolute paths required")
    if project.resolve(strict=True) != project or fixture.resolve(strict=True) != fixture \
            or fixture == project or not fixture.is_relative_to(project) \
            or outside is not None and outside.is_relative_to(project):
        raise ValueError("fixture boundaries invalid")
    if not (project / VENDOR_SOURCE).is_file():
        raise ValueError("installed vendor source required")
    for name in ("source.txt", "edit.txt"):
        path = fixture / name
        if path.is_symlink() or path.read_bytes() != SENTINEL:
            raise ValueError("owned sentinel mismatch")
    if not workspace_only:
        alias = fixture / "outside-alias"
        if not alias.is_symlink() or alias.readlink() != outside:
            raise ValueError("owned alias mismatch")


def run_process(command: list[str], cwd: Path, timeout: int, *, stdin_text: str | None = None) -> subprocess.CompletedProcess:
    """Clean our command group on timeout or helper TERM/INT, not client groups."""
    previous_handlers = {sig: signal.getsignal(sig) for sig in (signal.SIGTERM, signal.SIGINT)}

    def stop_helper(signum: int, _frame: object) -> None:
        raise SystemExit(128 + signum)

    for sig in previous_handlers:
        signal.signal(sig, stop_helper)
    try:
        process = subprocess.Popen(command, cwd=cwd, stdin=subprocess.PIPE if stdin_text is not None else subprocess.DEVNULL,
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                   text=True, start_new_session=True)

        try:
            stdout, stderr = process.communicate(input=stdin_text, timeout=timeout)
            return subprocess.CompletedProcess(command, process.returncode, stdout, stderr)
        finally:
            # A repeated graceful stop must not remove the cleanup watchdog.
            for sig in previous_handlers:
                signal.signal(sig, signal.SIG_IGN)
            try:
                stop_owned_process_group(process)
            finally:
                for stream in (process.stdin, process.stdout, process.stderr):
                    if stream is not None:
                        stream.close()
    finally:
        for sig, handler in previous_handlers.items():
            signal.signal(sig, handler)


def safe_tool_executable(value: object, tool: str) -> str | None:
    """Allow only bounded installation metadata for the reviewed tools."""
    if not isinstance(tool, str) or tool not in {"git", "rg", "grep", "make", "python3", "shasum"} or not isinstance(value, str) \
            or len(value) > 1024 or any(ord(char) < 32 for char in value):
        return None
    path = Path(value)
    roots = ("/usr/bin/", "/bin/", "/usr/local/", "/opt/homebrew/", "/Library/Developer/",
             "/Library/Frameworks/Python.framework/", "/Applications/")
    if not path.is_absolute() or ".." in path.parts or not value.startswith(roots):
        return None
    valid_name = bool(re.fullmatch(r"python3(?:\.\d+)*", path.name)) if tool == "python3" else path.name == tool
    return value if valid_name else None


def resolved_tool_executable(tool: str) -> str | None:
    name = Path(tool).name
    if name not in {"git", "rg", "grep", "make", "python3", "shasum"}:
        return None
    try:
        found = shutil.which(tool)
        return safe_tool_executable(str(Path(found).resolve(strict=True)), name) if found else None
    except (OSError, RuntimeError):
        return None


def command_diagnostic(stderr: str) -> str:
    text = stderr.lower()
    if ("xcrun:" in text and ("invalid active developer path" in text or "unable to find utility" in text)) \
            or ("xcode-select:" in text and ("invalid developer directory" in text
                                             or "no developer tools were found" in text)):
        return "developer_tools_unavailable"
    if "xcode" in text and "license" in text and "agree" in text:
        return "developer_tools_license_required"
    if "operation not permitted" in text or "permission denied" in text:
        return "system_access_denied"
    return "unclassified_command_error"


def git_access_warning(stderr: str) -> bool:
    """Recognize Git's ignore-load warning, not plain protected-file errors.

    Git can exit zero after failing to load a global exclusions file. The
    ordinary status probe also prints plain errors for intentionally denied
    environment templates; their policy remains unchanged and is not tested by
    this diagnostic. Do not copy the warning's path into the receipt.
    """
    return re.search(r"^warning: unable to access '[^\r\n]+': (?:Operation not permitted|Permission denied)$",
                     stderr, re.MULTILINE) is not None


def command_result(name: str, command: list[str], cwd: Path, timeout: int = 15, *, tool: str | None = None,
                   expected_stdout: str | None = None, stdin_text: str | None = None) -> dict:
    executable = resolved_tool_executable(command[0])
    metadata = {"executable": executable} if executable is not None else {}
    selection = {"tool": tool} if name in SEARCH_CHECKS else {}
    try:
        result = run_process(command, cwd, timeout, stdin_text=stdin_text)
    except (OSError, RuntimeError, subprocess.TimeoutExpired) as error:
        if isinstance(error, OSError) and type(error.errno) is int:
            metadata["errno"] = error.errno
        diagnostic = "command_timeout" if isinstance(error, subprocess.TimeoutExpired) else \
            "command_missing" if isinstance(error, FileNotFoundError) else \
            "system_access_denied" if isinstance(error, PermissionError) else "unclassified_command_error"
        return {"name": name, "passed": False, "category": "command_unavailable",
                "error_type": type(error).__name__, "diagnostic": diagnostic, **metadata, **selection}
    signature = SIGNATURES.get(name)
    signature_valid = signature is None or signature in result.stdout
    if name == "search tool version":
        signature_valid = isinstance(tool, str) and any(result.stdout.startswith(value) for value in SEARCH_VERSION_SIGNATURES.get(tool, ()))
    if expected_stdout is not None:
        signature_valid = result.stdout == expected_stdout
    warning = git_access_warning(result.stderr)
    passed = result.returncode == 0 and signature_valid and not warning
    proof = {"name": name, "passed": passed,
             "category": "command_completed" if passed else "command_failed",
             "returncode": result.returncode, "signature_valid": signature_valid,
             "git_access_warning": warning, **selection}
    if not passed:
        proof.update({"diagnostic": command_diagnostic(result.stderr), **metadata})
    return proof


def checksum_checks(fixture: Path) -> list[dict]:
    """Hash only the validated owned source with the actual macOS system tool.

    The fixed manifest on stdin names only source.txt in the fixture directory;
    no caller-supplied manifest, new file, outside target, or fallback is used.
    """
    manifest = hashlib.sha256(SENTINEL).hexdigest() + "  source.txt\n"
    return [command_result("shasum owned SHA256", ["/usr/bin/shasum", "-a", "256", "source.txt"],
                           fixture, expected_stdout=manifest),
            command_result("shasum owned manifest", ["/usr/bin/shasum", "-a", "256", "-c", "-"],
                           fixture, expected_stdout="source.txt: OK\n", stdin_text=manifest)]


def search_checks(project: Path, fixture: Path) -> list[dict]:
    """Choose before launch; a failed chosen command never triggers a retry."""
    binary = shutil.which("rg")
    tool = "rg"
    if binary is None:
        binary = shutil.which("/usr/bin/grep")
        tool = "grep"
    if binary is None:
        return [{"name": name, "passed": False, "category": "command_unavailable",
                 "diagnostic": "command_missing", "tool": tool} for name in ("search tool version", "owned sentinel search")]
    flag = "--fixed-strings" if tool == "rg" else "-F"
    return [command_result("search tool version", [binary, "--version"], project, tool=tool),
            command_result("owned sentinel search", [binary, flag, "--", SENTINEL.decode().strip(), str(fixture / "source.txt")], project, tool=tool)]


def run_child_read(path: Path, nonce: str, depth: int) -> dict:
    if depth == 0:
        return read_access(path)
    try:
        command = [str(Path(sys.executable).resolve(strict=True)), "-I", "-B",
                   str(Path(__file__).resolve(strict=True)), "--child-read", str(path),
                   "--nonce", nonce, "--depth", str(depth - 1)]
        result = run_process(command, Path.cwd(), 15)
        if result.returncode != 0 or not result.stdout.startswith(CHILD_PREFIX):
            raise ValueError("child did not return proof")
        payload = json.loads(result.stdout[len(CHILD_PREFIX):])
        if not isinstance(payload, dict) or set(payload) != {"protocol", "nonce", "depth", "operation", "success", "errno"} \
                or payload["protocol"] != CHILD_PROTOCOL or payload["nonce"] != nonce \
                or type(payload["depth"]) is not int or payload["depth"] != depth - 1 \
                or payload["operation"] != "read" or type(payload["success"]) is not bool \
                or (payload["success"] and payload["errno"] is not None) \
                or (not payload["success"] and type(payload["errno"]) is not int):
            raise ValueError("invalid child proof")
        return {"success": payload["success"], "errno": payload["errno"]}
    except (OSError, RuntimeError, ValueError, subprocess.TimeoutExpired) as error:
        return {"success": False, "errno": None, "error_type": type(error).__name__}


def access_check(name: str, proof: dict) -> dict:
    denied = CHECKS[name] == "deny"
    passed = (proof.get("success") is False and type(proof.get("errno")) is int
              and proof["errno"] in (errno.EACCES, errno.EPERM)) if denied else (
        proof.get("success") is True and proof.get("errno") is None)
    operation = "write" if name == "owned write" else "rename" if name == "owned rename" else "read"
    category = ("os_access_denied" if denied else "access_allowed") if passed else "unexpected_access_result"
    return {"name": name, "passed": passed, "category": category, "operation": operation, **proof}


def run_checks(project: Path, fixture: Path, outside: Path | None, nonce: str, *, workspace_only: bool = False) -> dict:
    expected = WORKSPACE_CHECKS if workspace_only else CHECKS
    receipt = {"protocol": WORKSPACE_PROTOCOL if workspace_only else PROTOCOL, "nonce": nonce, "checks": []}
    try:
        verify_layout(project, fixture, outside, workspace_only=workspace_only)
    except (OSError, ValueError) as error:
        receipt["checks"] = [{"name": name, "passed": False, "category": "fixture_invalid",
                              "error_type": type(error).__name__} for name in expected]
        return receipt
    edit = fixture / "edit.txt"
    checks = receipt["checks"]
    checks.append(access_check("project README read", read_access(project / "README.md")))
    checks.append(access_check("vendor source read", read_access(project / VENDOR_SOURCE)))
    checks.append(access_check("owned write", owned_write(edit)))
    checks.append(access_check("owned read", owned_read(edit)))
    checks.append(access_check("owned rename", owned_rename(edit, fixture / "renamed-edit.txt")))
    if not workspace_only:
        for name, depth in (("outside direct read", 0), ("outside child read", 1), ("outside grandchild read", 2)):
            checks.append(access_check(name, run_child_read(outside, nonce, depth)))
        checks.append(access_check("outside symlink read", read_access(fixture / "outside-alias")))
    for tool in ("git", "make", "python3"):
        checks.append(command_result(f"{tool} version", [tool, "--version"], project))
    checks.append(command_result("git status", ["git", "--no-optional-locks", "status", "--short", "--untracked-files=no"], project, 30))
    checks.extend(search_checks(project, fixture))
    checks.extend(checksum_checks(fixture))
    checks.append(command_result("make publish-guard", ["make", "publish-guard"], project, 30))
    checks.append(command_result("make ai-hooks-test", ["make", "ai-hooks-test"], project, 120))
    return receipt


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--project", type=Path)
    parser.add_argument("--fixture", type=Path)
    parser.add_argument("--outside", type=Path)
    parser.add_argument("--workspace-only", action="store_true")
    parser.add_argument("--nonce", required=True)
    parser.add_argument("--child-read", type=Path)
    parser.add_argument("--depth", type=int, choices=(0, 1, 2), default=0)
    args = parser.parse_args(argv)
    if not args.nonce:
        parser.error("nonce cannot be empty")
    if args.workspace_only and (args.outside is not None or args.child_read is not None):
        parser.error("workspace-only mode does not accept outside or child paths")
    if args.child_read is not None:
        proof = run_child_read(args.child_read, args.nonce, args.depth)
        payload = {"protocol": CHILD_PROTOCOL, "nonce": args.nonce, "depth": args.depth,
                   "operation": "read", **proof}
        print(CHILD_PREFIX + json.dumps(payload, sort_keys=True))
        return 0
    if args.project is None or args.fixture is None or not args.workspace_only and args.outside is None:
        parser.error("project, fixture, and outside paths are required")
    receipt = run_checks(args.project, args.fixture, args.outside, args.nonce, workspace_only=args.workspace_only)
    print(PREFIX + json.dumps(receipt, sort_keys=True))
    return 0 if validate_receipt(receipt, args.nonce, workspace_only=args.workspace_only) else 1


if __name__ == "__main__":
    raise SystemExit(main())
