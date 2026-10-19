#!/usr/bin/env python3
"""Pure tests for development smoke receipts; no native sandbox is launched."""
from __future__ import annotations

import copy
import errno
import hashlib
import importlib.util
import io
import json
import os
import selectors
import signal
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch


class DevelopmentProbeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        path = Path(__file__).with_name("development_probe.py")
        if not path.is_file():
            raise AssertionError("development_probe.py is required")
        spec = importlib.util.spec_from_file_location("development_probe_under_test", path)
        assert spec is not None and spec.loader is not None
        cls.module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.module)

    def receipt(self) -> dict:
        checks = []
        for name, kind in self.module.CHECKS.items():
            if kind == "command":
                check = {"name": name, "passed": True, "category": "command_completed",
                         "returncode": 0, "signature_valid": True, "git_access_warning": False}
                if name in {"search tool version", "owned sentinel search"}:
                    check["tool"] = "rg"
            else:
                denied = kind == "deny"
                operation = "rename" if name == "owned rename" else "write" if name == "owned write" else "read"
                check = {"name": name, "passed": True,
                         "category": "os_access_denied" if denied else "access_allowed",
                         "operation": operation, "success": not denied,
                         "errno": errno.EACCES if denied else None}
            checks.append(check)
        return {"protocol": "artifactflow-development-v3", "nonce": "synthetic-nonce", "checks": checks}

    def test_complete_typed_receipt_passes(self) -> None:
        receipt = self.receipt()
        self.assertEqual(19, len(receipt["checks"]))
        self.assertTrue(self.module.validate_receipt(receipt, "synthetic-nonce"))

    def test_safe_summary_identifies_failed_checks_without_raw_proof(self) -> None:
        receipt = self.receipt()
        check = next(item for item in receipt["checks"] if item["name"] == "python3 version")
        check.update({"passed": False, "category": "command_failed", "returncode": 126})
        summary = self.module.summarize_receipt(receipt, "synthetic-nonce")
        self.assertFalse(summary["passed"])
        self.assertEqual({"name": "python3 version", "passed": False, "category": "command_failed",
                          "returncode": 126, "signature_valid": True, "git_access_warning": False},
                         next(item for item in summary["checks"] if item["name"] == "python3 version"))

    def workspace_receipt(self) -> dict:
        receipt = self.receipt()
        receipt["protocol"] = "artifactflow-workspace-development-v3"
        receipt["checks"] = [item for item in receipt["checks"] if not item["name"].startswith("outside ")]
        return receipt

    def test_workspace_receipt_requires_explicit_distinct_protocol_and_all_fifteen_cases(self) -> None:
        receipt = self.workspace_receipt()
        self.assertEqual(15, len(receipt["checks"]))
        self.assertEqual("artifactflow-workspace-development-v3", self.module.WORKSPACE_PROTOCOL)
        self.assertTrue(self.module.validate_receipt(receipt, "synthetic-nonce", workspace_only=True))
        self.assertFalse(self.module.validate_receipt(receipt, "synthetic-nonce"))
        self.assertFalse(self.module.validate_receipt(self.receipt(), "synthetic-nonce", workspace_only=True))
        for change in ("missing", "duplicate", "outside", "protocol"):
            candidate = copy.deepcopy(receipt)
            if change == "missing":
                candidate["checks"].pop()
            elif change == "duplicate":
                candidate["checks"][-1] = candidate["checks"][0]
            elif change == "outside":
                candidate["checks"][-1] = self.receipt()["checks"][5]
            else:
                candidate["protocol"] = self.module.PROTOCOL
            self.assertFalse(self.module.validate_receipt(candidate, "synthetic-nonce", workspace_only=True))

    def test_workspace_summary_has_fifteen_cases_and_rejects_full_protocol(self) -> None:
        result = self.module.summarize_receipt(self.workspace_receipt(), "synthetic-nonce", workspace_only=True)
        self.assertTrue(result["passed"])
        self.assertEqual(15, len(result["checks"]))
        self.assertFalse(any(item["name"].startswith("outside ") for item in result["checks"]))
        result = self.module.summarize_receipt(self.receipt(), "synthetic-nonce", workspace_only=True)
        self.assertTrue(all(item["category"] == "invalid_receipt" for item in result["checks"]))

    def test_workspace_fixture_never_checks_or_reads_an_outside_alias(self) -> None:
        project, fixture = Path("/synthetic/project"), Path("/synthetic/project/fixture")
        with patch.object(Path, "resolve", autospec=True, side_effect=lambda path, strict: path), \
                patch.object(Path, "is_file", return_value=True), \
                patch.object(Path, "is_symlink", return_value=False), \
                patch.object(Path, "readlink") as readlink, \
                patch.object(Path, "read_bytes", autospec=True, return_value=self.module.SENTINEL) as read:
            self.module.verify_layout(project, fixture, None, workspace_only=True)
        readlink.assert_not_called()
        self.assertEqual([fixture / "source.txt", fixture / "edit.txt"], [item.args[0] for item in read.call_args_list])

    def test_workspace_batch_never_calls_outside_operations(self) -> None:
        project, fixture = Path("/synthetic/project"), Path("/synthetic/project/fixture")
        def command(name, argv, cwd, timeout=15, *, tool=None, expected_stdout=None, stdin_text=None):
            result = {"name": name, "passed": True, "category": "command_completed", "returncode": 0,
                      "signature_valid": True, "git_access_warning": False}
            return {**result, "tool": tool} if tool is not None else result
        with patch.object(self.module, "verify_layout") as verify, \
                patch.object(self.module, "read_access", return_value={"success": True, "errno": None}) as read, \
                patch.object(self.module, "owned_write", return_value={"success": True, "errno": None}), \
                patch.object(self.module, "owned_read", return_value={"success": True, "errno": None}), \
                patch.object(self.module, "owned_rename", return_value={"success": True, "errno": None}), \
                patch.object(self.module.shutil, "which", return_value="/usr/bin/rg"), \
                patch.object(self.module, "run_child_read") as child, \
                patch.object(self.module, "command_result", side_effect=command):
            result = self.module.run_checks(project, fixture, None, "synthetic-nonce", workspace_only=True)
        verify.assert_called_once_with(project, fixture, None, workspace_only=True)
        child.assert_not_called()
        self.assertEqual([project / "README.md", project / self.module.VENDOR_SOURCE], [item.args[0] for item in read.call_args_list])
        self.assertTrue(self.module.validate_receipt(result, "synthetic-nonce", workspace_only=True))

    def test_workspace_main_omits_outside_path_and_selects_workspace_protocol(self) -> None:
        with patch.object(self.module, "run_checks", return_value=self.workspace_receipt()) as run, \
                patch.object(sys, "stdout", io.StringIO()):
            status = self.module.main(["--project", "/project", "--fixture", "/project/fixture",
                                       "--nonce", "synthetic-nonce", "--workspace-only"])
        self.assertEqual(0, status)
        run.assert_called_once_with(Path("/project"), Path("/project/fixture"), None, "synthetic-nonce", workspace_only=True)

    def test_workspace_main_rejects_outside_and_child_arguments(self) -> None:
        base = ["--project", "/project", "--fixture", "/project/fixture", "--nonce", "synthetic-nonce", "--workspace-only"]
        for extra in (["--outside", "/outside"], ["--child-read", "/outside"]):
            with patch.object(self.module, "run_checks") as run, patch.object(self.module, "run_child_read") as child, \
                    patch.object(sys, "stderr", io.StringIO()), self.assertRaises(SystemExit) as error:
                self.module.main(base + extra)
            self.assertEqual(2, error.exception.code)
            run.assert_not_called()
            child.assert_not_called()

    def test_failed_commands_expose_only_fixed_diagnostic_and_known_executable(self) -> None:
        cases = (("xcrun: error: invalid active developer path (/synthetic private)", "developer_tools_unavailable"),
                 ("xcode-select: error: invalid developer directory '/synthetic private'", "developer_tools_unavailable"),
                 ("You have not agreed to the Xcode license agreements. synthetic private", "developer_tools_license_required"),
                 ("Operation not permitted: synthetic private", "system_access_denied"),
                 ("Permission denied: synthetic private", "system_access_denied"),
                 ("synthetic private error", "unclassified_command_error"))
        for stderr, expected in cases:
            with patch.object(self.module, "run_process", return_value=subprocess.CompletedProcess([], 72, "", stderr)) as run, \
                    patch.object(self.module.shutil, "which", return_value="/usr/bin/git"), \
                    patch.object(Path, "resolve", return_value=Path("/usr/bin/git")):
                result = self.module.command_result("git version", ["git", "--version"], Path("/synthetic"))
            self.assertEqual(expected, result["diagnostic"])
            self.assertEqual("/usr/bin/git", result["executable"])
            self.assertEqual(72, result["returncode"])
            self.assertIs(False, result["signature_valid"])
            self.assertNotIn("synthetic private", json.dumps(result))
            self.assertEqual(["git", "--version"], run.call_args.args[0])

    def test_observed_xcode_select_no_tools_error_has_bounded_diagnostic(self) -> None:
        message = ("xcode-select: error: No developer tools were found and no install could be requested "
                   "(possibly because there is no active GUI session).")
        self.assertEqual("developer_tools_unavailable", self.module.command_diagnostic(message))
        self.assertEqual("unclassified_command_error", self.module.command_diagnostic("No developer tools were found"))

    def test_summary_retains_typed_command_diagnostics_but_not_arbitrary_values(self) -> None:
        receipt = self.receipt()
        check = next(item for item in receipt["checks"] if item["name"] == "git version")
        check.update({"passed": False, "category": "command_failed", "returncode": 72,
                      "signature_valid": False, "diagnostic": "developer_tools_unavailable", "executable": "/usr/bin/git",
                      "stderr": "synthetic private"})
        result = self.module.summarize_receipt(receipt, "synthetic-nonce")
        projected = next(item for item in result["checks"] if item["name"] == "git version")
        self.assertEqual({"name": "git version", "passed": False, "category": "command_failed", "returncode": 72,
                          "signature_valid": False, "git_access_warning": False,
                          "diagnostic": "developer_tools_unavailable", "executable": "/usr/bin/git"}, projected)
        self.assertNotIn("synthetic private", json.dumps(result))
        check.update({"returncode": "synthetic private", "signature_valid": 1, "git_access_warning": "synthetic private",
                      "diagnostic": "synthetic private", "executable": "/synthetic private/data"})
        result = self.module.summarize_receipt(receipt, "synthetic-nonce")
        self.assertNotIn("synthetic private", json.dumps(result))
        projected = next(item for item in result["checks"] if item["name"] == "git version")
        self.assertEqual({"name": "git version", "passed": False, "category": "command_failed"}, projected)

    def test_launcher_os_error_retains_errno_without_claiming_sandbox_cause(self) -> None:
        with patch.object(self.module, "run_process", side_effect=PermissionError(errno.EPERM, "synthetic private path")), \
                patch.object(self.module, "resolved_tool_executable", return_value="/usr/bin/git"):
            result = self.module.command_result("git version", ["git", "--version"], Path("/synthetic"))
        self.assertEqual(errno.EPERM, result["errno"])
        self.assertEqual("system_access_denied", result["diagnostic"])
        self.assertNotIn("synthetic private", json.dumps(result))
        receipt = self.receipt()
        receipt["checks"] = [result if item["name"] == "git version" else item for item in receipt["checks"]]
        summary = self.module.summarize_receipt(receipt, "synthetic-nonce")
        self.assertEqual(errno.EPERM, next(item for item in summary["checks"] if item["name"] == "git version")["errno"])

    def test_safe_summary_never_copies_unknown_fields_or_category_values(self) -> None:
        receipt = self.receipt()
        receipt["checks"][0].update({"passed": "synthetic secret", "category": "synthetic secret", "stdout": "synthetic secret"})
        receipt["checks"].append({"name": "synthetic secret", "passed": True, "category": "synthetic secret"})
        summary = self.module.summarize_receipt(receipt, "synthetic-nonce")
        self.assertFalse(summary["passed"])
        self.assertNotIn("synthetic secret", json.dumps(summary))
        self.assertEqual(set(self.module.CHECKS), {item["name"] for item in summary["checks"]})

    def test_safe_summary_wrong_nonce_marks_every_check_unverified(self) -> None:
        summary = self.module.summarize_receipt(self.receipt(), "wrong")
        self.assertFalse(summary["passed"])
        self.assertTrue(all(not item["passed"] and item["category"] == "invalid_receipt" for item in summary["checks"]))

    def test_missing_duplicate_and_unknown_cases_fail(self) -> None:
        for change in ("missing", "duplicate", "unknown"):
            receipt = self.receipt()
            if change == "missing":
                receipt["checks"].pop()
            elif change == "duplicate":
                receipt["checks"][-1] = copy.deepcopy(receipt["checks"][0])
            else:
                receipt["checks"][-1]["name"] = "unreviewed operation"
            self.assertFalse(self.module.validate_receipt(receipt, "synthetic-nonce"))

    def test_wrong_nonce_protocol_or_extra_content_fails(self) -> None:
        for change in ({"nonce": "wrong"}, {"protocol": "wrong"}, {"stdout": "synthetic private data"}):
            receipt = self.receipt()
            receipt.update(change)
            self.assertFalse(self.module.validate_receipt(receipt, "synthetic-nonce"))

    def test_denial_requires_actual_permission_errno_and_boolean_proof(self) -> None:
        for changes in ({"errno": errno.ENOENT}, {"errno": True}, {"errno": "13"},
                        {"success": True}, {"success": 0}, {"passed": 1},
                        {"category": "unavailable"}, {"operation": "write"},
                        {"stderr": "synthetic private data"}):
            receipt = self.receipt()
            next(check for check in receipt["checks"] if check["name"] == "outside direct read").update(changes)
            self.assertFalse(self.module.validate_receipt(receipt, "synthetic-nonce"))

    def test_failed_command_or_missing_version_signature_cannot_pass(self) -> None:
        for changes in ({"returncode": 1}, {"returncode": False}, {"signature_valid": False},
                        {"signature_valid": 1}, {"passed": False}):
            receipt = self.receipt()
            next(check for check in receipt["checks"] if check["name"] == "python3 version").update(changes)
            self.assertFalse(self.module.validate_receipt(receipt, "synthetic-nonce"))

    def test_allowed_access_requires_success_without_errno(self) -> None:
        for changes in ({"success": False}, {"errno": errno.EACCES}, {"operation": "rename"}):
            receipt = self.receipt()
            receipt["checks"][0].update(changes)
            self.assertFalse(self.module.validate_receipt(receipt, "synthetic-nonce"))

    def test_access_result_never_returns_content(self) -> None:
        target = MagicMock()
        target.open.return_value.__enter__.return_value.read.return_value = b"synthetic private data"
        self.assertEqual({"success": True, "errno": None}, self.module.read_access(target))
        target.open.assert_called_once_with("rb")

    def test_access_result_preserves_permission_errno(self) -> None:
        target = MagicMock()
        target.open.side_effect = PermissionError(errno.EPERM, "synthetic private path")
        self.assertEqual({"success": False, "errno": errno.EPERM}, self.module.read_access(target))

    def test_command_error_does_not_return_raw_output(self) -> None:
        with patch.object(self.module, "run_process", return_value=
                          subprocess.CompletedProcess([], 127, "private stdout", "private stderr")):
            result = self.module.command_result("python3 version", ["python3", "--version"], Path("/synthetic"))
        self.assertFalse(result["passed"])
        self.assertNotIn("private", json.dumps(result))

    def test_version_command_requires_its_real_output_signature(self) -> None:
        for stdout, expected in (("Python 3.13.2\n", True), ("", False), ("unrelated program\n", False)):
            with patch.object(self.module, "run_process", return_value=subprocess.CompletedProcess([], 0, stdout, "")):
                result = self.module.command_result("python3 version", ["python3", "--version"], Path("/synthetic"))
            self.assertIs(expected, result["passed"])

    def test_zero_exit_git_ignore_access_warnings_are_failures_without_raw_paths(self) -> None:
        for name, argv in (("git status", ["git", "status", "--short"]),
                           ("make publish-guard", ["make", "publish-guard"])):
            for error in ("Operation not permitted", "Permission denied"):
                with self.subTest(name=name, error=error):
                    stderr = f"warning: unable to access '/synthetic private/.gitignore_global': {error}\n"
                    with patch.object(self.module, "resolved_tool_executable", return_value=None), \
                            patch.object(self.module, "run_process", return_value=subprocess.CompletedProcess([], 0, "", stderr)):
                        proof = self.module.command_result(name, argv, Path("/synthetic"))
                    self.assertFalse(proof["passed"])
                    self.assertEqual("command_failed", proof["category"])
                    self.assertEqual("system_access_denied", proof["diagnostic"])
                    self.assertIs(True, proof["git_access_warning"])
                    self.assertNotIn("synthetic private", json.dumps(proof))

    def test_zero_exit_ordinary_stderr_and_protected_template_diagnostics_remain_tolerated(self) -> None:
        for stderr in ("notice: synthetic development warning\n",
                       ".env.example: Operation not permitted\n.env.production.example: Operation not permitted\n",
                       "warning: unable to access '/synthetic/.gitignore_global': No such file or directory\n"):
            with self.subTest(stderr=stderr), patch.object(self.module, "resolved_tool_executable", return_value=None), \
                    patch.object(self.module, "run_process", return_value=subprocess.CompletedProcess([], 0, "", stderr)):
                proof = self.module.command_result("git status", ["git", "status", "--short"], Path("/synthetic"))
            self.assertTrue(proof["passed"])
            self.assertIs(False, proof["git_access_warning"])
            self.assertNotIn("synthetic development", json.dumps(proof))

    def test_success_requires_typed_negative_git_access_warning_proof(self) -> None:
        for value in (None, True, 0, "false", [], {}):
            receipt = self.receipt()
            check = next(item for item in receipt["checks"] if item["name"] == "git status")
            if value is None:
                check.pop("git_access_warning")
            else:
                check["git_access_warning"] = value
            self.assertFalse(self.module.validate_receipt(receipt, "synthetic-nonce"))

    def test_old_development_protocol_cannot_claim_new_hash_and_warning_checks(self) -> None:
        for workspace_only, protocol in ((False, "artifactflow-development-v2"),
                                         (True, "artifactflow-workspace-development-v2")):
            payload = self.workspace_receipt() if workspace_only else self.receipt()
            payload["protocol"] = protocol
            self.assertFalse(self.module.validate_receipt(payload, "synthetic-nonce", workspace_only=workspace_only))

    def test_shasum_checks_require_exact_digest_and_manifest_success(self) -> None:
        fixture = Path("/synthetic owned fixture")
        manifest = hashlib.sha256(self.module.SENTINEL).hexdigest() + "  source.txt\n"
        outputs = (subprocess.CompletedProcess([], 0, manifest, ""),
                   subprocess.CompletedProcess([], 0, "source.txt: OK\n", ""))
        with patch.object(self.module, "resolved_tool_executable", return_value="/usr/bin/shasum"), \
                patch.object(self.module, "run_process", side_effect=outputs) as run:
            checks = self.module.checksum_checks(fixture)
        self.assertEqual(["shasum owned SHA256", "shasum owned manifest"], [item["name"] for item in checks])
        self.assertTrue(all(self.module.successful_check(item) for item in checks))
        self.assertEqual(["/usr/bin/shasum", "-a", "256", "source.txt"], run.call_args_list[0].args[0])
        self.assertEqual(["/usr/bin/shasum", "-a", "256", "-c", "-"], run.call_args_list[1].args[0])
        self.assertEqual(manifest, run.call_args_list[1].kwargs["stdin_text"])
        self.assertEqual([fixture, fixture], [item.args[1] for item in run.call_args_list])

    def test_shasum_version_wrong_digest_extra_output_or_missing_manifest_confirmation_fails(self) -> None:
        valid_digest = hashlib.sha256(self.module.SENTINEL).hexdigest() + "  source.txt\n"
        for digest, verified in (("6.02\n", "source.txt: OK\n"),
                                 ("0" * 64 + "  source.txt\n", "source.txt: OK\n"),
                                 (valid_digest + "unrelated\n", "source.txt: OK\n"),
                                 (valid_digest, ""), (valid_digest, "source.txt: OK\nunrelated\n")):
            with self.subTest(digest=digest, verified=verified), \
                    patch.object(self.module, "resolved_tool_executable", return_value=None), \
                    patch.object(self.module, "run_process", side_effect=[subprocess.CompletedProcess([], 0, digest, ""),
                                                                          subprocess.CompletedProcess([], 0, verified, "")]):
                checks = self.module.checksum_checks(Path("/synthetic/fixture"))
            self.assertFalse(all(item["passed"] for item in checks))
            self.assertNotIn("unrelated", json.dumps(checks))

    def test_shasum_unavailable_or_denied_fails_without_fallback_or_raw_error(self) -> None:
        for error in (FileNotFoundError(errno.ENOENT, "synthetic private runtime"),
                      PermissionError(errno.EPERM, "synthetic private runtime")):
            with patch.object(self.module, "resolved_tool_executable", return_value="/usr/bin/shasum"), \
                    patch.object(self.module, "run_process", side_effect=error) as run:
                checks = self.module.checksum_checks(Path("/synthetic/fixture"))
            self.assertTrue(all(not item["passed"] for item in checks))
            self.assertEqual(2, run.call_count)
            self.assertEqual(["/usr/bin/shasum", "/usr/bin/shasum"], [item.args[0][0] for item in run.call_args_list])
            self.assertTrue(all(item["executable"] == "/usr/bin/shasum" for item in checks))
            self.assertNotIn("synthetic private", json.dumps(checks))

    def test_shasum_executable_metadata_is_reviewed_and_redacted(self) -> None:
        self.assertEqual("/usr/bin/shasum", self.module.safe_tool_executable("/usr/bin/shasum", "shasum"))
        self.assertIsNone(self.module.safe_tool_executable("/private personal/shasum", "shasum"))
        self.assertIsNone(self.module.safe_tool_executable("/usr/bin/perl", "shasum"))
        with patch.object(self.module.shutil, "which", return_value="/usr/bin/shasum"), \
                patch.object(Path, "resolve", return_value=Path("/usr/bin/shasum")):
            self.assertEqual("/usr/bin/shasum", self.module.resolved_tool_executable("shasum"))

    def test_process_forwards_owned_manifest_on_stdin_without_environment_overrides(self) -> None:
        process = MagicMock(pid=845, returncode=0)
        process.communicate.return_value = ("source.txt: OK\n", "")
        manifest = hashlib.sha256(self.module.SENTINEL).hexdigest() + "  source.txt\n"
        with patch.object(self.module.subprocess, "Popen", return_value=process) as launch, \
                patch.object(self.module, "stop_owned_process_group"):
            result = self.module.run_process(["shasum", "-a", "256", "-c", "-"],
                                             Path("/synthetic/fixture"), 15, stdin_text=manifest)
        self.assertEqual(subprocess.PIPE, launch.call_args.kwargs["stdin"])
        self.assertNotIn("env", launch.call_args.kwargs)
        self.assertEqual("source.txt: OK\n", result.stdout)
        process.communicate.assert_called_once_with(input=manifest, timeout=15)

    def test_missing_command_and_timeout_are_failures(self) -> None:
        for error in (FileNotFoundError("private path"), subprocess.TimeoutExpired("private command", 15)):
            with patch.object(self.module, "run_process", side_effect=error):
                result = self.module.command_result("git version", ["git", "--version"], Path("/synthetic"))
            self.assertFalse(result["passed"])
            self.assertNotIn("private", json.dumps(result))

    def test_child_receipt_needs_exact_nonce_depth_and_permission_error(self) -> None:
        marker = {"protocol": "artifactflow-development-child-v1", "nonce": "synthetic-nonce",
                  "depth": 0, "operation": "read", "success": False, "errno": errno.EACCES}
        for changes, expected in (({}, True), ({"nonce": "wrong"}, False), ({"depth": 1}, False),
                                  ({"errno": errno.ENOENT}, False), ({"success": "false"}, False)):
            candidate = {**marker, **changes}
            stdout = self.module.CHILD_PREFIX + json.dumps(candidate)
            with patch.object(Path, "resolve", return_value=Path("/synthetic/canonical-python")), \
                    patch.object(self.module, "run_process", return_value=subprocess.CompletedProcess([], 0, stdout, "")) as launch:
                result = self.module.run_child_read(Path("/synthetic/outside"), "synthetic-nonce", 1)
            self.assertIs(expected, result["success"] is False and result.get("errno") == errno.EACCES)
            self.assertEqual("/synthetic/canonical-python", launch.call_args.args[0][0])
            self.assertNotIn("-c", launch.call_args.args[0])

    def test_child_launcher_failure_and_narrative_are_not_denials(self) -> None:
        for returncode, stdout in ((126, "Permission denied"), (0, "the check passed"),
                                   (0, self.module.CHILD_PREFIX + "{}")):
            with patch.object(self.module, "run_process", return_value=subprocess.CompletedProcess([], returncode, stdout, "")):
                result = self.module.run_child_read(Path("/synthetic/outside"), "synthetic-nonce", 1)
            self.assertIs(result["success"], False)
            self.assertIsNone(result["errno"])

    def test_process_timeout_terminates_only_owned_group_and_never_passes(self) -> None:
        error = subprocess.TimeoutExpired("synthetic", 15)
        process = MagicMock(pid=845, returncode=0)
        process.communicate.side_effect = [error, ("", "")]
        with patch.object(self.module.subprocess, "Popen", return_value=process) as launch, \
                patch.object(self.module, "stop_owned_process_group") as cleanup, \
                self.assertRaises(subprocess.TimeoutExpired):
            self.module.run_process(["synthetic"], Path("/synthetic"), 15)
        self.assertTrue(launch.call_args.kwargs["start_new_session"])
        cleanup.assert_called_once_with(process)

    def test_cleanup_failure_cannot_return_a_successful_command(self) -> None:
        process = MagicMock(pid=845, returncode=0)
        process.communicate.return_value = ("synthetic output", "")
        with patch.object(self.module.subprocess, "Popen", return_value=process), \
                patch.object(self.module, "stop_owned_process_group", side_effect=PermissionError("synthetic denial")), \
                self.assertRaises(PermissionError):
            self.module.run_process(["synthetic"], Path("/synthetic"), 15)
        for stream in (process.stdin, process.stdout, process.stderr):
            stream.close.assert_called_once_with()

    def test_interrupt_reaps_owned_command_and_restores_signal_handlers(self) -> None:
        process = MagicMock(pid=845)
        process.communicate.side_effect = [KeyboardInterrupt(), ("", "")]
        before = {sig: signal.getsignal(sig) for sig in (signal.SIGTERM, signal.SIGINT)}
        with patch.object(self.module.subprocess, "Popen", return_value=process), \
                patch.object(self.module, "stop_owned_process_group") as cleanup, self.assertRaises(KeyboardInterrupt):
            self.module.run_process(["synthetic"], Path("/synthetic"), 15)
        cleanup.assert_called_once_with(process)
        self.assertEqual(1, process.communicate.call_count)
        self.assertEqual(before, {sig: signal.getsignal(sig) for sig in before})

    def test_term_of_probe_reaps_its_active_owned_sleep_command(self) -> None:
        # No client or filesystem fixture: this helper owns only a sleeping Python.
        # The pipe reports that exact child's PID; cleanup never scans other tasks.
        script = '''
import os, subprocess, sys
from pathlib import Path
sys.path.insert(0, sys.argv[1])
import development_probe
original = subprocess.Popen
def launch(*args, **kwargs):
    child = original(*args, **kwargs)
    os.write(int(sys.argv[2]), (str(child.pid) + "\\n").encode())
    os.close(int(sys.argv[2]))
    return child
development_probe.subprocess.Popen = launch
development_probe.run_process([sys.executable, "-I", "-B", "-c", "import time; time.sleep(30)"], Path.cwd(), 60)
'''
        reader, writer = os.pipe()
        helper = None
        child_pid = None
        try:
            helper = subprocess.Popen(
                [sys.executable, "-I", "-B", "-c", script, str(Path(__file__).parent), str(writer)],
                pass_fds=(writer,), start_new_session=True,
                stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
            )
            os.close(writer)
            writer = None
            with selectors.DefaultSelector() as ready:
                ready.register(reader, selectors.EVENT_READ)
                self.assertTrue(ready.select(5), "Owned child did not become ready")
                child_pid = int(os.read(reader, 100).strip())
            os.kill(helper.pid, signal.SIGTERM)
            helper.wait(timeout=8)
            self.assertEqual(128 + signal.SIGTERM, helper.returncode, helper.stderr.read().decode())
            with self.assertRaises(ProcessLookupError):
                os.killpg(child_pid, 0)
        finally:
            os.close(reader)
            if writer is not None:
                os.close(writer)
            for pid in (child_pid, helper.pid if helper is not None else None):
                if pid is not None:
                    try:
                        os.killpg(pid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass
            if helper is not None:
                helper.wait(timeout=5)
                helper.stderr.close()

    def test_successful_process_returns_output_only_to_internal_caller(self) -> None:
        process = MagicMock(pid=845, returncode=0)
        process.communicate.return_value = ("synthetic output", "synthetic stderr")
        with patch.object(self.module.subprocess, "Popen", return_value=process), \
                patch.object(self.module, "stop_owned_process_group") as cleanup:
            result = self.module.run_process(["synthetic"], Path("/synthetic"), 15)
        self.assertEqual("synthetic output", result.stdout)
        self.assertEqual(0, result.returncode)
        cleanup.assert_called_once_with(process)

    def test_isolated_cli_loads_only_the_adjacent_cleanup_helper(self) -> None:
        result = subprocess.run(
            [str(Path(sys.executable).resolve()), "-I", "-B", str(Path(__file__).with_name("development_probe.py")), "--help"],
            stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=5, check=False,
        )
        self.assertEqual(0, result.returncode)
        self.assertIn("--workspace-only", result.stdout)
        self.assertEqual("", result.stderr)

    def test_fixture_layout_checks_owned_content_without_reading_outside(self) -> None:
        project, fixture, outside = Path("/synthetic/project"), Path("/synthetic/project/fixture"), Path("/synthetic/outside")
        with patch.object(Path, "resolve", autospec=True, side_effect=lambda path, strict: path), \
                patch.object(Path, "is_file", return_value=True), \
                patch.object(Path, "is_symlink", autospec=True, side_effect=lambda path: path.name == "outside-alias"), \
                patch.object(Path, "readlink", return_value=outside), \
                patch.object(Path, "read_bytes", autospec=True, return_value=self.module.SENTINEL) as read:
            self.module.verify_layout(project, fixture, outside)
        self.assertEqual([fixture / "source.txt", fixture / "edit.txt"],
                         [item.args[0] for item in read.call_args_list])

    def test_fixture_layout_rejects_outside_fixture_or_inside_denial_target(self) -> None:
        cases = ((Path("/synthetic/project"), Path("/synthetic/elsewhere"), Path("/synthetic/outside")),
                 (Path("/synthetic/project"), Path("/synthetic/project/fixture"), Path("/synthetic/project/inside")))
        for project, fixture, outside in cases:
            with patch.object(Path, "resolve", autospec=True, side_effect=lambda path, strict: path), \
                    patch.object(Path, "read_bytes") as read, self.assertRaises(ValueError):
                self.module.verify_layout(project, fixture, outside)
            read.assert_not_called()

    def test_batch_runs_reviewed_commands_only_and_emits_no_raw_output(self) -> None:
        project, fixture, outside = Path("/synthetic/project"), Path("/synthetic/project/fixture"), Path("/synthetic/outside")
        def command(name, argv, cwd, timeout=15, *, tool=None, expected_stdout=None, stdin_text=None):
            result = {"name": name, "passed": True, "category": "command_completed", "returncode": 0,
                      "signature_valid": True, "git_access_warning": False}
            return {**result, "tool": tool} if tool is not None else result
        with patch.object(self.module, "verify_layout"), \
                patch.object(self.module, "read_access", return_value={"success": True, "errno": None}), \
                patch.object(self.module, "owned_write", return_value={"success": True, "errno": None}), \
                patch.object(self.module, "owned_read", return_value={"success": True, "errno": None}), \
                patch.object(self.module, "owned_rename", return_value={"success": True, "errno": None}), \
                patch.object(self.module.shutil, "which", return_value="/usr/bin/rg"), \
                patch.object(self.module, "run_child_read", return_value={"success": False, "errno": errno.EACCES}), \
                patch.object(self.module, "command_result", side_effect=command) as commands:
            result = self.module.run_checks(project, fixture, outside, "synthetic-nonce")
        argv = [item.args[1] for item in commands.call_args_list]
        self.assertIn(["python3", "--version"], argv)
        self.assertIn(["make", "publish-guard"], argv)
        self.assertIn(["make", "ai-hooks-test"], argv)
        self.assertIn(["git", "--no-optional-locks", "status", "--short", "--untracked-files=no"], argv)
        self.assertIn(["/usr/bin/shasum", "-a", "256", "source.txt"], argv)
        self.assertIn(["/usr/bin/shasum", "-a", "256", "-c", "-"], argv)
        self.assertFalse(any(item[0] in {"node", "npm", "docker", "php"} for item in argv))
        self.assertEqual(19, len(result["checks"]))
        self.assertEqual("artifactflow-development-v3", result["protocol"])

    def test_missing_ripgrep_selects_system_grep_before_any_command(self) -> None:
        outputs = [subprocess.CompletedProcess([], 0, "grep (BSD grep, GNU compatible) 2.6.0-FreeBSD\n", ""),
                   subprocess.CompletedProcess([], 0, self.module.SENTINEL.decode(), "")]
        with patch.object(self.module.shutil, "which", side_effect=lambda name: None if name == "rg" else "/usr/bin/grep"), \
                patch.object(Path, "resolve", return_value=Path("/usr/bin/grep")), \
                patch.object(self.module, "run_process", side_effect=outputs) as run:
            checks = self.module.search_checks(Path("/project"), Path("/project/fixture"))
        self.assertEqual(["search tool version", "owned sentinel search"], [check["name"] for check in checks])
        self.assertTrue(all(self.module.successful_check(check) and check["tool"] == "grep" for check in checks))
        self.assertEqual(["/usr/bin/grep", "--version"], run.call_args_list[0].args[0])
        self.assertEqual(["/usr/bin/grep", "-F", "--", self.module.SENTINEL.decode().strip(), "/project/fixture/source.txt"], run.call_args_list[1].args[0])

    def test_available_ripgrep_failure_never_falls_back_to_grep(self) -> None:
        with patch.object(self.module.shutil, "which", return_value="/opt/homebrew/bin/rg") as which, \
                patch.object(Path, "resolve", return_value=Path("/opt/homebrew/bin/rg")), \
                patch.object(self.module, "run_process", return_value=subprocess.CompletedProcess([], 126, "", "Permission denied")) as run:
            checks = self.module.search_checks(Path("/project"), Path("/project/fixture"))
        self.assertTrue(all(not check["passed"] and check["tool"] == "rg" for check in checks))
        self.assertTrue(all(item.args[0][0] == "/opt/homebrew/bin/rg" for item in run.call_args_list))
        self.assertNotIn("/usr/bin/grep", [item.args[0] for item in which.call_args_list])
        self.assertEqual(2, run.call_count)

    def test_missing_both_search_tools_fails_without_launch(self) -> None:
        with patch.object(self.module.shutil, "which", return_value=None), patch.object(self.module, "run_process") as run:
            checks = self.module.search_checks(Path("/project"), Path("/project/fixture"))
        run.assert_not_called()
        self.assertEqual(2, len(checks))
        self.assertTrue(all(not check["passed"] and check["diagnostic"] == "command_missing" for check in checks))

    def test_search_receipt_requires_consistent_typed_tool_choice(self) -> None:
        for tool in (None, "sed", 1, [], {}):
            receipt = self.receipt()
            next(item for item in receipt["checks"] if item["name"] == "search tool version")["tool"] = tool
            self.assertFalse(self.module.validate_receipt(receipt, "synthetic-nonce"))
        receipt = self.receipt()
        next(item for item in receipt["checks"] if item["name"] == "search tool version")["tool"] = "grep"
        self.assertFalse(self.module.validate_receipt(receipt, "synthetic-nonce"))
        for item in receipt["checks"]:
            if item["name"] in {"search tool version", "owned sentinel search"}:
                item["tool"] = "grep"
        self.assertTrue(self.module.validate_receipt(receipt, "synthetic-nonce"))
        summary = self.module.summarize_receipt(receipt, "synthetic-nonce")
        self.assertTrue(all(item["tool"] == "grep" for item in summary["checks"] if item["name"] in {"search tool version", "owned sentinel search"}))

    def test_search_version_requires_selected_tool_signature(self) -> None:
        for tool, stdout, expected in (("grep", "grep (GNU grep) 3.11\n", True),
                                       ("rg", "ripgrep 14.1.1\n", True),
                                       ("grep", "ripgrep 14.1.1\n", False),
                                       ("rg", "grep (GNU grep) 3.11\n", False)):
            with patch.object(self.module, "resolved_tool_executable", return_value=None), \
                    patch.object(self.module, "run_process", return_value=subprocess.CompletedProcess([], 0, stdout, "")):
                result = self.module.command_result("search tool version", [tool, "--version"], Path("/project"), tool=tool)
            self.assertIs(expected, result["passed"])

    def test_invalid_fixture_layout_prevents_every_operation(self) -> None:
        with patch.object(self.module, "verify_layout", side_effect=ValueError("synthetic private path")), \
                patch.object(self.module, "read_access") as read, \
                patch.object(self.module, "owned_write") as write, \
                patch.object(self.module, "command_result") as commands:
            result = self.module.run_checks(Path("/project"), Path("/project/fixture"), Path("/outside"), "synthetic-nonce")
        read.assert_not_called()
        write.assert_not_called()
        commands.assert_not_called()
        self.assertFalse(self.module.validate_receipt(result, "synthetic-nonce"))
        self.assertNotIn("synthetic private path", json.dumps(result))

    def test_owned_write_requires_expected_sentinel_before_mutation(self) -> None:
        target = MagicMock()
        target.read_bytes.return_value = b"unrelated original content"
        result = self.module.owned_write(target)
        self.assertFalse(result["success"])
        target.write_bytes.assert_not_called()

    def test_owned_rename_refuses_to_overwrite_and_restores_original_name(self) -> None:
        target, destination = MagicMock(), MagicMock()
        target.read_bytes.return_value = self.module.SENTINEL
        destination.is_symlink.return_value = False
        destination.exists.return_value = True
        self.assertFalse(self.module.owned_rename(target, destination)["success"])
        target.rename.assert_not_called()
        destination.exists.return_value = False
        self.assertTrue(self.module.owned_rename(target, destination)["success"])
        target.rename.assert_called_once_with(destination)
        destination.rename.assert_called_once_with(target)

    def test_main_outputs_one_prefixed_receipt_and_failure_exit(self) -> None:
        receipt = self.receipt()
        receipt["checks"][0]["passed"] = False
        output = io.StringIO()
        with patch.object(self.module, "run_checks", return_value=receipt), patch.object(sys, "stdout", output):
            result = self.module.main(["--project", "/synthetic/project", "--fixture", "/synthetic/project/fixture",
                                       "--outside", "/synthetic/outside", "--nonce", "synthetic-nonce"])
        self.assertEqual(1, result)
        self.assertEqual(self.module.PREFIX + json.dumps(receipt, sort_keys=True), output.getvalue().strip())


if __name__ == "__main__":
    unittest.main(verbosity=2)
