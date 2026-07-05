# AI Hook Harness

These scripts provide project-level guardrails for AI coding agents.

Configured consumers:

- Claude Code: `.claude/settings.json`
- Codex: `.codex/hooks.json`
- Codex execpolicy: `.codex/rules/artifactflow.rules`

The guards are intentionally conservative:

- `git push` always asks first.
- Recursive deletion such as `rm -rf` always asks first.
- Dangerous recursive deletion targets such as `/`, `.`, `..`, `~`, or `$HOME` are denied.
- Direct Laravel/Pest/PHPUnit test runner commands are denied; use `make test` or `make test TEST_FILTER=...` so tests always run against an isolated test database.
- Secret-bearing files such as `.env`, `.npmrc`, `auth.json`, and private keys are not printed, edited, opened in terminal editors, inspected through key tools such as `openssl`, loaded through `source`/`.`, read through shell redirection, or hidden inside wrapped `eval`/`sh -c` commands.
- Docker volume deletion, destructive git commands, privilege changes, cloud deletes, and network-to-shell installers require approval.
- Prompts that appear to contain pasted credentials are blocked.

Run the local harness:

```sh
make ai-hooks-test
```

Codex rules can be checked directly when the Codex CLI is installed:

```sh
codex execpolicy check --pretty --rules .codex/rules/artifactflow.rules -- git push origin main
codex execpolicy check --pretty --rules .codex/rules/artifactflow.rules -- rm -rf storage/framework/views
```
