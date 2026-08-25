# Agent Instructions

This file is the source of truth for AI agents and automation working in this repository. Read it before changing code, tests, infrastructure, or documentation.

## Project Context

ArtifactFlow is a security-first Laravel application and self-hosted, versioned artifact vault for deliberate tools and documents created with AI.

Upstream repository: `https://github.com/Gadsotek/artifactflow`.
Default commit author: `Gadsotek <14184492+Gadsotek@users.noreply.github.com>`.
Project license: `AGPL-3.0-or-later` with a separate commercial licensing path documented in `COMMERCIAL.md`.

The long-term product direction is a durable system of record for AI-generated artifacts: preserve the authoritative source or original, every retained version, searchable content, ownership, permissions, safe previews or execution, and audit history. ArtifactFlow preserves the output, not the conversation. It is not agent memory, a vector database, a chat archive, an AI generation platform, or static hosting.

The current MVP stays narrower and includes Markdown/wiki pages, single-file HTML artifact pages, normalized PNG/JPEG image or screenshot artifacts, and opt-in native-text PDF artifacts: authenticated users can create Markdown pages with inline Mermaid diagrams, paste or upload single-file HTML artifacts, upload raster images whose metadata and non-pixel payloads are discarded by re-encoding, and upload bounded PDFs whose embedded text is extracted by the dedicated processor. HTML, scriptless image, and native PDF previews stay on the isolated artifact origin; native PDF viewing is explicitly download-equivalent. Users can tag/search/version pages, control access through workspace roles and page overrides, and share internal authenticated links. The active external-sharing slice adds only the narrowly scoped page capabilities in the exclusive expiring or one-time modes defined by `docs/architecture/external-sharing.md`; do not present it as shipped until its required implementation and security proof are complete. Approved AI clients can retrieve authoritative source, normalized image content, or bounded enveloped PDF text; preserve new text/HTML versions; create and replace normalized image or validated PDF artifacts through the dedicated `mcp:upload` boundary; update editable descriptions; organize title/hierarchy/category/tags through `mcp:organize`; and create narrowly scoped external shares for owned editable pages through `mcp:share`, under the same authorization, scanning, parser isolation, concurrency, and audit rules as the web interface. Initial parent assignment remains part of `mcp:create`; binary creation requires `mcp:create` plus `mcp:upload`, replacement requires `mcp:update` plus `mcp:upload`, and workspace creation/settings/membership/administration remain unavailable through MCP. PDF remains default-off and may be enabled in production only with the containment and operator evidence required by `docs/architecture/pdf-artifacts.md`; Word document artifacts remain roadmap work.

Do not drift the MVP into full Confluence parity, public marketplace, AI generation platform, ZIP uploader, approval workflow system, enterprise RBAC suite, non-Mermaid diagram rendering, public search/navigation, unlimited reusable links, or a general public-sharing product. External sharing remains limited to the bearer-capability boundary in `docs/architecture/external-sharing.md`.

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
- Automated browser coverage runs the full Playwright suite on Chromium and the artifact security corpus on Firefox and WebKit. Add `@artifact-security` to the title of any E2E regression whose correctness depends on browser enforcement or engine-specific behavior at the artifact boundary (including CSP, iframe sandboxing, origin/cookie isolation, nested contexts, browser networking APIs, or Mermaid sanitization); ordinary UI tests remain Chromium-only. Playwright WebKit is not released Safari or iOS, so keep the documented manual Safari/iOS security pass.
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
- Treat uploaded raster images as untrusted binary parser input. Accept only bounded PNG/JPEG and perform native decoding only in the dedicated internal parser container; keep the production app image free of GD/EXIF. Bound decoded pixels and non-pixel input work independently: raw bytes, metadata bytes, structure count, and execution time must not escape accounting. Authenticate requests and normalized responses, re-encode decoded pixels, discard the original upload, and serve only the normalized derivative from the artifact origin.
- Treat uploaded PDFs as untrusted binary parser input. Process them only in the dedicated single-worker container with bounded bytes/pages/text/time/memory/processes/tmpfs, no app source or storage credentials, HMAC-authenticated traffic, and an effective outbound-connect deny. Local Unix-socket deployments use `network_mode: none`; private-network deployments must use the published processor image's inherited seccomp deny and verify its startup self-test before enabling PDF.
- Untrusted artifact HTML must never execute in the main application origin or DOM.
- Raw HTML or JavaScript inside Markdown must never execute in the main application origin or DOM.
- Mermaid rendering must use strict security settings and must not require external network calls.
- Preserve the separate app origin and artifact origin boundary in local, CI, and production.
- Artifact preview must use strict iframe sandboxing, strict CSP, signed short-lived URLs, and no app cookies on the artifact host. Browser-native PDF viewing is the sole format-specific iframe-sandbox exception: it may omit the iframe and CSP `sandbox` controls only under `docs/architecture/pdf-artifacts.md`, while retaining the separate cookieless artifact origin, PDF-specific restrictive CSP, signed revision-bound authorization, active-structure rejection, and browser security coverage. Never generalize this exception to HTML or images.
- Image preview must remain scriptless (`sandbox=""` plus header CSP without script permission) and must never serve the original upload bytes.
- Do not add `allow-same-origin`, top navigation, external script, external connect, form submission, or public unauthenticated artifact access without a written architecture decision and security tests.
- Scanning is advisory. Isolation is the security boundary.
- Never log secrets, signed URLs, session tokens, authorization headers, raw credentials, or private artifact content unless explicitly redacted.
- Registered human coworker names, emails, and UIDs are intentionally installation-wide discoverable metadata, not authorization secrets. Keep service accounts out of human pickers and enforce page/workspace authority independently whenever an identifier is submitted.
- Validate input at the boundary and enforce authorization in application behavior, not only in UI state.
- Keep dependency, image, and secret-risk gates green.
- Preserve the pre-session installation-readiness boundary on every app-origin browser and MCP route. A missing or pending migration must fail closed before database sessions or MCP bearer-token lookup; browser requests receive the secured setup page, MCP receives a retryable JSON-RPC 503, `/up` remains session-free, and the artifact origin must not disclose setup state. Cache readiness only for the exact migration-file manifest so a newly deployed migration invalidates success immediately; `make migrate` restores an existing installation, while `make install` is the guided first-time path.
- Preserve the first-admin 2FA enrollment contract: the just-validated login password grants only the configured short enrollment window, both starting and confirming must occur inside it, expiry invalidates the pending secret/QR, and recovery-code login stays behind an explicit alternate-mode control.
- Nested artifact browsing contexts remain prohibited across static markup and dynamic DOM APIs, including legacy `document.execCommand('insertHTML')`. Keep server hardening, the early browser guard, advisory scanning, and PHP/browser attack fixtures aligned when this boundary changes.
- External artifact sharing must follow `docs/architecture/external-sharing.md`: keep raw secrets out of request URLs and persistence, require explicit POST redemption for one-time links, use a distinct window-lived anonymous viewing session plus per-window proof, re-check live share/page state on every load, preserve the existing Markdown, HTML, and image presentation boundaries, and treat the default-off native PDF presenter as download-equivalent without adding a separate anonymous download endpoint.

## Required Gates

Before any commit, all gates must be green:

```sh
make compose-config
make publish-guard
make ai-hooks-test
make ecs
make stan
make run-app-cmd APP_CMD='composer rector'
semgrep --test --config .semgrep/artifactflow.yml .semgrep/artifactflow.php --metrics=off
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

`make quality-full` is the authoritative aggregate for the Make-backed gates above: it runs all of them except `make compose-config` (plus `make verify-reverb-origin`). The Rector dry run and Semgrep rule-fixture test currently run as separate required commands and as explicit CI steps. Run `make compose-config` as well when Docker or environment files change.
`.semgrep/artifactflow.php` is the positive/negative fixture corpus for the custom rules in `.semgrep/artifactflow.yml`. Whenever a custom rule changes, update its fixtures and run the exact `semgrep --test` command above; do not weaken or remove fixture expectations merely to make the gate pass. The corpus intentionally contains synthetic secret-like and unsafe examples for detection tests—never treat them as production credentials or copy them into runtime code, logs, or general documentation.
`make publish-guard` verifies that publishable docs are visible to Git while private handoff, task, and audit materials stay ignored.
`make type-coverage` enforces 100% type coverage through Pest. `make coverage` enforces the committed `COVERAGE_MIN` line coverage floor through PCOV in the dev/test image.

Before committing, also check:

```sh
git status --short
git diff --check
```

Every commit must carry a valid DCO `Signed-off-by` trailer matching the commit
identity. Use `git commit -s` or `git commit --signoff`; GPG signing with `-S`
does not replace DCO sign-off.

Do not commit `.env`, secrets, private keys, certificates, database dumps, local logs, generated reports, `vendor/`, `node_modules/`, or build/cache output.

Never push without explicit user approval for that specific push. Asking before every push is mandatory, even when the branch, remote, or previous approval seems obvious.

Before requesting approval for any push, run `make quality-full` against the current worktree and report the result. If the worktree changes afterwards, rerun the affected gates before pushing.

Never run recursive deletion such as `rm -rf`, `rm -fr`, `rm -r`, or `rm -R` without explicit user approval for that exact command. Do not work around this through shell wrappers, scripts, globs, or aliases.

Never run direct Laravel/Pest/PHPUnit test commands. Use `make test` or `make test TEST_FILTER=...` only, because direct commands may target the local development database.

Project AI guardrails live in `CLAUDE.md`, `.claude/settings.json`, `.codex/hooks.json`, `.codex/rules/artifactflow.rules`, and `scripts/ai-hooks/`. Keep `make ai-hooks-test` green whenever those files change.

## Infrastructure Defaults

- Local development uses the bundled Docker Compose stack. Production uses the same Dockerfile-built image with PostgreSQL, Caddy, and FrankenPHP, but deployment-specific orchestration must not treat the local Compose file as a production template.
- Production images must be buildable from `Dockerfile` and scannable by Trivy.
- Keep app, artifact host, worker, and scheduler roles separate even when they share the same image.
- Do not make local development depend on services that are explicitly post-MVP in the architecture, such as Redis, Meilisearch, S3, or SSO.

## Working Agreement

Before non-trivial implementation work, state the Objective, in-scope work, explicit non-goals, governing architecture or security contracts, and expected tests. Keep that boundary current when the user changes the request.

Do not fix adjacent defects or implement nearby improvements during the current task unless they block the requested outcome or the user explicitly adds them to scope. Report worthwhile adjacent work separately.

If a requested change conflicts with the documented product direction, architecture, security model, or these instructions, stop and call out the conflict before implementing it.

Small changes still need tests when they affect behavior. Documentation-only and ignore-file changes do not require the full gate suite, but they must not reduce or bypass any gate.
