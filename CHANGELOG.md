# Changelog

All notable changes to ArtifactFlow will be documented here.

This project is pre-1.0; expect breaking changes between alpha revisions.

## Unreleased

### Security

- Disabled the declarative-shadow-DOM parser entry points (`Document.parseHTMLUnsafe`, `Element.prototype.setHTMLUnsafe`, `ShadowRoot.prototype.setHTMLUnsafe`) in the artifact-preview guard instead of sanitizing their input. Input sanitization could not reach a nested browsing context hidden inside a declarative shadow root, which the parser materialized into a live, script-executing frame — a bypass of the nested-context defense confirmed by an empirical Chromium harness and now covered by draft-preview and saved-artifact end-to-end tests.

## v0.0.4 — 2026-07-22

Security release. It binds browser sessions to the user authentication revision, closes the remaining old-password login race, makes the MCP transport fully session-free, clears the grpc-go HIGH finding from the production image, adopts laravel/mcp v0.9.1 with the upstreamed malformed-parameter fix, and prompts users to review surviving MCP tokens after a password reset.

### Security

- Bound authenticated browser sessions to the user authentication revision, closing the verified race where an old-password login could finish concurrently with a completed password reset. Sessions created before this release are logged out once on upgrade. (#21)
- Prompted users once after their first successful post-reset login to review any active MCP tokens that deliberately survived the password reset. (#21)
- Made every Laravel MCP HTTP route session-free and applied the pre-authenticated source-IP limiter to the package-generated compatibility methods as well as POST requests. (#21)
- Rebuilt the production FrankenPHP binary with grpc-go v1.82.1, clearing GHSA-hrxh-6v49-42gf from the nightly image audit while upstream still resolves the vulnerable dependency. (#21)

### Fixed

- Returned the retryable JSON-RPC installation-readiness response for every MCP transport method, including package-generated GET and DELETE compatibility routes. (#21)
- Required the artifact read ceiling to cover both Markdown and HTML write ceilings at validation, application, production-startup, diagnostic, and database boundaries. (#21)
- Advanced page metadata revisions when member removal reassigns ownership, so an already-open metadata form receives a conflict instead of overwriting the chosen replacement owner. (#21)

### Changed

- Upgraded laravel/mcp to v0.9.1, which ships our upstreamed malformed JSON-RPC parameter fix, and removed the local server-side shim it replaces. List-shaped tool arguments are now rejected at the protocol layer (-32602) instead of reaching tool-level validation. (#21)

## v0.0.3 — 2026-07-21

Security and correctness release. It completes the official Laravel MCP migration, closes the remaining authentication and concurrent-mutation races found by adversarial review, repairs rich-Markdown serialization, and hardens installation, backup, restore, and release operations.

### Security

- Replaced the hand-written MCP JSON-RPC transport with the official `laravel/mcp` package while preserving app-origin routing, installation readiness, scoped bearer tokens, the Editor authority ceiling, throttling, untrusted-data envelopes, and audit attribution. (#16)
- Serialized password reset and invitation workflows, invalidated stale 2FA login challenges after password changes, and made invitation delivery transactional without exposing invitation bearer data. (#16)
- Revalidated the locked user and authentication revision during MCP token issuance, closing the race where a token could otherwise be issued from stale password/TOTP verification state. Existing MCP tokens deliberately survive an ordinary password reset; operators can revoke them separately after a suspected compromise. (#17)
- Closed additional artifact-preview HTML tokenizer differentials while preserving the two-origin, opaque sandbox boundary. (#16)
- Tightened the fail-closed production gate and read-only doctor checks for database TLS root certificates, deliverable mail configuration, runtime role state, bootstrap credentials, and migration readiness. (#17)
- Added one-shot password-file inputs for administrative console commands so credentials need not appear in process arguments or shell history. (#17)

### Fixed

- Fixed rich-Markdown list and inline-format serialization that could absorb subsequent list items and code blocks into bold text. (#16)
- Made page metadata updates revision-aware under row lock, including workspace moves, so stale forms return `409` instead of overwriting newer ownership or metadata. (#17)
- Enforced hard-delete title confirmation under the page lock and mapped concurrent user-email uniqueness conflicts to the documented domain error. (#17)
- Made MCP token revocation report only the process that performed the actual state transition during concurrent revocations. (#17)
- Removed a fail-open installation-readiness memoization path in long-running workers. (#17)

### Changed

- Extended MCP client connection setup to discover and explicitly select among Claude Desktop, Claude Code, Codex homes, and Codex profiles without storing bearer tokens in repository configuration. (#16)
- Backup manifests now include a format version and SHA-256 hashes for both payloads. Restore verifies integrity before changing service state, requires application roles to be quiescent, and offers an explicit legacy-manifest upgrade path. (#17)
- Workspace names and MCP token names reject non-storable control characters at the validation boundary. (#17)

### Internal / Tooling

- Tag-driven releases now depend on the complete CI workflow before publishing images or GitHub Releases. (#17)
- Granted the reusable release CI gate its required read-only pull-request scope so tag workflows pass GitHub's startup validation.
- Expanded deterministic concurrency, deployment-gate, backup/restore, editor, sandbox-parser, and operational regression coverage. (#16, #17)
- Stabilized the saved-preview recovery E2E test by synchronizing on parent-observed iframe loads and the renewal response instead of racing the artifact's self-navigating document.

### Dependencies

- Added `laravel/mcp` 0.9.x and updated the locked Guzzle/PSR-7 and `shell-quote` versions to resolve published advisories. (#16)

## v0.0.2 — 2026-07-19

Security-hardening release. No new end-user features; it tightens the untrusted-artifact isolation boundary, closes several artifact-preview parser differentials, hardens mass assignment and rate limiting, and patches the base image.

### Security

- Artifact preview blocks nested browsing contexts and WebRTC egress: static `iframe`/`frame`/`fencedframe`/`portal` tags are neutralized server-side, and the early guard removes fresh `srcdoc`/`about:blank` child realms that bypass parent-realm API patches. A real UDP STUN listener regression-tests that no packet escapes. (#6)
- Hardened that nested-context neutralization against HTML parser differentials, including a neutralized iframe's inert `template` wrapper being closed from its raw-text interior. (#10)
- Closed comment- and declaration-parser differentials in the artifact-preview hardener. (#11)
- Require the artifact storage root to live outside the public web root; the production boot gate now fails closed otherwise. (#12)
- Rate limiting now requires a persistent cache store in production (boot gate), and workspace invitation tokens are stored hashed. (#13)
- Archiving a page increments `preview_access_revision`, so outstanding signed preview URLs are invalidated immediately on archive instead of waiting out the TTL.
- Locked down mass assignment on credential, authority, immutable-content, and installation-settings models (`McpAccessToken`, `TrustedDevice`, `PageVersion`, `InstallationSettings`) with `$guarded = ['*']`.
- Disabled legacy `document.execCommand('insertHTML')` inside artifact previews so it cannot create a nested browsing context during the MutationObserver microtask window; the advisory scanner and browser attack corpus now pin the rule.
- Production rate limiting now requires a shared database, Redis, Memcached, or DynamoDB counter backend; node-local file caches are rejected so limits cannot multiply across app replicas.

### Fixed

- Content saves keep succeeding when the after-commit realtime broadcast fails. (#8)
- Fresh and partially migrated deployments now return a secured, session-free setup-required response instead of exposing a missing-database-table exception; `/up` stays available during installation. The same manifest-aware gate covers MCP before token authentication and returns a retryable JSON-RPC 503 until migrations are current.

### Changed

- The saved-artifact preview-ready recovery signal is now a per-load nonce handshake (the parent posts a nonce; the opaque-origin document echoes it back), so a stale or pre-sent signal can't suppress URL recovery.
- A password login now opens a visible three-minute window to start and finish first-time 2FA enrollment without immediately re-entering the same password; expiry returns to password confirmation and invalidates the pending QR/secret so restarting generates a fresh one.
- The 2FA login challenge now presents recovery-code entry as an explicit alternate mode, hidden until requested; invalid authenticator and recovery values are excluded from flashed session input.
- Search ranking/match SQL moved to static `literal-string` constants; behavior is unchanged and user input stays parameterized.
- Threat model clarified: the full set of transitions that invalidate signed URLs, both embedding-iframe surfaces (current and historical version), and the ready-signal handshake.
- Operations guidance now distinguishes ordinary network APIs from the accepted self-navigation residual and requires upstream log redaction for invitation/reset bearer URLs.

### Internal / Tooling

- Broadened the best-effort AI agent guard hooks and documented the repository-shipped hooks. (#4, #7)
- Added architecture and infrastructure contract tests (mass-assignment, raw-SQL tripwire, workspace-scoped foreign keys, AI-harness drift, DCO validation) plus Semgrep rules, an explicit positive/negative Semgrep fixture corpus, and a Rector dry-run (`composer rector`).

### Dependencies

- Bumped the FrankenPHP production base image, clearing Go CVE-2026-39822 (`os.Root` symlink traversal); c-ares held at ≥ 1.34.8-r0 for CVE-2026-33630. (#2)
- Bumped vite 8.1.4 → 8.1.5 (#3) and nunomaduro/collision (#1).

## v0.0.1 — Alpha (2026-07)

First public release.

- Markdown/wiki pages with a rich editor over portable Markdown source, inline Mermaid diagrams (strict security mode, no external calls), and authorization-aware `[[Page Name]]` wiki links.
- Single-file HTML artifact pages (paste or upload) rendered only from an isolated, cookieless artifact origin behind sandboxed iframes and short-lived HMAC-signed URLs; pre-save draft preview uses an authenticated, short-lived HMAC capability bound to the exact content before rendering in the same opaque no-network sandbox.
- Immutable page versioning with restore, archive/unarchive, and Admin-only hard delete.
- Weighted PostgreSQL full-text search across metadata, tags, and extracted content.
- Personal and shared workspaces with Reader/Editor/Admin roles and per-page access overrides.
- Installation-wide human coworker autocomplete for workspace membership and page access. Human names, emails, and UIDs are intentionally discoverable to authenticated coworkers but never confer authority; service accounts stay out of human pickers and every mutation remains server-authorized. Explicit page Reader and Editor grants do not require workspace membership; page Admin grants do.
- MCP server (app origin only) with scoped, expiring bearer tokens hard-capped to Editor authority.
- TOTP two-factor auth with recovery codes and trusted devices; step-up confirmation on sensitive actions.
- Advisory content scanning with secret blocking on save; durable domain events and an append-only audit trail.
- Docker-based self-hosting with a local Compose quickstart, a multi-role production image, guided install wizard, config doctor, backup/restore tooling, and a fail-closed production boot gate.
- The per-page version limit (`PAGE_MAX_PAGE_VERSIONS`) is a **retention cap**, not a hard wall: appending a new version past the cap prunes the oldest whole version(s) instead of rejecting the edit, so a heavily-edited page can never become uneditable. Retained version content stays immutable; each pruned version is recorded as its own `page.version.pruned` domain event + audit entry, its bytes are released from the workspace storage counter, and its blob is deleted after commit. A post-commit deletion failure can leave an orphan blob; operators can inspect and remove those with the manual `artifactflow:prune-orphan-artifacts` command, while automatic orphan garbage collection remains deferred. Applies to editor, MCP, restore, and revert appends.
- Docker Compose mirrors the published host ports (`APP_PORT`, `ARTIFACT_HOST_PORT`) into the containers, and `artifactflow:doctor` warns when a host port and the URL that embeds it (`APP_URL`, `ARTIFACT_URL`) were not changed together. Local-only usability check; skipped in production and never a boot-gate failure.
