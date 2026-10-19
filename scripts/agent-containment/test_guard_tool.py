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

    def invoke(self, agent: str, tool_name: str, tool_input: object) -> subprocess.CompletedProcess[str]:
        adapter = ADAPTER_PATH or REPOSITORY_ROOT / "scripts/agent-containment/guard_tool.py"
        self.assertTrue(adapter.is_file(), f"Operation-aware guard adapter is missing: {adapter}")
        payload = {"hook_event_name": "PreToolUse", "tool_name": tool_name, "tool_input": tool_input}
        return subprocess.run(
            [sys.executable, "-B", str(adapter), "--agent", agent, "--write-guard",
             str(REPOSITORY_ROOT / "scripts/ai-hooks/guard_file_write.py")],
            input=json.dumps(payload), text=True, capture_output=True, timeout=10,
            cwd=REPOSITORY_ROOT,
            env={**os.environ, "PYTHONDONTWRITEBYTECODE": "1",
                 "ARTIFACTFLOW_AI_HOOK_TRACE_FILE": str(self.trace_path)},
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

    def assert_claude_search_denied(self, tool_name: str, tool_input: object) -> None:
        result = self.invoke("claude", tool_name, tool_input)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertNotEqual("", result.stdout, "Native Claude search must return an explicit denial")
        output = json.loads(result.stdout)["hookSpecificOutput"]
        self.assertEqual("PreToolUse", output["hookEventName"])
        self.assertEqual("deny", output["permissionDecision"])
        self.assertIn("sandboxed Bash", output["permissionDecisionReason"])
        trace = json.loads(self.trace_path.read_text().splitlines()[-1])
        self.assertEqual("native_search_disabled", trace["code"])
        self.assertEqual("deny", trace["decision"])

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

    def test_grep_control_file_follows_agent_search_policy(self) -> None:
        inputs = {"path": "AGENTS.md", "pattern": "policy"}
        self.assert_decision("codex", "Grep", inputs, "allow")
        self.assert_claude_search_denied("Grep", inputs)

    def test_grep_secret_target_is_denied(self) -> None:
        self.check_both_agents("Grep", {"path": ".env", "pattern": "example"}, "deny")

    def test_glob_control_directory_follows_agent_search_policy(self) -> None:
        inputs = {"path": ".claude", "pattern": "*.json"}
        self.assert_decision("codex", "Glob", inputs, "allow")
        self.assert_claude_search_denied("Glob", inputs)

    def test_claude_native_search_selectors_are_always_denied(self) -> None:
        selectors = (
            ".env*", "**/.env*", "credentials*", "**/credentials*.json",
            "**/auth.json", "**/service-account.json", "**/*.key", "**/*.pem",
            "**/[c]redentials.json", "**/{README.md,.env*}", "**/*.{pem,key}",
            "*", "**/*", "**/.*", "**/*.php",
        )
        for tool in ("Grep", "Glob"):
            for selector in selectors:
                with self.subTest(tool=tool, selector=selector):
                    inputs = ({"path": ".", "glob": selector, "pattern": "synthetic"}
                              if tool == "Grep" else {"path": ".", "pattern": selector})
                    self.assert_claude_search_denied(tool, inputs)

    def test_claude_search_omitted_and_malformed_inputs_are_denied(self) -> None:
        for tool in ("Grep", "Glob"):
            for inputs in (
                {}, {"pattern": "synthetic"}, {"pattern": ".env*"},
                {"path": "."}, {"path": 7, "pattern": "synthetic"},
                {"path": ".", "glob": ["*.php"], "pattern": "synthetic"},
                {"path": ".", "pattern": None}, None, [], "synthetic", 7,
            ):
                with self.subTest(tool=tool, inputs=inputs):
                    self.assert_claude_search_denied(tool, inputs)

    def test_codex_search_behavior_is_unchanged(self) -> None:
        for tool, inputs in (
            ("Grep", {"path": ".", "glob": ".env*", "pattern": "synthetic"}),
            ("Grep", {"pattern": ".env|credentials|password|*.key"}),
            ("Grep", {"path": "app", "glob": "**/*.php", "pattern": "synthetic"}),
            ("Glob", {"pattern": "**/*"}),
            ("Glob", {"path": "scripts", "pattern": "**/*.py"}),
        ):
            with self.subTest(tool=tool, inputs=inputs):
                self.assert_decision("codex", tool, inputs, "allow")

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
