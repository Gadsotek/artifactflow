#!/usr/bin/env python3
"""Read-only configuration conformance checks; this does not prove live isolation.

Run with Python 3.11+ (stdlib tomllib):
    python3 scripts/agent-containment/test_configuration.py --repo /path/to/repo
"""
from __future__ import annotations

import argparse
import copy
import json
import sys
import unittest
from pathlib import Path

try:
    import tomllib
except ModuleNotFoundError:
    raise SystemExit("Containment configuration tests require Python 3.11+ (stdlib tomllib).")


REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
CODEX_CONFIG_PATH: Path | None = None
CLAUDE_CONFIG_PATH: Path | None = None
SECRET_RULES = (
    ".env*", "**/.env*", ".npmrc", "auth.json", "credentials",
    "credentials.json", "service-account.json", "**/*.pem", "**/*.key",
    "**/*.p12", "**/*.pfx", "**/*.jks", "**/*.keystore",
)
CODEX_NESTED_SECRET_RULES = (
    "**/.npmrc", "**/auth.json", "**/credentials",
    "**/credentials.json", "**/service-account.json",
)
CONTROL_PATHS = (
    ".claude", ".codex", ".github/workflows", ".semgrep", "AGENTS.md",
    "CLAUDE.md", "Makefile", "SECURITY.md", "THREAT-MODEL.md", "harness",
    "phpstan.neon", "phpunit.xml", "rector.php", "scripts/ai-hooks", "scripts/agent-containment",
    "tests/Feature/Architecture/AiHarnessDriftContractTest.php",
    "tests/Feature/Infrastructure/HarnessPolicyConfigurationTest.php",
)
CONTROL_DIRECTORIES = frozenset({
    ".claude", ".codex", ".github/workflows", ".semgrep", "harness",
    "scripts/ai-hooks", "scripts/agent-containment",
})


class ContainmentConfigurationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.codex = tomllib.loads((CODEX_CONFIG_PATH or REPOSITORY_ROOT / ".codex/config.toml").read_text())
        cls.claude = json.loads((CLAUDE_CONFIG_PATH or REPOSITORY_ROOT / ".claude/settings.json").read_text())

    def codex_profile(self) -> dict:
        selected = self.codex.get("default_permissions")
        self.assertIsInstance(selected, str, "A named Codex permission profile is required.")
        profile = self.codex.get("permissions", {}).get(selected)
        self.assertIsInstance(profile, dict, "The selected Codex permission profile must exist.")
        return profile

    def test_codex_denies_unrelated_filesystem_reads_by_default(self) -> None:
        filesystem = self.codex_profile().get("filesystem", {})
        self.assertEqual("deny", filesystem.get(":root"), "Root must be denied, not inherited as globally readable.")
        self.assertEqual("read", filesystem.get(":minimal"), "Only the documented runtime minimum may be readable outside declared roots.")
        for broad_path in ("/", "~", "$HOME", ":home"):
            self.assertNotIn(filesystem.get(broad_path), ("read", "write"), f"Broad filesystem grant: {broad_path}")

    def test_codex_only_adds_reviewed_read_only_exceptions(self) -> None:
        filesystem = self.codex_profile().get("filesystem", {})
        self.assertEqual("deny", filesystem.get(":root"))
        absolute_grants = {
            path: permission for path, permission in filesystem.items()
            if path.startswith(("/", "~")) and permission != "deny"
        }
        self.assertEqual({
            "/Library/Frameworks/Python.framework": "read",
            "/Library/Developer/CommandLineTools": "read",
            "/System/Library/Perl": "read",
            "/usr/local/bin": "read",
            "~/.gitconfig": "read",
            "~/.gitignore_global": "read",
        }, absolute_grants, "Exceptions must not widen to Library, System/Library, usr/local, home, or write access.")

    def test_codex_preserves_existing_git_and_path_environment(self) -> None:
        environment = self.codex.get("shell_environment_policy", {}).get("set", {})
        for variable in ("PATH", "HOME", "GIT_CONFIG_GLOBAL"):
            self.assertNotIn(variable, environment, "Preserve existing tool discovery and Git behavior.")

    def test_codex_preserves_secret_denials_and_control_file_protection(self) -> None:
        roots = self.codex_profile().get("filesystem", {}).get(":workspace_roots", {})
        self.assertIsInstance(roots, dict)
        for path in SECRET_RULES + CODEX_NESTED_SECRET_RULES:
            with self.subTest(path=path):
                self.assertEqual("deny", roots.get(path), f"Missing secret denial: {path}")
        for path in CONTROL_PATHS:
            with self.subTest(path=path):
                self.assertEqual("read", roots.get(path), f"Control path must require escalation: {path}")

    def test_codex_secret_validator_rejects_missing_nested_denials(self) -> None:
        for path in CODEX_NESTED_SECRET_RULES:
            with self.subTest(path=path):
                candidate = copy.deepcopy(self.codex)
                roots = candidate["permissions"][candidate["default_permissions"]]["filesystem"][":workspace_roots"]
                del roots[path]
                checker = ContainmentConfigurationTest(
                    "test_codex_preserves_secret_denials_and_control_file_protection",
                )
                checker.codex = candidate
                result = unittest.TestResult()
                checker.run(result)
                self.assertFalse(result.wasSuccessful(), "Missing nested denial must fail configuration validation.")
                self.assertEqual([], result.errors)
                self.assertEqual(1, len(result.failures))
                self.assertIn(f"Missing secret denial: {path}", result.failures[0][1])

    def test_codex_secret_validator_rejects_widened_nested_denials(self) -> None:
        for path in CODEX_NESTED_SECRET_RULES:
            for access in ("read", "write"):
                with self.subTest(path=path, access=access):
                    candidate = copy.deepcopy(self.codex)
                    roots = candidate["permissions"][candidate["default_permissions"]]["filesystem"][":workspace_roots"]
                    roots[path] = access
                    checker = ContainmentConfigurationTest(
                        "test_codex_preserves_secret_denials_and_control_file_protection",
                    )
                    checker.codex = candidate
                    result = unittest.TestResult()
                    checker.run(result)
                    self.assertFalse(result.wasSuccessful(), "Readable or writable nested secret must fail configuration validation.")
                    self.assertEqual([], result.errors)
                    self.assertEqual(1, len(result.failures))
                    self.assertIn(f"Missing secret denial: {path}", result.failures[0][1])

    def test_codex_uses_human_on_request_approval_without_legacy_sandbox_override(self) -> None:
        self.assertEqual("on-request", self.codex.get("approval_policy"))
        self.assertEqual("user", self.codex.get("approvals_reviewer"))
        self.assertNotIn("sandbox_mode", self.codex)
        self.assertNotIn("sandbox_workspace_write", self.codex)
        self.assertIs(self.codex.get("features", {}).get("hooks"), True)

    def test_claude_blocks_reads_outside_working_directories(self) -> None:
        permissions = self.claude.get("permissions", {})
        self.assertIs(permissions.get("blockReadsOutsideWorkingDirectories"), True)
        self.assertIsNot(permissions.get("allowManagedReadPathsOnly"), True)
        self.assertNotIn(permissions.get("defaultMode"), ("bypassPermissions", "dontAsk"))
        self.assertEqual("disable", permissions.get("disableBypassPermissionsMode"))
        self.assertEqual([], permissions.get("additionalDirectories", []))

    def test_claude_requires_available_sandbox_and_keeps_human_approvals(self) -> None:
        sandbox = self.claude.get("sandbox", {})
        self.assertIs(sandbox.get("enabled"), True)
        self.assertIs(sandbox.get("failIfUnavailable"), True)
        unsandboxed = sandbox.get("allowUnsandboxedCommands")
        self.assertIsInstance(unsandboxed, bool, "Unsandboxed execution policy must be explicit.")
        if unsandboxed:
            self.assertIn(
                "Bash(dangerouslyDisableSandbox:true)",
                self.claude.get("permissions", {}).get("ask", []),
                "Unsandboxed execution must require a human approval for each invocation.",
            )
        self.assertIs(sandbox.get("autoAllowBashIfSandboxed"), False)
        self.assertEqual([], sandbox.get("excludedCommands", []), "No command may silently leave containment.")
        self.assertEqual([], sandbox.get("filesystem", {}).get("allowRead", []))
        self.assertEqual([], sandbox.get("filesystem", {}).get("allowWrite", []))
        self.assertIsNot(sandbox.get("network", {}).get("allowAllUnixSockets", False), True)
        self.assertEqual([], sandbox.get("network", {}).get("allowUnixSockets", []))

    def test_claude_secret_paths_are_denied_for_native_tools_and_child_processes(self) -> None:
        permissions = self.claude.get("permissions", {})
        tool_denials = permissions.get("deny", [])
        filesystem = self.claude.get("sandbox", {}).get("filesystem", {})
        for path in SECRET_RULES:
            with self.subTest(path=path):
                self.assertIn(f"./{path}", filesystem.get("denyRead", []))
                self.assertIn(f"./{path}", filesystem.get("denyWrite", []))
                recursive_path = path if path.startswith("**/") or path == ".env*" else f"**/{path}"
                for operation in ("Read", "Edit"):
                    self.assertIn(f"{operation}(/{recursive_path})", tool_denials)

    def test_claude_control_paths_require_approval_and_are_not_child_writable(self) -> None:
        asks = self.claude.get("permissions", {}).get("ask", [])
        filesystem = self.claude.get("sandbox", {}).get("filesystem", {})
        for path in CONTROL_PATHS:
            with self.subTest(path=path):
                self.assertIn(f"./{path}", filesystem.get("denyWrite", []))
                suffix = "/**" if path in CONTROL_DIRECTORIES else ""
                self.assertIn(f"Edit(/{path}{suffix})", asks)

    def test_claude_command_hooks_block_on_hook_failure(self) -> None:
        hooks = self.claude.get("hooks", {})
        command_count = 0
        for event, groups in hooks.items():
            for group in groups:
                for hook in group.get("hooks", []):
                    if hook.get("type") != "command":
                        continue
                    command_count += 1
                    with self.subTest(event=event, command=hook.get("command")):
                        self.assertEqual("block", hook.get("onFailure"))
        self.assertGreater(command_count, 0, "Expected configured safety hooks.")

    def test_shared_hook_contract_stays_on_version_eight(self) -> None:
        contract = json.loads((REPOSITORY_ROOT / "harness/ai-hooks-contract.json").read_text())
        self.assertEqual(8, contract.get("contract_version"))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", type=Path, default=REPOSITORY_ROOT)
    parser.add_argument("--codex-config", type=Path)
    parser.add_argument("--claude-config", type=Path)
    args, unittest_args = parser.parse_known_args()
    REPOSITORY_ROOT = args.repo.resolve(strict=True)
    CODEX_CONFIG_PATH = args.codex_config
    CLAUDE_CONFIG_PATH = args.claude_config
    unittest.main(argv=[sys.argv[0], *unittest_args], verbosity=2)
