#!/usr/bin/env python3
"""Host coordinator regressions; no client, model, or native sandbox launches."""
from __future__ import annotations

import importlib
import io
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch


class DevelopmentRunnerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.runner = importlib.import_module("run_development")

    def test_requires_explicit_run_before_creating_fixtures_or_sessions(self) -> None:
        with patch.object(sys, "argv", ["run_development"]), \
                patch.object(self.runner, "create_fixtures") as fixtures, \
                patch.object(self.runner, "run_codex_session") as codex, \
                patch.object(self.runner, "run_claude_session") as claude, \
                patch.object(sys, "stdout", new=io.StringIO()):
            self.assertEqual(2, self.runner.main())
        fixtures.assert_not_called()
        codex.assert_not_called()
        claude.assert_not_called()

    def test_missing_or_failed_client_evidence_cannot_pass(self) -> None:
        self.assertFalse(self.runner.sessions_passed({}, ("codex", "claude")))
        self.assertFalse(self.runner.sessions_passed({"codex": {"passed": True}}, ("codex", "claude")))
        self.assertFalse(self.runner.sessions_passed({"codex": {"passed": 1}}, ("codex",)))
        self.assertFalse(self.runner.sessions_passed({"codex": {"passed": False}}, ("codex",)))
        self.assertTrue(self.runner.sessions_passed({"codex": {"passed": True}}, ("codex",)))

    def test_inside_and_native_temp_outside_roots_are_rejected_before_creation(self) -> None:
        project = Path("/synthetic/project")
        for parent in (project, project / "outside", Path("/tmp"), Path(tempfile.gettempdir())):
            with self.subTest(parent=parent), patch.object(Path, "mkdir") as mkdir:
                with self.assertRaises(ValueError):
                    self.runner.validate_outside_parent(project, parent)
                mkdir.assert_not_called()

    def test_probe_argv_uses_canonical_orchestrator_but_preserves_actual_project_and_targets(self) -> None:
        with patch.object(Path, "resolve", return_value=Path("/runtime/python3")):
            command = self.runner.probe_command(Path("/project with spaces"), Path("/owned fixture"),
                                                Path("/outside/sentinel.txt"), "nonce")
        self.assertEqual("/runtime/python3", command[0])
        self.assertEqual(["-I", "-B"], command[1:3])
        self.assertEqual("/project with spaces", command[command.index("--project") + 1])
        self.assertEqual("/outside/sentinel.txt", command[command.index("--outside") + 1])
        self.assertNotIn("-c", command)

    def test_workspace_probe_has_no_outside_argument_or_hidden_target(self) -> None:
        with patch.object(Path, "resolve", return_value=Path("/runtime/python3")):
            command = self.runner.probe_command(Path("/project"), Path("/owned fixture"),
                                                Path("/outside/sentinel.txt"), "nonce", workspace_only=True)
        self.assertEqual(1, command.count("--workspace-only"))
        self.assertNotIn("--outside", command)
        self.assertNotIn("/outside/sentinel.txt", command)
        self.assertEqual("nonce", command[command.index("--nonce") + 1])

    def test_each_client_gets_its_explicit_probe_scope_and_validator(self) -> None:
        import development_probe
        with tempfile.TemporaryDirectory() as directory:
            project = Path(directory).resolve()
            fixture = project / "fixture"
            fixture.mkdir()
            outside = Path("/outside/sentinel.txt")
            codex_result = {"passed": True, "command_receipt": {"client": "codex"}}
            claude_result = {"passed": True, "shell_receipt": {"client": "claude"}}
            with patch.object(sys, "argv", ["run_development", "--run", "--project-root", str(project)]), \
                    patch.object(self.runner, "snapshot_controls", return_value={}), \
                    patch.object(self.runner, "create_fixtures", return_value=(fixture, outside)), \
                    patch.object(self.runner, "run_codex_session", return_value=codex_result) as codex, \
                    patch.object(self.runner, "run_claude_session", return_value=claude_result) as claude, \
                    patch.object(development_probe, "validate_receipt", return_value=True) as validate, \
                    patch.object(development_probe, "summarize_receipt", return_value={}) as summarize, \
                    patch.object(sys, "stdout", new=io.StringIO()):
                self.assertEqual(0, self.runner.main())
            self.assertIn("--outside", codex.call_args.kwargs["command"])
            self.assertNotIn("--workspace-only", codex.call_args.kwargs["command"])
            self.assertIn("--workspace-only", claude.call_args.args[2])
            self.assertNotIn(str(outside), claude.call_args.args[2])
            self.assertEqual(outside, claude.call_args.kwargs["outside_path"])
            self.assertEqual("artifactflow-workspace-development-v3", claude.call_args.args[3]["protocol"])
            for calls in (validate.call_args_list, summarize.call_args_list):
                self.assertEqual([False, True], [call.kwargs["workspace_only"] for call in calls])

    def test_snapshot_retains_digests_not_source_contents(self) -> None:
        with patch.object(self.runner, "CONTROL_FILES", (".codex/config.toml",)), \
                patch.object(self.runner, "list_owned_files", return_value=[]), \
                patch.object(self.runner, "read_owned_bytes", return_value=b"synthetic private configuration value"):
            snapshot = self.runner.snapshot_controls(Path("/project"))
        self.assertEqual({".codex/config.toml"}, set(snapshot))
        self.assertEqual(64, len(snapshot[".codex/config.toml"]))
        self.assertNotIn("synthetic", str(snapshot))

    def test_failed_command_diagnostics_are_visible_without_a_false_pass(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            project = Path(directory).resolve()
            output = io.StringIO()
            result = {"passed": False, "command": {"summary": {"checks": [{
                "name": "git version", "passed": False, "category": "command_failed",
                "returncode": 1, "signature_valid": False, "executable": "/usr/bin/git",
                "diagnostic": "developer_tools_unavailable",
            }]}}}
            with patch.object(sys, "argv", ["run_development", "--run", "--client", "codex",
                                             "--project-root", str(project)]), \
                    patch.object(self.runner, "snapshot_controls", return_value={}), \
                    patch.object(self.runner, "create_fixtures", return_value=(project, Path("/outside/sentinel.txt"))), \
                    patch.object(self.runner, "run_codex_session", return_value=result), \
                    patch.object(sys, "stdout", new=output):
                self.assertEqual(1, self.runner.main())
            self.assertIn("returncode=1", output.getvalue())
            self.assertIn("developer_tools_unavailable", output.getvalue())
            self.assertIn("/usr/bin/git", output.getvalue())

    def test_snapshot_includes_executed_make_and_python_inputs(self) -> None:
        with patch.object(self.runner, "read_owned_bytes", return_value=b"input"), \
                patch.object(self.runner, "list_owned_files", return_value=[Path("/project/scripts/agent-containment/test_new.py")]):
            snapshot = self.runner.snapshot_controls(Path("/project"))
        for name in ("Makefile", "scripts/publish-guard.sh", "scripts/ai-hooks/run_harness.py",
                     "scripts/agent-containment/test_new.py"):
            self.assertIn(name, snapshot)


if __name__ == "__main__":
    unittest.main(verbosity=2)
