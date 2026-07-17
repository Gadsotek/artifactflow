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
  `functions.exec` composite tool when the host emits that event. It cannot
  intercept nested operations that the desktop/tool bridge does not expose to
  project hooks. Nested calls are not inferred by parsing JavaScript; agents
  must use native hook-visible tools instead.
- `git push` always asks first.
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

## Security boundary and residual risk

The hooks inspect serialized tool events and command strings. They cannot know
what arbitrary code will do at runtime. For example, a new interpreter, a script
written in one tool call and executed in another, a native binary, an unreported
nested tool call, or code that constructs a path internally can bypass a
command-string denylist. Pattern coverage raises the bar; it does not make a
secret unreadable.

To actually close access to `.env` or other credentials, run the agent in a
separate OS/container security context where those files are absent or
unreadable. Mount only the required workspace paths, make policy/control files
read-only where practical, use a default-deny execution policy, and deny network
egress at the sandbox boundary. If the agent process runs as the same host user
that can read the secret, no repository hook can provide that guarantee.

Run the local harness:

```sh
make ai-hooks-test
```

Codex rules can be checked directly when the Codex CLI is installed:

```sh
codex execpolicy check --pretty --rules .codex/rules/artifactflow.rules -- git push origin main
codex execpolicy check --pretty --rules .codex/rules/artifactflow.rules -- rm -rf storage/framework/views
```
