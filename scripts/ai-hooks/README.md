# AI Hook Harness

These scripts provide project-level, defense-in-depth guardrails for AI coding agents.
They are not a security boundary or a substitute for process isolation.

Configured consumers:

- Claude Code: `.claude/settings.json`
- Codex: `.codex/hooks.json`
- Codex execpolicy: `.codex/rules/artifactflow.rules`

The guards are intentionally conservative:

- Python 3 is a mandatory safety dependency. Every configured hook blocks with
  exit code 2 when `python3` is unavailable, and `make ai-hooks-test` fails with
  the same explicit prerequisite instead of relying on command-not-found behavior.
- The file-safety hook matches every emitted `PreToolUse` event and denies the
  `functions.exec` composite tool when the host emits that event. Codex is also
  configured with the `artifactflow-edit` permission profile, so a missing
  nested hook event cannot silently bypass the OS-enforced file policy.
- Known mutating file tools fail closed when their target cannot be extracted.
  AI instructions, security contracts, hook code, CI workflows, and enforcement
  configuration require explicit approval to edit; secret, generated, and Git
  internal files remain denied.
- Codex `PreToolUse` does not support an `ask` result. Codex command findings
  with matching execpolicy rules delegate to those native prompts; findings
  without a native prompt fail closed. Claude retains its supported ask flow.
- `git push` always asks through Codex execpolicy or the Claude hook. GitHub API
  deletion of Git refs fails closed for Codex and asks through the Claude hook.
- `git commit` is denied unless it includes DCO sign-off through an actual
  lowercase `-s` option (including a short-option group) or `--signoff`;
  option arguments that happen to contain `s` and GPG `-S` do not satisfy DCO,
  and an actual `--no-signoff` option always fails closed.
- File deletion through `rm`, `unlink`, or `shred`, command dispatch through
  `xargs`, and `find -delete`/`find -exec` variants are denied.
- Dangerous recursive deletion targets such as `/`, `.`, `..`, `~`, or `$HOME` are denied.
- Direct Laravel/Pest/PHPUnit test runner commands are denied; use `make test` or `make test TEST_FILTER=...` so tests always run against an isolated test database.
- Secret-bearing files such as `.env`, `.npmrc`, `auth.json`, and private keys are not printed, edited, opened in terminal editors, inspected through key tools such as `openssl`, loaded through `source`/`.`, read through shell redirection, or hidden inside wrapped `eval`/`sh -c` commands.
- Known inline-code interpreters, dynamically constructed command/path tokens,
  write-then-execute command chains, decoder-to-interpreter pipelines, and
  arbitrary pipes into interpreters are denied.
- Docker volume deletion, destructive git commands, privilege changes, and cloud deletes require approval.
- Prompts that appear to contain pasted credentials are blocked.

## Invocation trace

Every hook invocation emits a concise diagnostic to stderr, for example:

```text
ArtifactFlow AI Called: guard_command.py Output: allow Agent: codex Event: PreToolUse Tool: Bash Code: none
```

The same result is appended as JSON Lines to the ignored local file
`storage/logs/ai-hooks.jsonl`. Records contain only timestamp, hook, agent,
event, tool, decision, and finding code. Prompt text, command strings, file
paths, reasons, and credentials are never recorded. Set
`ARTIFACTFLOW_AI_HOOK_TRACE_FILE` to redirect the trace for testing or local
tooling. Trace-write failure is reported but does not weaken or block the
underlying safety verdict.

The import surface remains `policy.py`, while event extraction, file policy,
command analysis, prompt scanning, result types, and observability live in
cohesive `policy_*.py` modules.

## Security boundary and residual risk

The hooks inspect serialized tool events and command strings. They cannot know
what arbitrary code will do at runtime. For example, a new interpreter, a script
written in one tool call and executed in another, a native binary, an unreported
nested tool call, or code that constructs a path internally can bypass a
command-string denylist. Pattern coverage raises the bar; it does not make a
secret unreadable.

ArtifactFlow's Codex configuration uses a macOS-enforced permission profile:
all `.env*` paths and other secret paths are denied, while AI instructions,
hooks, CI, security contracts, and other control files are readable but not
writable without the native Codex approval flow. This deliberately includes
tracked `.env` templates; inspect or change those only outside the restricted
profile. Repository hooks remain defense in depth for serialized tool events;
the permission profile is the file-access boundary.

Run the local harness:

```sh
make ai-hooks-test
```

After installing or updating Codex Desktop, run the host integration canary:

```sh
make codex-permissions-test
```

The canary never prints `.env`; it verifies that the active macOS sandbox cannot
open it and cannot update the `Makefile` timestamp. A global legacy
`sandbox_mode` or `[sandbox_workspace_write]` setting disables permission
profiles and makes the canary fail.

Codex rules can be checked directly when the Codex CLI is installed:

```sh
codex execpolicy check --pretty --rules .codex/rules/artifactflow.rules -- git push origin main
codex execpolicy check --pretty --rules .codex/rules/artifactflow.rules -- rm -rf storage/framework/views
```
