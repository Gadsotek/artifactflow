#!/usr/bin/env python3
"""Probe a candidate Codex profile on owned fixtures; never audit a live session.

No model, credentials, global configuration parsing, or automatic elevation is used.
Fixtures and a receipt are deliberately retained. The caller removes them manually.
"""
from __future__ import annotations

import argparse
import copy
import errno
import hashlib
import json
import os
from pathlib import Path
from owned_receipt import OwnedReceipt
import shutil
import subprocess
import sys
import tempfile
import tomllib
import uuid

from process_cleanup import stop_owned_process_group


PROTOCOL = "artifactflow-containment-v1"
PROBE_SOURCE = r'''from __future__ import annotations
import errno
import json
import os
from pathlib import Path
import subprocess
import sys

nonce, operation, path, destination, depth_text = sys.argv[1:]
depth = int(depth_text)
if depth:
    result = subprocess.run(
        [sys.executable, "-I", "-B", __file__, nonce, operation, path,
         destination, str(depth - 1)],
        capture_output=True, text=True, check=False,
    )
    sys.stdout.write(result.stdout)
    sys.stderr.write(result.stderr)
    raise SystemExit(result.returncode)

result = {"protocol": "artifactflow-containment-v1", "nonce": nonce,
          "operation": operation, "success": False, "errno": None}
try:
    if operation == "read":
        with open(path, "rb") as stream:
            # Only an owned synthetic fixture; never print its contents.
            if stream.read() != b"artifactflow-owned-sentinel\n":
                raise RuntimeError("Synthetic fixture content mismatch")
    elif operation == "write":
        with open(path, "wb") as stream:
            stream.write(b"artifactflow-owned-probe-write\n")
    elif operation == "rename":
        os.rename(path, destination)
    else:
        raise RuntimeError("Unknown fixture operation")
    result["success"] = True
except OSError as error:
    result["errno"] = error.errno
except Exception as error:
    result["error_type"] = type(error).__name__
print(json.dumps(result, sort_keys=True))
'''


def inline_toml(value: object) -> str:
    """Serialize the small TOML subset used by a permission profile."""
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, str):
        return json.dumps(value, ensure_ascii=True)
    if isinstance(value, int):
        return str(value)
    if isinstance(value, list):
        return "[" + ", ".join(inline_toml(item) for item in value) + "]"
    if isinstance(value, dict) and all(isinstance(key, str) for key in value):
        return "{ " + ", ".join(
            json.dumps(key) + " = " + inline_toml(item)
            for key, item in value.items()
        ) + " }"
    raise ValueError("Unsupported permission-profile TOML value")


def validate_profile(profile: dict[str, object]) -> None:
    """Reject an unsuitable candidate instead of silently strengthening it."""
    filesystem = profile.get("filesystem")
    if not isinstance(filesystem, dict):
        raise ValueError("Candidate profile has no filesystem table")
    for key, expected in ((":root", "deny"), (":minimal", "read")):
        if filesystem.get(key) != expected:
            raise ValueError(f"Candidate profile must explicitly set {key}={expected}")
    if profile.get("extends") != ":workspace":
        raise ValueError("Candidate profile must extend :workspace")
    if profile.get("workspace_roots"):
        raise ValueError("Conformance requires no profile-defined extra workspace_roots")


def classify_probe(
    returncode: int, stdout: str, expected: str, nonce: str,
    *, expected_operation: str | None = None,
) -> dict[str, object]:
    """Only a probe-observed access-denied errno demonstrates a denied operation."""
    if expected not in {"allow", "deny"}:
        raise ValueError("Unknown expected verdict")
    if returncode != 0:
        return {"passed": False, "category": "probe_or_launcher_failed",
                "returncode": returncode}
    try:
        result = json.loads(stdout)
    except (json.JSONDecodeError, TypeError):
        return {"passed": False, "category": "missing_probe_result"}
    if not isinstance(result, dict) \
            or not {"protocol", "nonce", "operation", "success", "errno"}.issubset(result) \
            or result.get("protocol") != PROTOCOL \
            or result.get("nonce") != nonce \
            or type(result.get("success")) is not bool \
            or result.get("operation") not in {"read", "write", "rename"} \
            or (expected_operation is not None and result.get("operation") != expected_operation):
        return {"passed": False, "category": "invalid_probe_result"}
    success = result["success"]
    failure_errno = result.get("errno")
    if success:
        valid_success = failure_errno is None and "error_type" not in result
        return {"passed": expected == "allow" and valid_success,
                "category": "access_allowed" if valid_success else "invalid_probe_result"}
    access_denied = type(failure_errno) is int \
        and failure_errno in {errno.EACCES, errno.EPERM} \
        and "error_type" not in result
    return {"passed": expected == "deny" and access_denied,
            "category": "os_access_denied" if access_denied else "unexpected_probe_error",
            "errno": failure_errno}


def safe_launcher_diagnostic(stderr: str) -> str:
    """Do not copy arbitrary client output or user configuration into the receipt."""
    for line in stderr.splitlines():
        if "sandbox-exec: execvp() of " in line and " failed: Operation not permitted" in line:
            return "sandbox-exec could not start the probe executable"
    for signature in (
        "sandbox_apply: Operation not permitted",
        "failed to inspect Seatbelt writable root",
    ):
        if signature in stderr:
            return signature
    return "See the native client separately; raw stderr was not retained"


def probe_runtime_metadata() -> dict[str, str]:
    """Record the interpreter identity without inspecting account configuration."""
    return {
        "executable": sys.executable,
        "resolved_executable": str(Path(sys.executable).resolve()),
        "base_prefix": sys.base_prefix,
        "version": sys.version.split()[0],
    }


def run_native(command: list[str], workspace: Path, prompt: str,
               timeout: int) -> subprocess.CompletedProcess[str]:
    """Bound the native client and descendants to an owned process group."""
    if not hasattr(os, "killpg"):
        raise RuntimeError("Native probes require POSIX process-group support")
    process = subprocess.Popen(
        command, cwd=workspace, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, text=True, start_new_session=True,
    )

    try:
        stdout, stderr = process.communicate(prompt, timeout=timeout)
        return subprocess.CompletedProcess(command, process.returncode, stdout, stderr)
    finally:
        try:
            stop_owned_process_group(process)
        finally:
            for stream in (process.stdin, process.stdout, process.stderr):
                if stream is not None:
                    stream.close()


def run_case(
    codex: str, profile: dict[str, object], workspace: Path, probe: Path,
    operation: str, path: Path, expected: str, name: str, nonce: str,
    *, depth: int = 0, destination: Path | None = None,
    profile_name: str = "artifactflow-edit",
) -> dict[str, object]:
    try:
        # Native permission roots are canonicalized. Launch the same binary
        # directly rather than traversing an ungranted interpreter symlink.
        probe_executable = str(Path(sys.executable).resolve(strict=True))
        command = [
            codex, "sandbox", "-P", profile_name,
            "-C", str(workspace), "--include-managed-config",
            "-c", "permissions=" + inline_toml({profile_name: profile}),
            "--", probe_executable, "-I", "-B", str(probe), nonce, operation,
            str(path), str(destination or ""), str(depth),
        ]
        result = run_native(command, workspace, "", 25)
    except (OSError, RuntimeError, subprocess.TimeoutExpired) as error:
        return {"name": name, "passed": False, "category": "launcher_unavailable",
                "error_type": type(error).__name__}
    verdict = classify_probe(result.returncode, result.stdout, expected, nonce,
                             expected_operation=operation)
    if not verdict["passed"] and result.stderr:
        verdict["diagnostic"] = safe_launcher_diagnostic(result.stderr)
        verdict["stderr_sha256"] = hashlib.sha256(result.stderr.encode()).hexdigest()
    return {"name": name, "expected": expected, "operation": operation,
            "descendant_depth": depth, "probe_executable": probe_executable,
            **verdict}


def create_fixtures(parent: Path) -> tuple[Path, Path, dict[str, Path]]:
    parent.mkdir(parents=True, exist_ok=True)
    root = Path(tempfile.mkdtemp(prefix="agent-containment-", dir=parent)).resolve()
    (root / "fixture-receipt.json").write_text(json.dumps({
        "passed": False, "scope": "owned synthetic fixture creation only",
        "fixture_root": str(root), "state": "setup_started",
    }, indent=2) + "\n", encoding="utf-8")
    print(f"Owned fixture root retained: {root}", flush=True)
    workspace = root / "workspace"
    outside = root / "outside"
    workspace.mkdir()
    outside.mkdir()
    # A nested Git root prevents -C from treating the enclosing real repository
    # as this isolated probe's workspace.
    git = shutil.which("git")
    if git is None:
        raise RuntimeError("Git is required to establish the synthetic workspace root")
    initialized = subprocess.run([git, "init", "--quiet", str(workspace)],
                                 capture_output=True, text=True, check=False)
    if initialized.returncode != 0:
        raise RuntimeError(f"Synthetic git init failed; retained fixtures at {root}")
    files = {
        "allowed": workspace / "allowed.txt",
        "writable": workspace / "writable.txt",
        "control": workspace / "Makefile",
        "secret": workspace / ".env.conformance",
        "outside": outside / "denied.txt",
        "approved": outside / "approved.txt",
        "unrelated": outside / "unrelated.txt",
        "alias": workspace / "outside-alias.txt",
        "probe": workspace / "probe.py",
    }
    for key, path in files.items():
        if key not in {"alias", "probe"}:
            path.write_bytes(b"artifactflow-owned-sentinel\n")
    files["alias"].symlink_to(files["outside"])
    files["probe"].write_text(PROBE_SOURCE, encoding="utf-8")
    (root / "fixture-receipt.json").write_text(json.dumps({
        "passed": False, "scope": "owned synthetic fixture creation only",
        "fixture_root": str(root), "state": "setup_complete_native_checks_pending",
    }, indent=2) + "\n", encoding="utf-8")
    return root, workspace, files


def ambient_preflight(files: dict[str, Path]) -> None:
    """Prevent outer sandbox or host permissions from supplying a false denial."""
    sentinel = b"artifactflow-owned-sentinel\n"
    for key in ("allowed", "writable", "control", "secret", "outside", "approved", "unrelated"):
        path = files[key]
        if path.read_bytes() != sentinel:
            raise ValueError("Owned synthetic fixture content mismatch")
        with path.open("r+b") as stream:
            stream.write(sentinel)
    if files["alias"].read_bytes() != sentinel:
        raise ValueError("Owned synthetic symlink fixture content mismatch")
    control = files["control"]
    destination = control.with_name("ambient-renamed-control.txt")
    control.rename(destination)
    destination.rename(control)


def load_candidate(config: Path, profile_name: str) -> tuple[dict[str, object], str]:
    data = config.read_bytes()
    parsed = tomllib.loads(data.decode("utf-8"))
    profile = parsed.get("permissions", {}).get(profile_name)
    if not isinstance(profile, dict):
        raise ValueError("Named candidate permission profile is missing")
    validate_profile(profile)
    return profile, hashlib.sha256(data).hexdigest()


def codex_bundle_candidates() -> tuple[Path, ...]:
    """Known macOS installations; never scan private application data."""
    return (
        Path("/Applications/ChatGPT.app/Contents/Resources/codex-cli/CodexCLI.app/Contents/MacOS/codex"),
        Path("/Applications/Codex.app/Contents/Resources/codex"),
    )


def resolve_codex(explicit: str | None = None) -> str | None:
    """Select one executable without changing PATH or weakening the sandbox."""
    selected = explicit if explicit is not None else os.environ.get("CODEX_BIN")
    if selected is not None:
        executable = shutil.which(os.path.expanduser(selected)) if selected.strip() else None
        if executable is None:
            raise ValueError("--codex-bin/CODEX_BIN must name an executable; fix the selection or unset CODEX_BIN")
        return str(Path(executable).resolve())
    executable = shutil.which("codex")
    if executable is not None:
        return str(Path(executable).resolve())
    if sys.platform == "darwin":
        for candidate in codex_bundle_candidates():
            if candidate.is_file() and os.access(candidate, os.X_OK):
                return str(candidate.resolve())
    return None


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--project-root", "--repo", dest="project_root", type=Path,
                        default=Path(__file__).resolve().parents[2])
    parser.add_argument("--fixture-parent", type=Path,
                        help="Owned synthetic fixture parent; requires explicit access outside the project")
    parser.add_argument("--config", type=Path)
    parser.add_argument("--profile", default="artifactflow-edit")
    parser.add_argument("--codex-bin", help="Codex executable; overrides CODEX_BIN, PATH, and Desktop discovery")
    args = parser.parse_args()
    project = args.project_root.resolve()
    config = args.config or project / ".codex" / "config.toml"
    try:
        profile, config_hash = load_candidate(config, args.profile)
    except (OSError, ValueError, tomllib.TOMLDecodeError) as error:
        print(f"FAIL: candidate configuration: {error}", file=sys.stderr)
        return 2
    try:
        codex = resolve_codex(args.codex_bin)
    except ValueError as error:
        print(f"FAIL: {error}", file=sys.stderr)
        return 2
    if codex is None:
        print("FAIL: Codex CLI was not found on PATH or in a known macOS Desktop bundle. "
              "Select an installed executable with --codex-bin or CODEX_BIN.", file=sys.stderr)
        return 2
    print(f"Using Codex executable: {codex}", flush=True)
    runtime = probe_runtime_metadata()
    print(f"Probe Python: {runtime['executable']} -> {runtime['resolved_executable']}", flush=True)
    print(f"Probe Python runtime root: {runtime['base_prefix']}", flush=True)
    print("Native probes launch the resolved Python executable directly.", flush=True)
    try:
        root, workspace, files = create_fixtures(
            args.fixture_parent or project / "storage/framework/testing",
        )
        ambient_preflight(files)
    except (OSError, RuntimeError, ValueError) as error:
        print(f"FAIL: fixture setup ({type(error).__name__}); no native verdict. "
              "Any created fixture root was printed above and has been retained.",
              file=sys.stderr)
        return 2
    try:
        with OwnedReceipt(root, "receipt.json") as receipt_output:
            nonce = str(uuid.uuid4())
            version = subprocess.run([codex, "--version"], capture_output=True,
                                     text=True, check=False, timeout=10)
            receipt: dict[str, object] = {
                "scope": "candidate profile in a separate Codex native sandbox; not active-session proof",
                "config_sha256": config_hash, "source_profile": args.profile,
                "fixture_root": str(root), "workspace": str(workspace),
                "codex_executable": codex,
                "python_runtime": runtime,
                "codex_version": version.stdout.strip() if version.returncode == 0 else "unavailable",
                "ambient_preflight": "owned reads, writes, symlink read, and control rename passed before native sandbox",
                "scratch_policy": "inherited temp access preserved; outside fixtures are not in temp roots",
                "cases": [],
            }
            cases: list[dict[str, object]] = []

            def check(name: str, operation: str, key: str, expected: str,
                      *, depth: int = 0, granted: bool = False, rename: bool = False) -> None:
                selected = copy.deepcopy(profile)
                if granted:
                    filesystem = selected["filesystem"]
                    assert isinstance(filesystem, dict)
                    filesystem[str(files["approved"])] = "read"
                verdict = run_case(codex, selected, workspace, files["probe"], operation,
                                   files[key], expected, name, nonce, depth=depth,
                                   destination=workspace / "renamed-control.txt" if rename else None,
                                   profile_name=args.profile)
                cases.append(verdict)
                print(("PASS" if verdict["passed"] else "FAIL") + ": " + name
                      + " (" + str(verdict["category"]) + ")", flush=True)
                if not verdict["passed"] and "diagnostic" in verdict:
                    print("Launcher diagnostic: " + str(verdict["diagnostic"]), flush=True)

            check("workspace read", "read", "allowed", "allow")
            # Without a proven runnable probe, later nonzero results cannot prove denial.
            if cases[-1]["passed"]:
                check("workspace write", "write", "writable", "allow")
                check("control write", "write", "control", "deny")
                check("control rename", "rename", "control", "deny", rename=True)
                check("synthetic secret read", "read", "secret", "deny")
                check("synthetic secret write", "write", "secret", "deny")
                check("outside direct read", "read", "outside", "deny")
                check("outside child read", "read", "outside", "deny", depth=1)
                check("outside grandchild read", "read", "outside", "deny", depth=2)
                check("outside symlink read", "read", "alias", "deny")
                check("outside write", "write", "outside", "deny")
                check("explicit read grant", "read", "approved", "allow", granted=True)
                check("grant leaves unrelated read denied", "read", "unrelated", "deny", granted=True)
                check("grant leaves write denied", "write", "approved", "deny", granted=True)
            receipt["cases"] = cases
            passed = len(cases) == 14 and all(case["passed"] is True for case in cases)
            receipt["passed"] = passed
            path = root / "receipt.json"
            receipt_output.write(receipt)
            print(f"Receipt and owned fixtures retained: {path}")
            return 0 if passed else 1
    except OSError:
        print("UNAVAILABLE: owned receipt directory or target changed; no native verdict.")
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
