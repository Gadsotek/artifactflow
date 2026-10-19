from __future__ import annotations

import copy
import errno
import importlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from development_probe import CHECKS

try:
    session = importlib.import_module("claude_session")
except ModuleNotFoundError:
    session = None


PROJECT = Path("/owned/project")
FIXTURE = PROJECT / "storage/framework/testing/session-owned"
READ = FIXTURE / "read.txt"
OUTSIDE = Path("/owned/outside/read.txt")
EDIT = FIXTURE / "source.txt"
COMMAND = "python3 /owned/project/smoke.py --workspace-only"
BEFORE = "artifactflow-session-source-before\n"
AFTER = "artifactflow-session-source-after\n"
MARKER = {"prefix": "ARTIFACTFLOW_DEVELOPMENT_RECEIPT ",
          "protocol": "artifactflow-workspace-development-v3", "nonce": "owned-nonce"}
SEARCH_CHECKS = {"search tool version", "owned sentinel search"}
PAYLOAD = {"protocol": MARKER["protocol"], "nonce": MARKER["nonce"], "checks": [
    {"name": name, "passed": True, "category": "command_completed", "returncode": 0,
     "signature_valid": True, "git_access_warning": False,
     **({"tool": "rg"} if name in SEARCH_CHECKS else {})} if kind == "command" else
    {"name": name, "passed": True, "category": "os_access_denied" if kind == "deny" else "access_allowed",
     "operation": "write" if name == "owned write" else "rename" if name == "owned rename" else "read",
     "success": kind != "deny", "errno": errno.EPERM if kind == "deny" else None}
    for name, kind in CHECKS.items() if kind != "deny"
]}


def successful_events() -> list[dict[str, object]]:
    events = [{"type": "system", "subtype": "init", "session_id": "new-session",
               "cwd": str(PROJECT), "claude_code_version": "2.1.299",
               "permissionMode": "default", "tools": ["Bash", "Read", "Edit"],
               "mcp_servers": [], "plugins": []}]
    guards = [("guard_prompt.py", "UserPromptSubmit", "none"),
              ("guard_command.py", "PreToolUse", "Bash"),
              ("guard_file_write.py", "PreToolUse", "bash"),
              ("guard_tool.py", "PreToolUse", "read"),
              ("guard_tool.py", "PreToolUse", "read"),
              ("guard_tool.py", "PreToolUse", "read"),
              ("guard_file_write.py", "PreToolUse", "edit")]
    for index, (guard, event, tool) in enumerate(guards):
        events.append({"type": "system", "subtype": "hook_response", "session_id": "new-session",
                       "hook_id": f"hook-{index}", "hook_name": f"{event}:{tool}",
                       "hook_event": event, "exit_code": 0, "outcome": "success",
                       "stderr": f"ArtifactFlow AI Called: {guard} Output: allow Agent: claude "
                       f"Event: {event} Tool: {tool} Code: none\n"})
    for identifier, name, parameters, output, failed in [
        ("read-inside", "Read", {"file_path": str(READ)}, "artifactflow-owned-development-sentinel\n", False),
        ("read-outside", "Read", {"file_path": str(OUTSIDE)}, "Denied", True),
        ("read-edit", "Read", {"file_path": str(EDIT)}, BEFORE, False),
        ("edit", "Edit", {"file_path": str(EDIT), "old_string": BEFORE,
                            "new_string": AFTER}, "Updated", False),
        ("bash", "Bash", {"command": COMMAND}, MARKER["prefix"] + json.dumps(PAYLOAD), False),
    ]:
        events.append({"type": "assistant", "session_id": "new-session", "parent_tool_use_id": None,
                       "message": {"content": [{"type": "tool_use", "id": identifier,
                                                "name": name, "input": parameters}]}})
        events.append({"type": "user", "session_id": "new-session", "parent_tool_use_id": None,
                       "message": {"content": [{"type": "tool_result", "tool_use_id": identifier,
                                                "content": output, "is_error": failed}]}})
    events.append({"type": "result", "session_id": "new-session", "subtype": "success",
                   "is_error": False, "permission_denials": [
                       {"tool_name": "Read", "tool_use_id": "read-outside"}]})
    return events


class ClaudeSessionTests(unittest.TestCase):
    def setUp(self) -> None:
        self.assertIsNotNone(session, "claude_session implementation is missing")

    def validate(self, events: list[dict[str, object]]) -> dict[str, object]:
        return session.validate_session_stream(
            "\n".join(json.dumps(event) for event in events), PROJECT, COMMAND,
            MARKER, READ, OUTSIDE, EDIT,
        )

    def test_native_tool_and_hook_evidence_passes(self) -> None:
        result = self.validate(successful_events())
        self.assertTrue(result["passed"])
        self.assertEqual(result["shell_receipt"], PAYLOAD)
        self.assertEqual(result["permission_mode"], "default")
        self.assertIn("not a full merged-policy attestation", result["scope"])
        self.assertIn("no fresh OS containment proof", result["scope"])
        self.assertEqual(len(result["shell_receipt"]["checks"]), 15)

    def test_portable_search_requires_two_proofs_from_the_same_supported_tool(self) -> None:
        for tool in ("rg", "grep"):
            with self.subTest(tool=tool):
                events = successful_events()
                payload = copy.deepcopy(PAYLOAD)
                checks = [check for check in payload["checks"] if check["name"] in SEARCH_CHECKS]
                self.assertEqual(len(checks), 2)
                for check in checks:
                    check["tool"] = tool
                events[-2]["message"]["content"][0]["content"] = MARKER["prefix"] + json.dumps(payload)
                result = self.validate(events)
                self.assertTrue(result["passed"])
                self.assertEqual(result["shell_receipt"], payload)

    def test_invalid_or_mismatched_search_proof_cannot_pass_or_leak(self) -> None:
        for value in (None, "synthetic-private-value", "grep"):
            with self.subTest(tool=value):
                events = successful_events()
                payload = copy.deepcopy(PAYLOAD)
                checks = [check for check in payload["checks"] if check["name"] in SEARCH_CHECKS]
                self.assertEqual(len(checks), 2)
                if value is None:
                    checks[0].pop("tool")
                else:
                    checks[0]["tool"] = value
                events[-2]["message"]["content"][0]["content"] = MARKER["prefix"] + json.dumps(payload)
                result = self.validate(events)
                self.assertFalse(result["passed"])
                self.assertNotIn("shell_receipt", result)
                self.assertNotIn("synthetic-private-value", json.dumps(result))

    def test_legacy_search_protocol_is_not_accepted_as_current_evidence(self) -> None:
        events = successful_events()
        marker = {**MARKER, "protocol": "artifactflow-workspace-development-v1"}
        payload = {**PAYLOAD, "protocol": marker["protocol"]}
        events[-2]["message"]["content"][0]["content"] = marker["prefix"] + json.dumps(payload)
        result = session.validate_session_stream(
            "\n".join(json.dumps(event) for event in events), PROJECT, COMMAND,
            marker, READ, OUTSIDE, EDIT,
        )
        self.assertFalse(result["passed"])

    def test_workspace_proof_does_not_accept_combined_containment_cases(self) -> None:
        events = successful_events()
        payload = copy.deepcopy(PAYLOAD)
        payload["checks"].append({"name": "outside direct read", "passed": True,
                                  "category": "os_access_denied", "operation": "read",
                                  "success": False, "errno": errno.EPERM})
        events[-2]["message"]["content"][0]["content"] = MARKER["prefix"] + json.dumps(payload)
        self.assertFalse(self.validate(events)["passed"])
        payload["protocol"] = "artifactflow-development-v3"
        events[-2]["message"]["content"][0]["content"] = MARKER["prefix"] + json.dumps(payload)
        self.assertFalse(self.validate(events)["passed"])

    def test_init_must_match_project_and_normal_session(self) -> None:
        for key, value in [("cwd", "/different"), ("permissionMode", "bypassPermissions"),
                           ("permissionMode", "mystery"), ("tools", ["Bash", "Read", "Edit", "Agent"]),
                           ("mcp_servers", [{"name": "unrequested", "status": "connected"}]),
                           ("plugin_errors", [{"message": "synthetic-private-value"}])]:
            with self.subTest(key=key):
                events = successful_events()
                events[0][key] = value
                self.assertFalse(self.validate(events)["passed"])
        self.assertFalse(self.validate(successful_events()[1:])["passed"])
        self.assertFalse(self.validate([successful_events()[0]] + successful_events())["passed"])

    def test_other_session_and_subagent_events_cannot_supply_proof(self) -> None:
        for key, value in [("session_id", "other-session"), ("parent_tool_use_id", "subagent")]:
            events = successful_events()
            events[-3][key] = value
            self.assertFalse(self.validate(events)["passed"])

    def test_missing_or_failed_hook_runtime_cannot_pass(self) -> None:
        for index in range(1, 8):
            events = successful_events()
            events.pop(index)
            self.assertFalse(self.validate(events)["passed"])
        for key, value in [("outcome", "error"), ("exit_code", 2), ("type", "assistant"),
                           ("hook_event", "SessionStart"), ("session_id", "other-session"),
                           ("stderr", "narrative says hooks passed")]:
            events = successful_events()
            events[1][key] = value
            self.assertFalse(self.validate(events)["passed"])

    def test_unknown_hook_output_is_not_persisted(self) -> None:
        events = successful_events()
        events[1]["stderr"] += "synthetic-private-value\n"
        result = self.validate(events)
        self.assertTrue(result["passed"])
        self.assertNotIn("synthetic-private-value", json.dumps(result))

    def test_shell_must_be_exact_foreground_sandboxed_tool_call(self) -> None:
        for key, value in [("command", COMMAND + " --changed"),
                           ("dangerouslyDisableSandbox", True), ("run_in_background", True)]:
            events = successful_events()
            events[-3]["message"]["content"][0]["input"][key] = value
            self.assertFalse(self.validate(events)["passed"])

    def test_shell_receipt_must_come_from_matching_real_tool_result(self) -> None:
        for field, value in [("type", "assistant"), ("session_id", "other-session")]:
            events = successful_events()
            events[-2][field] = value
            self.assertFalse(self.validate(events)["passed"])
        for key, value in [("is_error", True), ("tool_use_id", "unrelated"),
                           ("content", "The test passed")]:
            events = successful_events()
            events[-2]["message"]["content"][0][key] = value
            self.assertFalse(self.validate(events)["passed"])

    def test_shell_receipt_requires_one_matching_protocol_and_nonce(self) -> None:
        for payload in [{**PAYLOAD, "nonce": "other"}, {**PAYLOAD, "protocol": "other"},
                        {**PAYLOAD, "checks": "passed"}, {**PAYLOAD, "checks": []}]:
            events = successful_events()
            events[-2]["message"]["content"][0]["content"] = MARKER["prefix"] + json.dumps(payload)
            self.assertFalse(self.validate(events)["passed"])
        events = successful_events()
        events[-2]["message"]["content"][0]["content"] *= 2
        self.assertFalse(self.validate(events)["passed"])

    def test_failed_bash_retains_only_sanitized_native_check_diagnostics(self) -> None:
        payload = copy.deepcopy(PAYLOAD)
        payload["checks"][0].update({"passed": False, "category": "unexpected_access_result",
                                      "success": False, "errno": errno.EPERM,
                                      "error_type": "synthetic-private-value"})
        for shell_error in (False, True):
            events = successful_events()
            events[-2]["message"]["content"][0].update({
                "is_error": shell_error, "content": MARKER["prefix"] + json.dumps(payload)})
            result = self.validate(events)
            self.assertFalse(result["passed"])
            self.assertEqual(result["shell_summary"]["checks"][0], {
                "name": "project README read", "passed": False, "category": "unexpected_access_result"})
            self.assertNotIn("shell_receipt", result)
            self.assertNotIn("synthetic-private-value", json.dumps(result))

    def test_malformed_bash_receipt_cannot_leak_arbitrary_fields(self) -> None:
        payload = copy.deepcopy(PAYLOAD)
        payload["checks"][0] = {"name": "synthetic-private-value", "passed": False,
                                "category": "synthetic-private-value"}
        events = successful_events()
        events[-2]["message"]["content"][0]["content"] = MARKER["prefix"] + json.dumps(payload)
        result = self.validate(events)
        self.assertFalse(result["passed"])
        self.assertNotIn("shell_receipt", result)
        self.assertNotIn("synthetic-private-value", json.dumps(result))

    def test_every_failure_includes_fixed_shape_native_diagnostics(self) -> None:
        events = successful_events()
        events.pop(1)
        events[-2]["message"]["content"][0].update(
            {"is_error": True, "content": "sandbox_apply: Operation not permitted synthetic-private-value"})
        events.append({"type": "synthetic-private-value", "subtype": "synthetic-private-value"})
        result = self.validate(events)
        diagnostic = result["diagnostics"]
        self.assertFalse(result["passed"])
        self.assertEqual(diagnostic["tool_calls"], {"Bash": 1, "Read": 3, "Edit": 1, "other": 0})
        self.assertEqual(diagnostic["final_subtypes"]["success"], 1)
        self.assertEqual(diagnostic["permission_denials"]["Read"], 1)
        self.assertEqual(diagnostic["tool_errors"]["sandbox_start_not_permitted"], 1)
        self.assertEqual(diagnostic["system_subtypes"]["hook_response"], 6)
        self.assertEqual(diagnostic["event_types"]["other"], 1)
        prompt_hook = next(item for item in diagnostic["hook_markers"] if item["guard"] == "guard_prompt.py")
        self.assertEqual(prompt_hook["stderr"], 0)
        self.assertNotIn("synthetic-private-value", json.dumps(result))
        malformed = session.validate_session_stream("synthetic-private-value", PROJECT, COMMAND,
                                                    MARKER, READ, OUTSIDE, EDIT)
        self.assertEqual(malformed["diagnostics"]["invalid_lines"], 1)
        self.assertNotIn("synthetic-private-value", json.dumps(malformed))

    def test_diagnostics_count_only_native_roles_and_fixed_labels(self) -> None:
        events = successful_events()
        events[0]["tools"] = ["Bash", "Read", "Edit", "synthetic-private-value"]
        events[0]["permissionMode"] = "synthetic-private-value"
        events[1]["stderr"] = ""
        events[1]["output"] = "ArtifactFlow AI Called: guard_prompt.py Output: allow Agent: claude Event: UserPromptSubmit Tool: none Code: none\n"
        events[-1]["subtype"] = "synthetic-private-value"
        events[-1]["permission_denials"].append({"tool_name": "synthetic-private-value"})
        result = self.validate(events)
        diagnostic = result["diagnostics"]
        self.assertEqual(diagnostic["initialization_tools"]["other"], 1)
        self.assertEqual(diagnostic["permission_modes"]["other"], 1)
        self.assertEqual(diagnostic["final_subtypes"]["other"], 1)
        self.assertEqual(diagnostic["permission_denials"]["other"], 1)
        prompt_hook = next(item for item in diagnostic["hook_markers"] if item["guard"] == "guard_prompt.py")
        self.assertEqual(prompt_hook["output"], 1)
        self.assertNotIn("synthetic-private-value", json.dumps(result))

    def test_permission_decision_diagnostics_are_allowlisted_and_tool_specific(self) -> None:
        events = successful_events()
        events.insert(-1, {"type": "system", "subtype": "permission_denied", "session_id": "new-session",
                           "tool_name": "Bash", "tool_use_id": "bash", "decision_reason_type": "rule",
                           "decision_reason": "Matched an ask rule synthetic-private-value",
                           "message": "Approval required; permission prompts are disabled"})
        events.insert(-1, {"type": "system", "subtype": "permission_denied", "session_id": "new-session",
                           "tool_name": "Edit", "tool_use_id": "edit",
                           "decision_reason_type": "synthetic-private-value",
                           "decision_reason": "synthetic-private-value"})
        events[-1]["is_error"] = True
        result = self.validate(events)
        diagnostic = result["diagnostics"]
        self.assertEqual(diagnostic["permission_decisions"]["Bash"]["rule"], 1)
        self.assertEqual(diagnostic["permission_decisions"]["Edit"]["other"], 1)
        self.assertEqual(diagnostic["permission_reason_hints"]["Bash"]["mentions_ask_rule"], 1)
        self.assertEqual(diagnostic["permission_reason_hints"]["Bash"]["mentions_approval"], 1)
        self.assertEqual(diagnostic["permission_reason_hints"]["Bash"]["mentions_disabled_prompts"], 1)
        self.assertNotIn("synthetic-private-value", json.dumps(result))

    def test_expected_input_diagnostics_survive_earlier_edit_failure(self) -> None:
        for changed in (False, True):
            events = successful_events()
            events[15]["message"]["content"][0]["is_error"] = True
            if changed:
                events[-3]["message"]["content"][0]["input"].update(
                    {"command": "synthetic-private-value", "dangerouslyDisableSandbox": True,
                     "run_in_background": True})
            result = self.validate(events)
            expected = result["diagnostics"]["expected_inputs"]
            self.assertTrue(expected["bash_call_present"])
            self.assertEqual(expected["bash_command_exact"], not changed)
            self.assertEqual(expected["bash_sandbox_preserved"], not changed)
            self.assertEqual(expected["bash_foreground"], not changed)
            self.assertTrue(expected["edit_target_exact"])
            self.assertTrue(expected["edit_change_exact"])
            self.assertNotIn("synthetic-private-value", json.dumps(result))

    def test_read_denial_requires_matching_tool_id_and_error(self) -> None:
        for denials in [[], [{"tool_name": "Read", "tool_use_id": "other"}]]:
            events = successful_events()
            events[-1]["permission_denials"] = denials
            self.assertFalse(self.validate(events)["passed"])
        events = successful_events()
        events[11]["message"]["content"][0]["is_error"] = False
        self.assertFalse(self.validate(events)["passed"])

    def test_edit_requires_exact_owned_change_and_success(self) -> None:
        for key, value in [("file_path", "/other"), ("new_string", "different"),
                           ("old_string", "different"), ("replace_all", True)]:
            events = successful_events()
            events[14]["message"]["content"][0]["input"][key] = value
            self.assertFalse(self.validate(events)["passed"])
        events = successful_events()
        events[15]["message"]["content"][0]["is_error"] = True
        self.assertFalse(self.validate(events)["passed"])

    def test_edit_target_must_be_read_successfully_before_edit(self) -> None:
        events = successful_events()
        events[13]["message"]["content"][0]["content"] = "different content"
        self.assertFalse(self.validate(events)["passed"])
        events = successful_events()
        events[12:16] = events[14:16] + events[12:14]
        self.assertFalse(self.validate(events)["passed"])

    def test_extra_duplicate_calls_and_failed_completion_do_not_pass(self) -> None:
        events = successful_events()
        events.insert(-1, copy.deepcopy(events[8]))
        self.assertFalse(self.validate(events)["passed"])
        events = successful_events()
        events[-1]["subtype"] = "error_max_budget_usd"
        self.assertFalse(self.validate(events)["passed"])
        self.assertFalse(session.validate_session_stream("not json", PROJECT, COMMAND,
                                                        MARKER, READ, OUTSIDE, EDIT)["passed"])

    def test_malformed_native_field_types_fail_closed(self) -> None:
        for location, key, value in [(0, "tools", [{}]), (0, "permissionMode", {}),
                                     (1, "hook_id", []), (1, "exit_code", False)]:
            with self.subTest(key=key):
                events = successful_events()
                events[location][key] = value
                self.assertFalse(self.validate(events)["passed"])
        for key, value in [("name", []), ("input", [])]:
            events = successful_events()
            events[8]["message"]["content"][0][key] = value
            self.assertFalse(self.validate(events)["passed"])
        events = successful_events()
        events[8]["message"]["content"][0]["input"]["file_path"] = {}
        self.assertFalse(self.validate(events)["passed"])
        events = successful_events()
        events[-1]["permission_denials"][0]["tool_use_id"] = {}
        self.assertFalse(self.validate(events)["passed"])

    def test_launch_preserves_normal_config_and_mode(self) -> None:
        command, prompt = session.build_launch("claude", COMMAND, READ, OUTSIDE, EDIT, .5)
        for forbidden in ("--settings", "--setting-sources", "--permission-mode", "--bare",
                          "--restricted", "--safe-mode", "--dangerously-skip-permissions"):
            self.assertNotIn(forbidden, command)
        self.assertIn("--permission-prompts", command)
        self.assertIn("Bash,Read,Edit", command)
        self.assertIn(f"Bash({COMMAND})", command)
        self.assertIn(f"Edit(/{EDIT})", command)
        self.assertNotIn(f"Edit({EDIT})", command)
        self.assertIn(str(OUTSIDE), prompt)
        self.assertIn(BEFORE.strip(), prompt)
        self.assertIn(AFTER.strip(), prompt)
        self.assertIn(f"Use Read exactly once with file_path {str(EDIT)!r}", prompt)

    def test_workspace_shell_cannot_name_outside_probe_targets(self) -> None:
        for command in (COMMAND + f" --outside {OUTSIDE}", COMMAND + f" {OUTSIDE}",
                        COMMAND.removesuffix(" --workspace-only")):
            with self.subTest(command=command), self.assertRaises(ValueError):
                session.build_launch("claude", command, READ, OUTSIDE, EDIT, .5)

    def test_runner_checks_real_edit_and_unchanged_config(self) -> None:
        for mutation, expected_pass in [("edit", True), ("none", False), ("config", False),
                                        ("failed_shell", False), ("makefile", False),
                                        ("publish_guard", False), ("harness", False),
                                        ("failed_shell_and_config", False)]:
            with self.subTest(mutation=mutation), tempfile.TemporaryDirectory() as temporary:
                project = Path(temporary).resolve() / "project"
                fixture = project / "storage/framework/testing/owned"
                fixture.mkdir(parents=True)
                (project / ".claude").mkdir()
                config = project / ".claude/settings.json"
                config.write_text("{}\n")
                for relative in ("scripts/ai-hooks/guard_prompt.py", "scripts/ai-hooks/guard_command.py",
                                 "scripts/ai-hooks/guard_file_write.py", "scripts/agent-containment/guard_tool.py",
                                 "scripts/ai-hooks/run_harness.py", "scripts/publish-guard.sh", "Makefile"):
                    target = project / relative
                    target.parent.mkdir(parents=True, exist_ok=True)
                    target.write_text("# owned hook fixture\n")
                (project / "CLAUDE.md").write_text("Owned instructions\n")
                read_path, edit_path = fixture / "source.txt", fixture / "agent-edit.txt"
                outside = Path(temporary) / "outside.txt"
                read_path.write_text("artifactflow-owned-development-sentinel\n")
                edit_path.write_text(BEFORE)
                outside.write_text("artifactflow-owned-development-sentinel\n")
                stream = "\n".join(json.dumps(event) for event in successful_events())
                if mutation in {"failed_shell", "failed_shell_and_config"}:
                    events = successful_events()
                    events[-2]["message"]["content"][0].update(
                        {"is_error": True, "content": "sandbox_apply: Operation not permitted"})
                    stream = "\n".join(json.dumps(event) for event in events)
                replacements = {str(PROJECT): str(project), str(READ): str(read_path),
                                str(OUTSIDE): str(outside), str(EDIT): str(edit_path)}
                for original in sorted(replacements, key=len, reverse=True):
                    stream = stream.replace(original, replacements[original])
                command = COMMAND.replace(str(PROJECT), str(project))
                executed_inputs = {"makefile": project / "Makefile",
                                   "publish_guard": project / "scripts/publish-guard.sh",
                                   "harness": project / "scripts/ai-hooks/run_harness.py"}

                def launch(*arguments: object) -> subprocess.CompletedProcess:
                    self.assertEqual(arguments[1], project)
                    if mutation in {"edit", "config"} or mutation in executed_inputs:
                        edit_path.write_text(AFTER)
                    if mutation in {"config", "failed_shell_and_config"}:
                        config.write_text('{"changed":true}\n')
                    if mutation in executed_inputs:
                        executed_inputs[mutation].write_text("# changed executed code\n")
                    return subprocess.CompletedProcess([], 0, stream, "synthetic-private-value")

                with patch.object(session.shutil, "which", return_value="/owned/claude"), \
                        patch.object(session, "run_native", side_effect=launch):
                    result = session.run_session(project, fixture, command, MARKER, read_path,
                                                 edit_path, outside_path=outside)
                self.assertEqual(result["passed"], expected_pass, result)
                self.assertNotIn("synthetic-private-value", json.dumps(result))
                if mutation in {"failed_shell", "failed_shell_and_config"}:
                    self.assertEqual(result["category"], "shell_tool_did_not_complete")
                    self.assertEqual(result["shell_diagnostic"], "sandbox_start_not_permitted")
                    self.assertEqual(result["diagnostics"]["tool_errors"]["sandbox_start_not_permitted"], 1)
                if mutation == "failed_shell_and_config":
                    self.assertEqual(result["integrity_failure"], "project_changed")

    def test_runner_fails_unavailable_without_starting_model(self) -> None:
        with patch.object(session.shutil, "which", return_value=None), \
                patch.object(session, "run_native") as native:
            result = session.run_session(PROJECT, FIXTURE, COMMAND, MARKER, READ,
                                         EDIT, outside_path=OUTSIDE)
        self.assertFalse(result["passed"])
        native.assert_not_called()


if __name__ == "__main__":
    unittest.main()
