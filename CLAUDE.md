# Claude Code Instructions

`AGENTS.md` is the canonical project instruction file. Read it before making changes.

Critical working agreements:

- Security is the first design constraint.
- Do not run `git push` without explicit user approval for that exact push.
- Do not run recursive deletion commands such as `rm -rf` without explicit user approval.
- Do not read, print, copy, or expose `.env`, private keys, package auth files, tokens, or credentials.
- Do not run Laravel/Pest/PHPUnit tests directly with `php artisan test`, `./vendor/bin/pest`, `./vendor/bin/phpunit`, `docker compose exec app php artisan test`, or `make run-app-cmd APP_CMD='php artisan test ...'`; use `make test` or `make test TEST_FILTER=...` only.
- If a command may have touched the local development database unexpectedly, stop immediately and tell the user before running any further database-writing commands.
- New production behavior requires tests first, including edge cases and security boundaries.
- Before any commit, the required gates in `AGENTS.md` must be green.
- Before requesting approval for a push, run `make quality-full` against the current worktree and report the result.
- State the task objective, in-scope work, explicit non-goals, governing contracts, and expected tests before non-trivial implementation.
- Do not fix unrelated or adjacent defects unless they block the requested outcome or the user explicitly adds them to scope.
- Every commit requires DCO sign-off through `git commit -s` or `git commit --signoff`.

Project Claude hooks live in `.claude/settings.json` and share their implementation with Codex hook scripts under `scripts/ai-hooks/`.
