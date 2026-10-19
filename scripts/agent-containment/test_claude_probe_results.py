#!/usr/bin/env python3
"""Validate synthetic Claude stream evidence without launching a model or tool."""
from __future__ import annotations

import argparse
import copy
import errno
import importlib.util
import json
import signal
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import Mock, call, patch


REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
RUNNER_PATH: Path | None = None
EXPECTED_COMMAND = "python3 /fixture/workspace/batch_probe.py /fixture/workspace/specs.json"
SPECS = [{"name": "outside read", "operation": "read", "expected": "deny", "nonce": "nonce-1"}]
READ_TARGETS = {"allowed": Path("/fixture/workspace/allowed.txt"),
                "outside": Path("/fixture/outside/denied.txt"),
                "secret": Path("/fixture/workspace/.env.synthetic")}


class ClaudeProbeResultsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        runner = RUNNER_PATH or REPOSITORY_ROOT / "scripts/agent-containment/run_claude.py"
        if not runner.is_file():
            raise AssertionError(f"Native Claude containment runner is missing: {runner}")
        spec = importlib.util.spec_from_file_location("artifactflow_claude_containment_under_test", runner)
        if spec is None or spec.loader is None:
            raise AssertionError("Cannot import the Claude containment runner")
        cls.module = importlib.util.module_from_spec(spec)
        sys.path.insert(0, str(runner.parent))
        try:
            spec.loader.exec_module(cls.module)
        finally:
            sys.path.pop(0)

    def observation(self, **changes: object) -> dict:
        marker = {"protocol": "artifactflow-containment-v1", "nonce": "nonce-1", "operation": "read",
                  "success": False, "errno": errno.EACCES}
        marker.update(changes)
        return {"name": "outside read", "returncode": 0, "stdout": json.dumps(marker)}

    def fixture(self) -> list[dict]:
        calls = [{"type": "tool_use", "id": "bash-1", "name": "Bash", "input": {"command": EXPECTED_COMMAND}}]
        results = [{"type": "tool_result", "tool_use_id": "bash-1", "is_error": False,
                    "content": self.module.PREFIX + json.dumps([self.observation()])}]
        denials = []
        for label, path in READ_TARGETS.items():
            identifier = f"read-{label}"
            calls.append({"type": "tool_use", "id": identifier, "name": "Read", "input": {"file_path": str(path)}})
            results.append({"type": "tool_result", "tool_use_id": identifier, "is_error": label != "allowed",
                            "content": "artifactflow-owned-sentinel" if label == "allowed" else "Permission denied"})
            if label != "allowed":
                denials.append({"tool_name": "Read", "tool_use_id": identifier})
        return [{"type": "assistant", "message": {"content": calls}},
                {"type": "user", "message": {"content": results}},
                {"type": "result", "subtype": "success", "is_error": False, "permission_denials": denials}]

    def validate(self, events: list[dict]) -> dict:
        return self.module.validate_stream("\n".join(json.dumps(event) for event in events),
                                           EXPECTED_COMMAND, copy.deepcopy(SPECS), READ_TARGETS)

    def test_complete_native_tool_evidence_passes(self) -> None:
        result = self.validate(self.fixture())
        self.assertIs(result["passed"], True)
        self.assertEqual(4, len(result["cases"]))

    def test_assistant_narrative_cannot_supply_a_shell_receipt(self) -> None:
        events = self.fixture()
        receipt = events[1]["message"]["content"][0]["content"]
        events[1]["message"]["content"][0]["content"] = "no machine receipt"
        events[0]["message"]["content"].append({"type": "text", "text": receipt})
        events[-1]["result"] = receipt
        self.assertIs(self.validate(events)["passed"], False)

    def test_only_user_tool_result_events_supply_results(self) -> None:
        events = self.fixture()
        events[1]["type"] = "assistant"
        self.assertIs(self.validate(events)["passed"], False)

    def test_missing_shell_tool_does_not_pass(self) -> None:
        events = self.fixture()
        events[0]["message"]["content"].pop(0)
        self.assertIs(self.validate(events)["passed"], False)

    def test_altered_command_is_rejected(self) -> None:
        events = self.fixture()
        events[0]["message"]["content"][0]["input"]["command"] += " --unexpected"
        self.assertIs(self.validate(events)["passed"], False)

    def test_unsandboxed_or_background_execution_is_rejected(self) -> None:
        for flag in ("dangerouslyDisableSandbox", "run_in_background"):
            with self.subTest(flag=flag):
                events = self.fixture()
                events[0]["message"]["content"][0]["input"][flag] = True
                self.assertIs(self.validate(events)["passed"], False)

    def test_shell_errors_and_nonzero_probe_exit_cannot_prove_denial(self) -> None:
        events = self.fixture()
        events[1]["message"]["content"][0]["is_error"] = True
        self.assertIs(self.validate(events)["passed"], False)
        events = self.fixture()
        observation = self.observation()
        observation["returncode"] = 1
        events[1]["message"]["content"][0]["content"] = self.module.PREFIX + json.dumps([observation])
        self.assertIs(self.validate(events)["passed"], False)

    def test_failed_shell_result_reports_only_a_known_safe_diagnostic(self) -> None:
        diagnostics = (
            ("sandbox_apply: Operation not permitted", "sandbox_start_not_permitted"),
            ("Permission denied while launching synthetic command", "permission_denied"),
            ("PreToolUse hook error: synthetic hook failed", "hook_failure"),
            ("Unrecognized failure with synthetic-secret-value-never-real", "unclassified_tool_error"),
        )
        for text, diagnostic in diagnostics:
            with self.subTest(diagnostic=diagnostic):
                events = self.fixture()
                events[1]["message"]["content"][0].update({"is_error": True, "content": text})
                result = self.validate(events)
                self.assertIs(result["passed"], False)
                self.assertEqual("shell_tool_did_not_complete", result["category"])
                self.assertEqual(diagnostic, result.get("shell_diagnostic"))
                self.assertNotIn(text, json.dumps(result))
                self.assertNotIn("synthetic-secret-value-never-real", json.dumps(result))

    def test_failed_shell_denial_record_must_match_its_exact_tool_and_id(self) -> None:
        for denial, expected in (
            ({"tool_name": "Bash", "tool_use_id": "bash-1"}, True),
            ({"tool_name": "Bash", "tool_use_id": "unrelated-id"}, False),
            ({"tool_name": "Read", "tool_use_id": "bash-1"}, False),
        ):
            with self.subTest(denial=denial):
                events = self.fixture()
                events[1]["message"]["content"][0].update({"is_error": True, "content": "Permission denied"})
                events[-1]["permission_denials"].append(denial)
                result = self.validate(events)
                self.assertIs(result["passed"], False)
                self.assertIs(result.get("permission_denial_recorded"), expected)

    def test_failed_shell_without_denial_record_is_explicitly_unverified(self) -> None:
        events = self.fixture()
        events[1]["message"]["content"][0].update({"is_error": True, "content": "Permission denied"})
        result = self.validate(events)
        self.assertIs(result["passed"], False)
        self.assertIs(result.get("permission_denial_recorded"), False)

    def test_shell_diagnostic_accepts_native_text_blocks_without_echoing_them(self) -> None:
        events = self.fixture()
        diagnostic_text = "sandbox_apply: Operation not permitted: synthetic-secret-value-never-real"
        events[1]["message"]["content"][0].update({
            "is_error": True, "content": [{"type": "text", "text": diagnostic_text}],
        })
        result = self.validate(events)
        self.assertIs(result["passed"], False)
        self.assertEqual("sandbox_start_not_permitted", result.get("shell_diagnostic"))
        self.assertNotIn("synthetic-secret-value-never-real", json.dumps(result))

    def test_read_error_without_native_permission_denial_does_not_pass(self) -> None:
        events = self.fixture()
        events[-1]["permission_denials"] = []
        self.assertIs(self.validate(events)["passed"], False)

    def test_permission_denial_without_failed_read_result_does_not_pass(self) -> None:
        events = self.fixture()
        events[1]["message"]["content"][2]["is_error"] = False
        self.assertIs(self.validate(events)["passed"], False)

    def test_missing_positive_read_control_does_not_pass(self) -> None:
        events = self.fixture()
        events[1]["message"]["content"][1]["content"] = ""
        self.assertIs(self.validate(events)["passed"], False)

    def test_wrong_nonce_operation_and_nonpermission_errno_do_not_pass(self) -> None:
        for changes in ({"nonce": "wrong"}, {"operation": "write"}, {"errno": errno.ENOENT}):
            with self.subTest(changes=changes):
                events = self.fixture()
                events[1]["message"]["content"][0]["content"] = self.module.PREFIX + json.dumps([self.observation(**changes)])
                self.assertIs(self.validate(events)["passed"], False)

    def test_missing_or_failed_final_client_result_does_not_pass(self) -> None:
        events = self.fixture()
        events[-1]["is_error"] = True
        self.assertIs(self.validate(events)["passed"], False)
        self.assertIs(self.validate(self.fixture()[:-1])["passed"], False)

    def test_extra_tools_duplicate_ids_and_duplicate_receipts_are_rejected(self) -> None:
        events = self.fixture()
        events[0]["message"]["content"].append({"type": "tool_use", "id": "extra", "name": "Write", "input": {}})
        self.assertIs(self.validate(events)["passed"], False)
        events = self.fixture()
        events[0]["message"]["content"].append(copy.deepcopy(events[0]["message"]["content"][0]))
        self.assertIs(self.validate(events)["passed"], False)
        events = self.fixture()
        receipt = events[1]["message"]["content"][0]["content"]
        events[1]["message"]["content"][0]["content"] = receipt + "\n" + receipt
        self.assertIs(self.validate(events)["passed"], False)

    def test_malformed_empty_and_nonobject_streams_do_not_pass(self) -> None:
        for stream in ("", "not JSON", "[]", "null", '{"type":"result","subtype":"success","is_error":false}'):
            with self.subTest(stream=stream):
                result = self.module.validate_stream(stream, EXPECTED_COMMAND, copy.deepcopy(SPECS), READ_TARGETS)
                self.assertIs(result["passed"], False)

    def native_runner(self):
        runner = getattr(self.module, "run_native", None)
        self.assertTrue(callable(runner), "Bounded native launcher must own and clean up its process group.")
        return runner

    def test_native_launcher_preserves_success_and_cleans_only_its_owned_group(self) -> None:
        runner = self.native_runner()
        process = Mock(pid=42424242, returncode=0)
        process.communicate.return_value = ("synthetic stdout", "synthetic stderr")
        with patch.object(self.module.subprocess, "Popen", return_value=process) as launch, \
                patch.object(sys.modules[runner.__module__], "stop_owned_process_group") as cleanup:
            result = runner(["synthetic-claude"], Path("/synthetic/workspace"), "synthetic prompt", 30)
        self.assertIsInstance(result, subprocess.CompletedProcess)
        self.assertEqual(0, result.returncode)
        self.assertEqual("synthetic stdout", result.stdout)
        self.assertEqual("synthetic stderr", result.stderr)
        options = launch.call_args.kwargs
        self.assertIs(options.get("start_new_session"), True)
        self.assertIs(options.get("text"), True)
        for descriptor in ("stdin", "stdout", "stderr"):
            self.assertEqual(subprocess.PIPE, options.get(descriptor))
        self.assertEqual(Path("/synthetic/workspace"), options.get("cwd"))
        process.communicate.assert_called_once_with("synthetic prompt", timeout=30)
        cleanup.assert_called_once_with(process)

    def test_native_timeout_terminates_owned_group_and_reraises_original_timeout(self) -> None:
        runner = self.native_runner()
        timeout = subprocess.TimeoutExpired(["synthetic-claude"], 30)
        process = Mock(pid=42424242, returncode=-15)
        process.communicate.side_effect = [timeout, ("partial stdout", "partial stderr")]
        with patch.object(self.module.subprocess, "Popen", return_value=process), \
                patch.object(sys.modules[runner.__module__], "stop_owned_process_group") as cleanup:
            with self.assertRaises(subprocess.TimeoutExpired) as caught:
                runner(["synthetic-claude"], Path("/synthetic/workspace"), "synthetic prompt", 30)
        self.assertIs(timeout, caught.exception)
        cleanup.assert_called_once_with(process)
        process.communicate.assert_called_once_with("synthetic prompt", timeout=30)

    def test_native_cleanup_failure_is_not_a_successful_run(self) -> None:
        runner = self.native_runner()
        process = Mock(pid=42424242, returncode=0)
        process.communicate.return_value = ("synthetic stdout", "synthetic stderr")
        with patch.object(self.module.subprocess, "Popen", return_value=process), \
                patch.object(sys.modules[runner.__module__], "stop_owned_process_group", side_effect=PermissionError("synthetic denial")):
            with self.assertRaises(PermissionError):
                runner(["synthetic-claude"], Path("/synthetic/workspace"), "synthetic prompt", 30)
        for stream in (process.stdin, process.stdout, process.stderr):
            stream.close.assert_called_once_with()

    def test_missing_native_executable_raises_without_signalling_any_group(self) -> None:
        runner = self.native_runner()
        with patch.object(self.module.subprocess, "Popen", side_effect=FileNotFoundError("synthetic missing client")), \
                patch("os.killpg") as kill_group:
            with self.assertRaises(FileNotFoundError):
                runner(["synthetic-claude"], Path("/synthetic/workspace"), "synthetic prompt", 30)
        kill_group.assert_not_called()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", type=Path, default=REPOSITORY_ROOT)
    parser.add_argument("--runner", type=Path)
    args, unittest_args = parser.parse_known_args()
    REPOSITORY_ROOT = args.repo.resolve(strict=True)
    RUNNER_PATH = args.runner
    unittest.main(argv=[sys.argv[0], *unittest_args], verbosity=2)
