#!/usr/bin/env python3
"""Safe adapter regression tests using synthetic tool payloads, never target files."""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
ADAPTER_PATH: Path | None = None


class GuardToolTest(unittest.TestCase):
    def setUp(self) -> None:
        descriptor, name = tempfile.mkstemp(prefix="artifactflow-guard-tool-", suffix=".jsonl")
        os.close(descriptor)
        self.trace_path = Path(name)

    def tearDown(self) -> None:
        self.trace_path.unlink(missing_ok=True)

    def invoke(self, agent: str, tool_name: str, tool_input: dict) -> subprocess.CompletedProcess[str]:
        adapter = ADAPTER_PATH or REPOSITORY_ROOT / "scripts/agent-containment/guard_tool.py"
        self.assertTrue(adapter.is_file(), f"Operation-aware guard adapter is missing: {adapter}")
        payload = {"hook_event_name": "PreToolUse", "tool_name": tool_name, "tool_input": tool_input}
        return subprocess.run(
            [sys.executable, str(adapter), "--agent", agent, "--write-guard",
             str(REPOSITORY_ROOT / "scripts/ai-hooks/guard_file_write.py")],
            input=json.dumps(payload), text=True, capture_output=True, timeout=10,
            cwd=REPOSITORY_ROOT,
            env={**os.environ, "ARTIFACTFLOW_AI_HOOK_TRACE_FILE": str(self.trace_path)},
            check=False,
        )

    def assert_decision(self, agent: str, tool_name: str, tool_input: dict, expected: str) -> None:
        result = self.invoke(agent, tool_name, tool_input)
        if agent == "codex":
            self.assertEqual(2 if expected == "deny" else 0, result.returncode, result.stderr)
            self.assertEqual("", result.stdout)
            if expected == "ask":
                self.assertIn("Output: delegate", result.stderr)
        else:
            self.assertEqual(0, result.returncode, result.stderr)
            if expected == "allow":
                self.assertEqual("", result.stdout)
            else:
                result_json = json.loads(result.stdout)
                self.assertEqual(expected, result_json["hookSpecificOutput"]["permissionDecision"])

    def check_both_agents(self, tool_name: str, tool_input: dict, expected: str) -> None:
        for agent in ("codex", "claude"):
            with self.subTest(agent=agent, tool=tool_name):
                self.assert_decision(agent, tool_name, tool_input, expected)

    def test_read_instructions_is_allowed(self) -> None:
        self.check_both_agents("Read", {"file_path": "AGENTS.md"}, "allow")

    def test_read_readme_is_allowed(self) -> None:
        self.check_both_agents("Read", {"file_path": "README.md"}, "allow")

    def test_read_dependency_source_is_allowed(self) -> None:
        self.check_both_agents("Read", {"file_path": "vendor/example/safe.php"}, "allow")

    def test_read_secret_is_denied(self) -> None:
        self.check_both_agents("Read", {"file_path": ".env"}, "deny")

    def test_read_nested_secret_is_denied(self) -> None:
        self.check_both_agents("Read", {"file_path": "storage/example/.env.local"}, "deny")

    def test_read_missing_target_is_denied(self) -> None:
        self.check_both_agents("Read", {}, "deny")

    def test_grep_control_file_is_allowed(self) -> None:
        self.check_both_agents("Grep", {"path": "AGENTS.md", "pattern": "policy"}, "allow")

    def test_grep_secret_target_is_denied(self) -> None:
        self.check_both_agents("Grep", {"path": ".env", "pattern": "example"}, "deny")

    def test_glob_control_directory_is_allowed(self) -> None:
        self.check_both_agents("Glob", {"path": ".claude", "pattern": "*.json"}, "allow")

    def test_lsp_source_is_allowed(self) -> None:
        self.check_both_agents("LSP", {"filePath": "app/Example.php", "operation": "documentSymbol"}, "allow")

    def test_lsp_secret_target_is_denied(self) -> None:
        self.check_both_agents("LSP", {"filePath": ".env", "operation": "documentSymbol"}, "deny")

    def test_edit_control_file_keeps_existing_approval_behavior(self) -> None:
        self.check_both_agents("Edit", {"file_path": "AGENTS.md", "old_string": "old", "new_string": "new"}, "ask")

    def test_write_source_is_allowed(self) -> None:
        self.check_both_agents("Write", {"file_path": "app/Example.php", "content": "safe synthetic fixture"}, "allow")

    def test_write_without_target_is_denied(self) -> None:
        self.check_both_agents("Write", {"content": "safe synthetic fixture"}, "deny")

    def test_command_event_does_not_get_a_file_write_decision(self) -> None:
        self.check_both_agents("Bash", {"command": "echo harmless"}, "allow")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", type=Path, default=REPOSITORY_ROOT)
    parser.add_argument("--adapter", type=Path)
    args, unittest_args = parser.parse_known_args()
    REPOSITORY_ROOT = args.repo.resolve(strict=True)
    ADAPTER_PATH = args.adapter
    unittest.main(argv=[sys.argv[0], *unittest_args], verbosity=2)
