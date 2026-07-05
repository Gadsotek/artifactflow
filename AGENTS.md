# Agent Instructions

This file is the source of truth for AI agents and automation working in this repository. Read it before changing code, tests, infrastructure, or documentation.

## Project Context

ArtifactFlow is a security-first Laravel application for storing, organizing, searching, versioning, and safely rendering AI-generated Markdown/wiki pages and HTML artifacts.

Upstream repository: `https://github.com/Gadsotek/artifactflow`.
Default commit author: `Gadsotek <14184492+Gadsotek@users.noreply.github.com>`.
Project license: `AGPL-3.0-or-later` with a separate commercial licensing path documented in `COMMERCIAL.md`.

The long-term product direction is an internal executable knowledge base, roughly "Confluence on steroids" for rich generated artifacts and architecture knowledge. The current MVP must stay narrower but now includes Markdown/wiki pages plus single-file HTML artifact pages: authenticated users can create Markdown pages with inline Mermaid diagrams, paste or upload single-file HTML artifacts into personal or shared workspaces, preview HTML safely from an isolated origin, tag/search/version pages, control access through workspace roles and page overrides, and share internal authenticated links.

Do not drift the MVP into full Confluence parity, public marketplace, AI generation platform, ZIP uploader, approval workflow system, enterprise RBAC suite, non-Mermaid diagram rendering, or public sharing product unless the project direction and architecture are deliberately updated first.

Before feature work, read this file and `README.md`.

## Engineering Principles

- Layer pragmatically (this is a modular monolith, not full DDD). Keep business rules out of controllers, Blade views, jobs, and Eloquent models when they become domain behavior.
- Prefer application commands/handlers for use cases. Handlers coordinate authorization, transactions, persistence, storage, scanners, and events.
- Model important business concepts explicitly instead of passing anonymous arrays through the system. In practice this is mostly backed enums plus typed command DTOs and cohesive application services over Eloquent; the `app/Domain` layer stays deliberately thin (enums and exceptions), and value objects or domain services are introduced only where they clearly earn their keep, not as a default.
- Keep Laravel conventions where they help. Do not build abstract architecture for its own sake.
- Record durable domain events for important state changes. The command handler owns the transaction, runs cross-boundary side effects (audit entries, search projection updates, notifications) synchronously inside it for atomicity, and persists domain events in the same transaction as a durable journal.
- The domain-event journal is a transactional outbox in shape: events are dispatched to listeners after commit, and new side effects may move onto listeners when async delivery (with retry) is worth more than same-transaction atomicity. Do not present the current design as fully event-driven; today listeners are observational and the synchronous path is the deliberate default.
- The MVP is not event-sourced. PostgreSQL remains the source of truth.
- Preserve traceability for important state changes. Record durable domain events and user-facing audit entries with actor UID, target UID, action, timestamp, and enough non-secret metadata to explain what changed. Do not log secrets, signed URLs, authorization headers, credentials, or private artifact content.

## Test Rules

- TDD is required for new features and production behavior. Write or update a failing test before implementing code.
- Tests must cover happy paths, authorization failures, validation failures, edge cases, and security boundaries.
- Prefer focused Pest/Laravel tests for application behavior. Add browser-level Playwright tests for UI flows and sandbox/security behavior that PHP tests cannot prove.
- Do not weaken tests to make a change pass. Fix the design or implementation.
- Every bug fix needs a regression test that fails without the fix.
- Never run Laravel/Pest/PHPUnit tests directly from the running app container or host with commands such as `php artisan test`, `./vendor/bin/pest`, `./vendor/bin/phpunit`, `docker compose exec app php artisan test`, or `make run-app-cmd APP_CMD='php artisan test ...'`. These commands can inherit the local app database and destroy local data.
- Always run PHP tests through the repository wrapper: `make test` for the full suite, or `make test TEST_FILTER=Name` for focused tests. The wrapper creates an isolated temporary database, injects the testing environment, and drops only that temporary database.
- Always run browser tests through `make e2e`. The wrapper creates an isolated temporary database, starts dedicated `e2e-app` and `e2e-artifact-host` services on separate ports, runs migrations, routes Playwright setup commands to that isolated app, and drops the temporary database afterwards. Do not run Playwright against the normal local dev app unless you intentionally want to mutate the dev database and have explicit approval.
- If you ever realize a test, migration, seed, reset, or database command may have touched the local development database unexpectedly, stop immediately, tell the user exactly what happened, and do not run further database-writing commands without explicit approval.

## Code Style

- New PHP code must use `declare(strict_types=1);` unless a Laravel-generated file format makes that impossible. Document any exception.
- Follow ECS with PSR-12. Do not hand-format around the formatter.
- Keep Larastan level max clean. Avoid `mixed`, broad arrays, and dynamic magic unless the boundary is unavoidable and documented.
- Prefer small cohesive classes over large procedural services.
- No god classes, god services, or god Blade components. Split code by use case, capability, or view concern before one file owns unrelated workflows.
- Avoid traits for business logic, workflow reuse, authorization, persistence, or hidden dependencies. Prefer explicit services, value objects, policies, query objects, DTOs, or small framework adapters. Use traits only for narrow, stateless framework glue when composition would add noise.
- Avoid speculative abstractions. Add interfaces at external boundaries or where the architecture already calls for a port.
- Keep Blade templates presentation-only. They may render values, call named routes, include components, and use simple display conditionals, but they must not contain business rules, authorization decisions, data fetching, mutation logic, query building, parsing, scanning, or security-sensitive branching.
- Move formatting and branching that grows beyond simple presentation into view models, presenters, components, policies, form requests, application handlers, or domain services as appropriate.
- Keep controllers thin. They validate boundary input, authorize through policies, call application handlers/queries, and return views or redirects; they should not accumulate workflow logic.
- Do not pass Eloquent models with lazy side effects deep into views when a typed DTO, view model, or explicit query result would make the template safer and easier to reason about.
- Prefer named routes, form requests, policies, enums, and typed value objects over duplicated strings, ad hoc request reads, or inline status checks in templates.
- Use UID/ULID identifiers for application-owned domain records. Do not introduce auto-incrementing numeric IDs for users, workspaces, pages, versions, access grants, audit records, domain events, or other business entities. Prefer `uid`/`*_uid` naming in domain schema, events, DTOs, and tests; preserve Laravel framework column names only where the framework requires them.

## Security Rules

Security is the first design constraint, not a final review step.

- Treat uploaded or pasted artifact HTML as untrusted executable content.
- Treat Markdown and Mermaid source as untrusted user content.
- Untrusted artifact HTML must never execute in the main application origin or DOM.
- Raw HTML or JavaScript inside Markdown must never execute in the main application origin or DOM.
- Mermaid rendering must use strict security settings and must not require external network calls.
- Preserve the separate app origin and artifact origin boundary in local, CI, and production.
- Artifact preview must use strict iframe sandboxing, strict CSP, signed short-lived URLs, and no app cookies on the artifact host.
- Do not add `allow-same-origin`, top navigation, external script, external connect, form submission, or public unauthenticated artifact access without a written architecture decision and security tests.
- Scanning is advisory. Isolation is the security boundary.
- Never log secrets, signed URLs, session tokens, authorization headers, raw credentials, or private artifact content unless explicitly redacted.
- Registered human coworker names, emails, and UIDs are intentionally installation-wide discoverable metadata, not authorization secrets. Keep service accounts out of human pickers and enforce page/workspace authority independently whenever an identifier is submitted.
- Validate input at the boundary and enforce authorization in application behavior, not only in UI state.
- Keep dependency, image, and secret-risk gates green.

## Required Gates

Before any commit, all gates must be green:

```sh
make compose-config
make publish-guard
make ai-hooks-test
make ecs
make stan
make semgrep
make test
make type-coverage
make coverage
make audit
make build-assets
make e2e
make build-prod
make scan-image
```

`make quality-full` is the authoritative aggregate: it runs every gate above except `make compose-config` (plus `make verify-reverb-origin`). Run `make compose-config` as well when Docker or environment files change.
`make publish-guard` verifies that publishable docs are visible to Git while private handoff, task, and audit materials stay ignored.
`make type-coverage` enforces 100% type coverage through Pest. `make coverage` enforces the committed `COVERAGE_MIN` line coverage floor through PCOV in the dev/test image.

Before committing, also check:

```sh
git status --short
git diff --check
```

Do not commit `.env`, secrets, private keys, certificates, database dumps, local logs, generated reports, `vendor/`, `node_modules/`, or build/cache output.

Never push without explicit user approval for that specific push. Asking before every push is mandatory, even when the branch, remote, or previous approval seems obvious.

Never run recursive deletion such as `rm -rf`, `rm -fr`, `rm -r`, or `rm -R` without explicit user approval for that exact command. Do not work around this through shell wrappers, scripts, globs, or aliases.

Never run direct Laravel/Pest/PHPUnit test commands. Use `make test` or `make test TEST_FILTER=...` only, because direct commands may target the local development database.

Project AI guardrails live in `CLAUDE.md`, `.claude/settings.json`, `.codex/hooks.json`, `.codex/rules/artifactflow.rules`, and `scripts/ai-hooks/`. Keep `make ai-hooks-test` green whenever those files change.

## Infrastructure Defaults

- Local development uses the bundled Docker Compose stack. Production uses the same Dockerfile-built image with PostgreSQL, Caddy, and FrankenPHP, but deployment-specific orchestration must not treat the local Compose file as a production template.
- Production images must be buildable from `Dockerfile` and scannable by Trivy.
- Keep app, artifact host, worker, and scheduler roles separate even when they share the same image.
- Do not make local development depend on services that are explicitly post-MVP in the architecture, such as Redis, Meilisearch, S3, or SSO.

## Working Agreement

If a requested change conflicts with the documented product direction, architecture, security model, or these instructions, stop and call out the conflict before implementing it.

Small changes still need tests when they affect behavior. Documentation-only and ignore-file changes do not require the full gate suite, but they must not reduce or bypass any gate.
