#!/usr/bin/env python3
"""Conformance-result and owned-process cleanup tests; no native client launches."""
from __future__ import annotations

import argparse
import copy
import errno
import importlib.util
import io
import json
import os
import selectors
import signal
import subprocess
import sys
import tomllib
import unittest
from pathlib import Path
from unittest.mock import MagicMock, call, patch


REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
RUNNER_PATH: Path | None = None
NONCE = "synthetic-test-receipt"
VALID_PROFILE = {"extends": ":workspace", "filesystem": {":root": "deny", ":minimal": "read"}}


class CodexProbeResultsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        runner = RUNNER_PATH or REPOSITORY_ROOT / "scripts/agent-containment/run_codex.py"
        if not runner.is_file():
            raise AssertionError(f"Native containment runner is missing: {runner}")
        spec = importlib.util.spec_from_file_location("artifactflow_containment_under_test", runner)
        if spec is None or spec.loader is None:
            raise AssertionError("Cannot import the containment runner")
        cls.module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.module)

    def marker(self, success: bool = False, error_number: int | None = errno.EACCES, **changes: object) -> str:
        value = {"protocol": "artifactflow-containment-v1", "nonce": NONCE,
                 "operation": "read", "success": success, "errno": error_number}
        value.update(changes)
        return json.dumps(value)

    def classify(self, stdout: str, expected: str = "deny", returncode: int = 0) -> dict:
        return self.module.classify_probe(returncode, stdout, expected, NONCE)

    def test_launcher_diagnostic_identifies_sandbox_application_failure(self) -> None:
        result = self.module.safe_launcher_diagnostic("sandbox-exec: sandbox_apply: Operation not permitted\n")
        self.assertEqual("sandbox_apply: Operation not permitted", result)

    def test_launcher_diagnostic_identifies_probe_exec_failure_without_echoing_runtime(self) -> None:
        stderr = "sandbox-exec: execvp() of 'synthetic private runtime' failed: Operation not permitted\n"
        result = self.module.safe_launcher_diagnostic(stderr)
        self.assertEqual("sandbox-exec could not start the probe executable", result)
        self.assertNotIn("synthetic private runtime", result)

    def test_launcher_diagnostic_does_not_mistake_startup_path_warning_for_probe_failure(self) -> None:
        stderr = (
            "WARNING: could not create PATH aliases at synthetic-private-directory: Operation not permitted\n"
            "unknown actual client error\n"
        )
        result = self.module.safe_launcher_diagnostic(stderr)
        self.assertEqual("See the native client separately; raw stderr was not retained", result)
        self.assertNotIn("synthetic-private-directory", result)

    def test_launcher_diagnostic_does_not_echo_unrelated_private_output(self) -> None:
        result = self.module.safe_launcher_diagnostic("unexpected synthetic-private-value-never-real\n")
        self.assertEqual("See the native client separately; raw stderr was not retained", result)
        self.assertNotIn("synthetic-private-value-never-real", result)

    def test_launcher_diagnostic_preserves_seatbelt_root_failure_without_echoing_path(self) -> None:
        result = self.module.safe_launcher_diagnostic("failed to inspect Seatbelt writable root '/synthetic-private-root'\n")
        self.assertEqual("failed to inspect Seatbelt writable root", result)
        self.assertNotIn("/synthetic-private-root", result)

    def test_probe_runtime_metadata_contains_only_runtime_identity(self) -> None:
        metadata = getattr(self.module, "probe_runtime_metadata", None)
        self.assertTrue(callable(metadata), "Native probe receipts must identify the selected Python runtime.")
        with patch.object(self.module.sys, "executable", "/synthetic/runtime with spaces/python3"), \
                patch.object(self.module.sys, "base_prefix", "/synthetic/runtime"), \
                patch.object(self.module.sys, "version", "3.13.2 (synthetic runtime build)"), \
                patch.object(Path, "resolve", return_value=Path("/synthetic/resolved/python3")):
            self.assertEqual({
                "executable": "/synthetic/runtime with spaces/python3",
                "resolved_executable": "/synthetic/resolved/python3",
                "base_prefix": "/synthetic/runtime",
                "version": "3.13.2",
            }, metadata())

    def codex_resolver(self):
        resolver = getattr(self.module, "resolve_codex", None)
        self.assertTrue(callable(resolver), "Codex discovery must support explicit binaries and the macOS app bundle.")
        return resolver

    def test_codex_discovery_uses_executable_macos_bundle_when_path_is_missing(self) -> None:
        resolver = self.codex_resolver()
        candidate = Path("/Applications/Codex Test.app/Contents/Resources/codex")
        with patch.dict(self.module.os.environ, {}, clear=True), \
                patch.object(self.module.sys, "platform", "darwin"), \
                patch.object(self.module.shutil, "which", return_value=None), \
                patch.object(self.module, "codex_bundle_candidates", return_value=[candidate]), \
                patch.object(Path, "is_file", return_value=True), \
                patch.object(self.module.os, "access", return_value=True) as executable:
            self.assertEqual(str(candidate), resolver())
            self.assertTrue(executable.called)
            self.assertTrue(all(item.args[1] == self.module.os.X_OK for item in executable.call_args_list))

    def test_codex_discovery_prefers_path_over_bundle_candidates(self) -> None:
        resolver = self.codex_resolver()
        with patch.dict(self.module.os.environ, {}, clear=True), \
                patch.object(self.module.shutil, "which", return_value="/synthetic/bin/codex"), \
                patch.object(self.module, "codex_bundle_candidates") as bundles:
            self.assertEqual("/synthetic/bin/codex", resolver())
            bundles.assert_not_called()

    def test_codex_discovery_explicit_binary_overrides_environment_and_path(self) -> None:
        resolver = self.codex_resolver()
        candidate = "/synthetic/Codex CLI"
        with patch.dict(self.module.os.environ, {"CODEX_BIN": "environment-codex"}, clear=True), \
                patch.object(self.module.shutil, "which", return_value=candidate) as find_binary, \
                patch.object(self.module, "codex_bundle_candidates") as bundles:
            self.assertEqual(candidate, resolver(candidate))
            find_binary.assert_called_once_with(candidate)
            bundles.assert_not_called()

    def test_codex_discovery_rejects_bad_explicit_override_without_fallback(self) -> None:
        resolver = self.codex_resolver()
        for candidate in ("", "   ", "/synthetic/missing-codex"):
            with self.subTest(candidate=candidate), \
                    patch.dict(self.module.os.environ, {"CODEX_BIN": "environment-codex"}, clear=True), \
                    patch.object(self.module.shutil, "which", return_value=None), \
                    patch.object(self.module, "codex_bundle_candidates") as bundles:
                with self.assertRaises(ValueError):
                    resolver(candidate)
                bundles.assert_not_called()

    def test_codex_discovery_preserves_explicit_relative_path_before_lookup(self) -> None:
        with patch.dict(self.module.os.environ, {}, clear=True), \
                patch.object(self.module.shutil, "which", return_value="./codex") as find_binary:
            self.assertEqual(str(Path("./codex").resolve()), self.module.resolve_codex("./codex"))
            find_binary.assert_called_once_with("./codex")

    def test_missing_explicit_relative_binary_cannot_select_path_binary(self) -> None:
        def find_binary(command: str) -> str | None:
            return None if command == "./codex" else "/synthetic/path-codex"

        with patch.dict(self.module.os.environ, {}, clear=True), \
                patch.object(self.module.shutil, "which", side_effect=find_binary), \
                patch.object(self.module, "codex_bundle_candidates") as bundles:
            with self.assertRaises(ValueError):
                self.module.resolve_codex("./codex")
            bundles.assert_not_called()

    def test_codex_discovery_uses_environment_override_before_path(self) -> None:
        resolver = self.codex_resolver()
        with patch.dict(self.module.os.environ, {"CODEX_BIN": "environment-codex"}, clear=True), \
                patch.object(self.module.shutil, "which", return_value="/synthetic/environment-codex") as find_binary, \
                patch.object(self.module, "codex_bundle_candidates") as bundles:
            self.assertEqual("/synthetic/environment-codex", resolver())
            find_binary.assert_called_once_with("environment-codex")
            bundles.assert_not_called()

    def test_codex_discovery_missing_environment_override_fails_without_fallback(self) -> None:
        resolver = self.codex_resolver()
        with patch.dict(self.module.os.environ, {"CODEX_BIN": "/synthetic/missing-codex"}, clear=True), \
                patch.object(self.module.shutil, "which", return_value=None), \
                patch.object(self.module, "codex_bundle_candidates") as bundles:
            with self.assertRaises(ValueError):
                resolver()
            bundles.assert_not_called()

    def test_codex_discovery_does_not_search_macos_bundles_on_other_platforms(self) -> None:
        resolver = self.codex_resolver()
        with patch.dict(self.module.os.environ, {}, clear=True), \
                patch.object(self.module.sys, "platform", "linux"), \
                patch.object(self.module.shutil, "which", return_value=None), \
                patch.object(self.module, "codex_bundle_candidates") as bundles:
            self.assertIsNone(resolver())
            bundles.assert_not_called()

    def test_codex_discovery_rejects_nonfiles_and_nonexecutable_bundles(self) -> None:
        resolver = self.codex_resolver()
        candidates = [Path("/synthetic/Codex.app/Contents/Resources/codex")]
        for is_file, executable in ((False, True), (True, False)):
            with self.subTest(is_file=is_file, executable=executable), \
                    patch.dict(self.module.os.environ, {}, clear=True), \
                    patch.object(self.module.sys, "platform", "darwin"), \
                    patch.object(self.module.shutil, "which", return_value=None), \
                    patch.object(self.module, "codex_bundle_candidates", return_value=candidates), \
                    patch.object(Path, "is_file", return_value=is_file), \
                    patch.object(self.module.os, "access", return_value=executable):
                self.assertIsNone(resolver())

    def test_main_resolver_unavailable_or_invalid_never_creates_fixtures(self) -> None:
        for error in (None, ValueError("synthetic invalid override")):
            with self.subTest(error=type(error).__name__), \
                    patch.object(sys, "argv", ["run_codex", "--repo", "/synthetic/project"]), \
                    patch.object(self.module, "load_candidate", return_value=(copy.deepcopy(VALID_PROFILE), "hash")), \
                    patch.object(self.module, "resolve_codex", return_value=None, side_effect=error, create=True) as resolve, \
                    patch.object(self.module.shutil, "which", return_value=None), \
                    patch.object(self.module, "create_fixtures") as fixtures, \
                    patch.object(self.module, "run_case") as native, \
                    patch.object(sys, "stderr", new=io.StringIO()):
                self.assertEqual(2, self.module.main())
                resolve.assert_called_once()
                fixtures.assert_not_called()
                native.assert_not_called()

    def test_main_forwards_explicit_codex_bin_without_creating_fixtures_when_unavailable(self) -> None:
        candidate = "/synthetic/Codex CLI"
        with patch.object(sys, "argv", ["run_codex", "--repo", "/synthetic/project", "--codex-bin", candidate]), \
                patch.object(self.module, "load_candidate", return_value=(copy.deepcopy(VALID_PROFILE), "hash")), \
                patch.object(self.module, "resolve_codex", return_value=None, create=True) as resolve, \
                patch.object(self.module, "create_fixtures") as fixtures, \
                patch.object(sys, "stderr", new=io.StringIO()):
            self.assertEqual(2, self.module.main())
            resolve.assert_called_once_with(candidate)
            fixtures.assert_not_called()

    def test_access_denied_requires_probe_observed_permission_errno(self) -> None:
        for error_number in (errno.EACCES, errno.EPERM):
            with self.subTest(errno=error_number):
                result = self.classify(self.marker(error_number=error_number))
                self.assertIs(result["passed"], True)
                self.assertEqual("os_access_denied", result["category"])

    def test_allowed_positive_control_requires_success(self) -> None:
        self.assertIs(self.classify(self.marker(True, None), "allow")["passed"], True)
        self.assertIs(self.classify(self.marker(), "allow")["passed"], False)
        self.assertIs(self.classify(self.marker(True, None), "deny")["passed"], False)

    def test_file_absence_and_unrelated_errors_do_not_prove_denial(self) -> None:
        for error_number in (errno.ENOENT, errno.EIO, errno.ENOTDIR, None, True, "13"):
            with self.subTest(errno=error_number):
                self.assertIs(self.classify(self.marker(error_number=error_number))["passed"], False)

    def test_launcher_failure_never_counts_as_successful_denial(self) -> None:
        for returncode in (1, 2, 126, 127, -9):
            with self.subTest(returncode=returncode):
                result = self.classify(self.marker(), returncode=returncode)
                self.assertIs(result["passed"], False)
                self.assertEqual("probe_or_launcher_failed", result["category"])

    def test_missing_malformed_skipped_and_unavailable_results_never_pass(self) -> None:
        for stdout in ("", "Permission denied", "not JSON", "[]", "null",
                       '{"status":"skipped"}', '{"status":"unavailable"}'):
            with self.subTest(stdout=stdout):
                self.assertIs(self.classify(stdout)["passed"], False)

    def test_forged_wrong_nonce_protocol_operation_and_nonboolean_success_fail(self) -> None:
        for changes in ({"nonce": "wrong"}, {"protocol": "wrong"},
                        {"operation": "unexpected"}, {"success": 0}, {"success": "false"}):
            with self.subTest(changes=changes):
                self.assertIs(self.classify(self.marker(**changes))["passed"], False)

    def test_every_required_marker_field_is_required(self) -> None:
        valid = json.loads(self.marker(True, None))
        for field in ("protocol", "nonce", "operation", "success", "errno"):
            with self.subTest(field=field):
                candidate = dict(valid)
                del candidate[field]
                self.assertIs(self.classify(json.dumps(candidate), "allow")["passed"], False)

    def test_exception_marker_cannot_be_mistaken_for_os_denial(self) -> None:
        self.assertIs(self.classify(self.marker(error_type="RuntimeError"))["passed"], False)
        self.assertIs(self.classify(self.marker(True, None, error_type="RuntimeError"), "allow")["passed"], False)

    def test_success_with_error_number_is_invalid(self) -> None:
        self.assertIs(self.classify(self.marker(True, errno.EACCES), "allow")["passed"], False)

    def test_unknown_expected_verdict_is_rejected(self) -> None:
        with self.assertRaises(ValueError):
            self.classify(self.marker(), "skipped")

    def test_marker_must_report_the_operation_requested_by_the_runner(self) -> None:
        result = self.module.classify_probe(0, self.marker(), "deny", NONCE, expected_operation="write")
        self.assertIs(result["passed"], False)

    def test_missing_launcher_and_timeout_never_pass(self) -> None:
        for error in (FileNotFoundError("synthetic missing launcher"),
                      subprocess.TimeoutExpired("synthetic launcher", 25)):
            with self.subTest(error=type(error).__name__), patch.object(self.module, "run_native", side_effect=error):
                result = self.module.run_case(
                    "synthetic-codex", copy.deepcopy(VALID_PROFILE), Path("/synthetic/workspace"),
                    Path("/synthetic/workspace/probe.py"), "read", Path("/synthetic/outside.txt"),
                    "deny", "missing launcher", NONCE,
                )
                self.assertIs(result["passed"], False)
                self.assertEqual("launcher_unavailable", result["category"])

    def test_driver_forwards_runtime_grant_and_candidate_restrictions_without_mutation(self) -> None:
        candidate = copy.deepcopy(VALID_PROFILE)
        candidate["filesystem"]["/Library/Frameworks/Python.framework"] = "read"
        candidate["filesystem"][":workspace_roots"] = {".env*": "deny", "scripts/agent-containment": "read"}
        original = copy.deepcopy(candidate)
        completed = subprocess.CompletedProcess([], 0, self.marker(True, None), "")
        with patch.object(self.module, "run_native", return_value=completed) as launch:
            verdict = self.module.run_case(
                "synthetic-codex", candidate, Path("/synthetic/workspace"),
                Path("/synthetic/workspace/probe.py"), "read", Path("/synthetic/workspace/allowed.txt"),
                "allow", "workspace read", NONCE,
            )
        self.assertIs(verdict["passed"], True)
        command = launch.call_args.args[0]
        key, rhs = command[command.index("-c") + 1].split("=", 1)
        self.assertEqual("permissions", key)
        override = tomllib.loads("value=" + rhs)["value"]
        self.assertEqual("artifactflow-edit", command[command.index("-P") + 1])
        self.assertEqual({"artifactflow-edit": original}, override)
        self.assertEqual(original, candidate)

    def test_driver_preserves_literal_custom_profile_name_in_selector_and_override(self) -> None:
        profile_name = 'team.review "local"'
        candidate = copy.deepcopy(VALID_PROFILE)
        candidate["filesystem"][":workspace_roots"] = {".env*": "deny", "AGENTS.md": "read"}
        original = copy.deepcopy(candidate)
        completed = subprocess.CompletedProcess([], 0, self.marker(True, None), "")
        with patch.object(self.module, "run_native", return_value=completed) as launch:
            verdict = self.module.run_case(
                "synthetic-codex", candidate, Path("/synthetic/workspace"),
                Path("/synthetic/workspace/probe.py"), "read", Path("/synthetic/workspace/allowed.txt"),
                "allow", "workspace read", NONCE, profile_name=profile_name,
            )
        launch.assert_called_once()
        command = launch.call_args.args[0]
        self.assertEqual(profile_name, command[command.index("-P") + 1])
        key, rhs = command[command.index("-c") + 1].split("=", 1)
        self.assertEqual("permissions", key)
        override = tomllib.loads("value=" + rhs)["value"]
        self.assertEqual({profile_name: original}, override)
        self.assertEqual(original, candidate)
        self.assertIs(verdict["passed"], True)

    def test_main_forwards_selected_profile_name_to_every_native_case(self) -> None:
        profile_name = "team.review"
        root = MagicMock(spec=Path)
        workspace = Path("/synthetic/workspace")
        files = {name: workspace / name for name in
                 ("probe", "allowed", "writable", "control", "secret", "outside", "approved", "unrelated", "alias")}
        runtime = {"executable": "/synthetic/python3", "resolved_executable": "/synthetic/python3",
                   "base_prefix": "/synthetic", "version": "3.13.2"}
        with patch.object(sys, "argv", ["run_codex", "--repo", "/synthetic/project", "--profile", profile_name]), \
                patch.object(self.module, "load_candidate", return_value=(copy.deepcopy(VALID_PROFILE), "hash")), \
                patch.object(self.module, "resolve_codex", return_value="synthetic-codex"), \
                patch.object(self.module, "probe_runtime_metadata", return_value=runtime), \
                patch.object(self.module, "create_fixtures", return_value=(root, workspace, files)), \
                patch.object(self.module, "ambient_preflight"), \
                patch.object(self.module, "OwnedReceipt"), \
                patch.object(self.module.subprocess, "run", return_value=
                             subprocess.CompletedProcess([], 0, "synthetic Codex version", "")), \
                patch.object(self.module, "run_case", return_value={"passed": True, "category": "synthetic"}) as native, \
                patch.object(sys, "stdout", new=io.StringIO()):
            self.assertEqual(0, self.module.main())
        self.assertEqual(14, native.call_count)
        self.assertTrue(all(item.kwargs.get("profile_name") == profile_name for item in native.call_args_list))

    def test_driver_executes_strictly_resolved_python_without_changing_permissions(self) -> None:
        alias = "/synthetic/bin/python3"
        canonical = Path("/synthetic/Python.framework/Versions/current/bin/python3")
        candidate = copy.deepcopy(VALID_PROFILE)
        candidate["filesystem"]["/synthetic/Python.framework"] = "read"
        original = copy.deepcopy(candidate)
        completed = subprocess.CompletedProcess([], 0, self.marker(True, None), "")
        with patch.object(self.module.sys, "executable", alias), \
                patch.object(Path, "resolve", autospec=True, return_value=canonical) as resolve, \
                patch.object(self.module, "run_native", return_value=completed) as launch:
            verdict = self.module.run_case(
                "synthetic-codex", candidate, Path("/synthetic/workspace"),
                Path("/synthetic/workspace/probe.py"), "read", Path("/synthetic/workspace/allowed.txt"),
                "allow", "workspace read", NONCE,
            )
        resolve.assert_called_once_with(Path(alias), strict=True)
        launch.assert_called_once()
        command = launch.call_args.args[0]
        self.assertEqual(str(canonical), command[command.index("--") + 1])
        self.assertNotIn(alias, command)
        key, rhs = command[command.index("-c") + 1].split("=", 1)
        self.assertEqual("permissions", key)
        override = tomllib.loads("value=" + rhs)["value"]
        self.assertEqual({"artifactflow-edit": original}, override)
        self.assertEqual(original, candidate)
        self.assertIs(verdict["passed"], True)
        self.assertEqual(str(canonical), verdict.get("probe_executable"))

    def test_canonical_python_launch_failure_records_executable_without_retry(self) -> None:
        canonical = Path("/synthetic/Python.framework/bin/python3")
        completed = subprocess.CompletedProcess([], 126, "", "sandbox-exec: execvp() failed")
        with patch.object(Path, "resolve", return_value=canonical), \
                patch.object(self.module, "run_native", return_value=completed) as launch:
            verdict = self.module.run_case(
                "synthetic-codex", copy.deepcopy(VALID_PROFILE), Path("/synthetic/workspace"),
                Path("/synthetic/workspace/probe.py"), "read", Path("/synthetic/workspace/allowed.txt"),
                "allow", "workspace read", NONCE,
            )
        launch.assert_called_once()
        self.assertIs(verdict["passed"], False)
        self.assertEqual("probe_or_launcher_failed", verdict["category"])
        self.assertEqual(str(canonical), verdict.get("probe_executable"))

    def test_python_resolution_failure_never_launches_or_reports_an_executable(self) -> None:
        for error in (FileNotFoundError("synthetic broken Python alias"),
                      PermissionError(errno.EACCES, "synthetic inaccessible Python alias")):
            with self.subTest(error=type(error).__name__), \
                    patch.object(Path, "resolve", side_effect=error), \
                    patch.object(self.module, "run_native", return_value=
                                 subprocess.CompletedProcess([], 0, self.marker(True, None), "")) as launch:
                verdict = self.module.run_case(
                    "synthetic-codex", copy.deepcopy(VALID_PROFILE), Path("/synthetic/workspace"),
                    Path("/synthetic/workspace/probe.py"), "read", Path("/synthetic/workspace/allowed.txt"),
                    "allow", "workspace read", NONCE,
                )
                launch.assert_not_called()
                self.assertIs(verdict["passed"], False)
                self.assertEqual("launcher_unavailable", verdict["category"])
                self.assertEqual(type(error).__name__, verdict["error_type"])
                self.assertNotIn("probe_executable", verdict)

    def fixture_paths(self) -> dict[str, MagicMock]:
        files = {name: MagicMock(name=name) for name in
                 ("allowed", "writable", "control", "secret", "outside", "approved", "unrelated", "alias")}
        for path in files.values():
            path.read_bytes.return_value = b"artifactflow-owned-sentinel\n"
        return files

    def test_ambient_preflight_proves_reads_writes_alias_and_control_rename(self) -> None:
        files = self.fixture_paths()
        self.module.ambient_preflight(files)
        for name, path in files.items():
            path.read_bytes.assert_called_once_with()
            if name != "alias":
                path.open.assert_called_once_with("r+b")
        destination = files["control"].with_name.return_value
        files["control"].rename.assert_called_once_with(destination)
        destination.rename.assert_called_once_with(files["control"])

    def test_ambient_denied_reads_or_writes_never_count_as_native_protection(self) -> None:
        for action in ("read_bytes", "open", "rename"):
            with self.subTest(action=action):
                files = self.fixture_paths()
                getattr(files["control"], action).side_effect = PermissionError(errno.EACCES, "synthetic denial")
                with self.assertRaises(PermissionError):
                    self.module.ambient_preflight(files)

    def test_ambient_missing_or_changed_fixture_is_not_usable(self) -> None:
        for name in ("allowed", "secret", "outside", "alias"):
            with self.subTest(name=name):
                files = self.fixture_paths()
                files[name].read_bytes.return_value = b"different synthetic content"
                with self.assertRaises(ValueError):
                    self.module.ambient_preflight(files)
        files = self.fixture_paths()
        files["outside"].read_bytes.side_effect = FileNotFoundError("synthetic absent fixture")
        with self.assertRaises(FileNotFoundError):
            self.module.ambient_preflight(files)

    def test_main_never_launches_native_checks_when_ambient_access_fails(self) -> None:
        with patch.object(sys, "argv", ["run_codex", "--repo", "/synthetic/project"]), \
                patch.object(self.module, "load_candidate", return_value=(copy.deepcopy(VALID_PROFILE), "hash")), \
                patch.object(self.module.shutil, "which", return_value="synthetic-codex"), \
                patch.object(self.module, "create_fixtures", return_value=(Path("/synthetic"), Path("/synthetic/workspace"), {})), \
                patch.object(self.module, "ambient_preflight", side_effect=PermissionError("outer sandbox")), \
                patch.object(self.module, "run_case") as native, \
                patch.object(sys, "stderr", new=io.StringIO()):
            self.assertEqual(2, self.module.main())
            native.assert_not_called()

    def test_native_process_group_is_owned_and_cleaned_after_success(self) -> None:
        process = MagicMock(pid=789, returncode=0)
        process.communicate.return_value = ("synthetic output", "")
        with patch.object(self.module.subprocess, "Popen", return_value=process) as launch, \
                patch.object(self.module, "stop_owned_process_group") as cleanup:
            result = self.module.run_native(["synthetic-codex"], Path("/synthetic"), "", 25)
        self.assertTrue(launch.call_args.kwargs["start_new_session"])
        self.assertEqual(0, result.returncode)
        cleanup.assert_called_once_with(process)

    def test_timeout_terminates_owned_group_and_remains_failure(self) -> None:
        original = subprocess.TimeoutExpired("synthetic-codex", 25)
        process = MagicMock(pid=789)
        process.communicate.side_effect = [original, ("", "")]
        with patch.object(self.module.subprocess, "Popen", return_value=process), \
                patch.object(self.module, "stop_owned_process_group") as cleanup, \
                self.assertRaises(subprocess.TimeoutExpired) as raised:
            self.module.run_native(["synthetic-codex"], Path("/synthetic"), "", 25)
        self.assertIs(original, raised.exception)
        cleanup.assert_called_once_with(process)

    def test_cleanup_failure_cannot_return_a_successful_native_result(self) -> None:
        process = MagicMock(pid=789, returncode=0)
        process.communicate.return_value = ("synthetic output", "")
        with patch.object(self.module.subprocess, "Popen", return_value=process), \
                patch.object(self.module, "stop_owned_process_group", side_effect=PermissionError("synthetic cleanup denial")), \
                self.assertRaises(PermissionError):
            self.module.run_native(["synthetic-codex"], Path("/synthetic"), "", 25)
        for stream in (process.stdin, process.stdout, process.stderr):
            stream.close.assert_called_once_with()

    def test_cleanup_checks_group_after_leader_exit_and_kills_after_bounded_grace(self) -> None:
        import process_cleanup
        process = MagicMock(pid=789, returncode=0)
        process.poll.return_value = 0
        with patch.object(process_cleanup.os, "killpg") as kill, \
                patch.object(process_cleanup.time, "monotonic", side_effect=[10, 10, 15]), \
                patch.object(process_cleanup.time, "sleep") as sleep:
            process_cleanup.stop_owned_process_group(process)
        self.assertEqual([call(789, signal.SIGTERM), call(789, 0), call(789, 0),
                          call(789, signal.SIGKILL)], kill.call_args_list)
        sleep.assert_called_once_with(.02)
        process.wait.assert_called_once_with(timeout=5)

    def test_cleanup_stops_when_owned_group_disappears_without_kill(self) -> None:
        import process_cleanup
        process = MagicMock(pid=789)
        with patch.object(process_cleanup.os, "killpg", side_effect=[None, ProcessLookupError]) as kill:
            process_cleanup.stop_owned_process_group(process)
        self.assertEqual([call(789, signal.SIGTERM), call(789, 0)], kill.call_args_list)
        process.wait.assert_called_once_with(timeout=5)

    def test_cleanup_reap_timeout_is_reported_after_bounded_group_cleanup(self) -> None:
        import process_cleanup
        process = MagicMock(pid=789)
        process.wait.side_effect = subprocess.TimeoutExpired("synthetic", 5)
        with patch.object(process_cleanup.os, "killpg", side_effect=ProcessLookupError), \
                self.assertRaises(subprocess.TimeoutExpired):
            process_cleanup.stop_owned_process_group(process)
        process.wait.assert_called_once_with(timeout=5)

    def test_cleanup_liveness_denial_does_not_count_as_a_stopped_group(self) -> None:
        import process_cleanup
        process = MagicMock(pid=789)
        with patch.object(process_cleanup.os, "killpg", side_effect=[None, PermissionError, PermissionError, None]) as kill, \
                patch.object(process_cleanup.time, "monotonic", side_effect=[10, 10, 15]), \
                patch.object(process_cleanup.time, "sleep"):
            process_cleanup.stop_owned_process_group(process)
        self.assertEqual([call(789, signal.SIGTERM), call(789, 0), call(789, 0),
                          call(789, signal.SIGKILL)], kill.call_args_list)
        process.wait.assert_called_once_with(timeout=5)

    def test_cleanup_denied_force_kill_fails_closed(self) -> None:
        import process_cleanup
        process = MagicMock(pid=789)
        with patch.object(process_cleanup.os, "killpg", side_effect=[None, PermissionError, PermissionError]), \
                patch.object(process_cleanup.time, "monotonic", side_effect=[10, 15]), \
                self.assertRaises(PermissionError):
            process_cleanup.stop_owned_process_group(process)

    def test_profile_must_have_explicit_containment_instead_of_silent_strengthening(self) -> None:
        self.module.validate_profile(copy.deepcopy(VALID_PROFILE))
        for candidate in ({}, {"extends": ":workspace", "filesystem": {}},
                          {"extends": ":workspace", "filesystem": {":root": "read", ":minimal": "read"}},
                          {"extends": ":workspace", "filesystem": {":root": "deny"}},
                          {"extends": ":read-only", "filesystem": {":root": "deny", ":minimal": "read"}}):
            with self.subTest(candidate=candidate), self.assertRaises(ValueError):
                self.module.validate_profile(candidate)

    def test_extra_workspace_roots_cannot_make_outside_fixture_an_allowed_root(self) -> None:
        candidate = copy.deepcopy(VALID_PROFILE)
        candidate["workspace_roots"] = ["/synthetic-outside"]
        with self.assertRaises(ValueError):
            self.module.validate_profile(candidate)

    def assert_stubborn_descendant_stopped(self, helper: str, parent_exits: bool) -> None:
        # The grandchild closes stdio but retains this owned liveness pipe.
        # EOF proves it stopped even on hosts that retain orphan zombies.
        child = '''import os, signal, sys, time
signal.signal(signal.SIGTERM, signal.SIG_IGN)
for fd in (0, 1, 2):
    os.close(fd)
os.write(int(sys.argv[1]), b"ready\\n")
os.write(int(sys.argv[2]), b"ready")
os.close(int(sys.argv[2]))
time.sleep(30)
'''
        parent = '''import os, subprocess, sys, time
ready_read, ready_write = os.pipe()
subprocess.Popen([sys.executable, "-I", "-B", "-c", sys.argv[1], sys.argv[2], str(ready_write)],
                 pass_fds=(int(sys.argv[2]), ready_write))
os.close(ready_write)
os.close(int(sys.argv[2]))
os.read(ready_read, 5)
os.close(ready_read)
if sys.argv[3] == "wait":
    time.sleep(30)
'''
        reader, writer = os.pipe()
        process = None
        stopped = False
        real_popen = subprocess.Popen

        def launch(*args, **kwargs):
            nonlocal writer, process
            process = real_popen(*args, **kwargs, pass_fds=(writer,))
            os.close(writer)
            writer = None
            return process

        command = [str(Path(sys.executable).resolve()), "-I", "-B", "-c", parent,
                   child, str(writer), "exit" if parent_exits else "wait"]
        try:
            with patch.object(subprocess, "Popen", side_effect=launch):
                if helper == "native":
                    operation = lambda: self.module.run_native(command, Path.cwd(), "", 1)
                elif helper == "development":
                    import development_probe
                    operation = lambda: development_probe.run_process(command, Path.cwd(), 1)
                else:
                    import codex_session
                    # Exercise the real transport's close path without a client.
                    rpc = codex_session.AppServer.__new__(codex_session.AppServer)
                    rpc.process = launch(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                         stderr=subprocess.PIPE, start_new_session=True)
                    rpc.selector = selectors.DefaultSelector()
                    with selectors.DefaultSelector() as ready:
                        ready.register(reader, selectors.EVENT_READ)
                        self.assertTrue(ready.select(5), "Owned descendant did not become ready")
                    if parent_exits:
                        rpc.process.wait(timeout=5)
                    operation = rpc.close
                if helper != "app_server" and not parent_exits:
                    with self.assertRaises(subprocess.TimeoutExpired):
                        operation()
                else:
                    operation()
            with selectors.DefaultSelector() as ready:
                ready.register(reader, selectors.EVENT_READ)
                self.assertTrue(ready.select(5), "Owned descendant did not become ready")
                self.assertEqual(b"ready\n", os.read(reader, 100))
                self.assertTrue(ready.select(2), "Owned descendant survived cleanup")
                self.assertEqual(b"", os.read(reader, 100), "Owned descendant pipe must close")
                stopped = True
        finally:
            os.close(reader)
            if writer is not None:
                os.close(writer)
            if process is not None:
                if not stopped:
                    try:
                        os.killpg(process.pid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass
                process.wait(timeout=5)
                for stream in (process.stdin, process.stdout, process.stderr):
                    if stream is not None:
                        stream.close()

    def test_closed_stdio_term_ignoring_descendant_stops_after_parent_success(self) -> None:
        for helper in ("native", "development", "app_server"):
            with self.subTest(helper=helper):
                self.assert_stubborn_descendant_stopped(helper, parent_exits=True)

    def test_closed_stdio_term_ignoring_descendant_stops_after_parent_timeout(self) -> None:
        for helper in ("native", "development", "app_server"):
            with self.subTest(helper=helper):
                self.assert_stubborn_descendant_stopped(helper, parent_exits=False)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", type=Path, default=REPOSITORY_ROOT)
    parser.add_argument("--runner", type=Path)
    args, unittest_args = parser.parse_known_args()
    REPOSITORY_ROOT = args.repo.resolve(strict=True)
    RUNNER_PATH = args.runner
    unittest.main(argv=[sys.argv[0], *unittest_args], verbosity=2)
