# ArtifactFlow Architecture

Status: MVP alpha architecture
Last updated: 2026-07-08
Primary audience: operators, engineering teams, security reviewers, maintainers, and OSS contributors

> Looking for the diagrams? See the [architecture one-pager](architecture/README.md) (`overview.svg` + `workflows.svg`). **This** document is the full written architecture; that one is just the diagram index.

ArtifactFlow is a security-first Laravel modular monolith for internal Markdown/wiki pages and isolated single-file HTML artifact pages. PostgreSQL is the source of truth. The app is not event-sourced and is not fully event-driven: command handlers synchronously persist important state changes, audit entries, and durable domain events in the same transaction, while normal relational tables hold current state. The durable `domain_events` table is a transactional outbox: a scheduled relay (`artifactflow:dispatch-domain-events`, run every minute by the `scheduler` role) dispatches recorded events after commit, and the single listener registered today is observational (it logs dispatch). Side effects move onto listeners only when asynchronous retry is worth more than same-transaction atomicity.

The central architecture decision is the two-origin artifact boundary. Untrusted HTML never executes on the authenticated application origin. It is stored privately, authorized through the app, signed with a short-lived HMAC URL, and served only by a separate artifact-host runtime with no app cookies.

## Technology Choices

The stack is deliberately boring where boredom buys reliability and deliberately specific where the security model demands it. Rationale, not just inventory:

| Choice | Why | Trade-off accepted |
| --- | --- | --- |
| **PHP 8.5 + Laravel 13** | A mature, batteries-included framework covers auth, sessions, CSRF, queues, scheduling, migrations, and policies out of the box, so the code we own is mostly the security-sensitive parts (the origin boundary, signing, access rules) rather than plumbing. Laravel's middleware/policy model maps cleanly onto the fail-closed posture. | Not the trendiest runtime; leans on framework conventions rather than a bespoke architecture. |
| **PostgreSQL as the single source of truth** | One engine serves relational state, weighted full-text search (`tsvector`, see [Search](#search)), the queue, durable domain events, and audit entries — no second datastore to secure, back up, or keep consistent. Postgres FTS is good enough to avoid a separate search service; transactional guarantees let the storage-quota and version-append invariants hold under row locks. MySQL was not chosen because its full-text and transactional-DDL story is weaker for this workload. | FTS is not a substitute for a dedicated search cluster at large scale; that trade is revisited only if needed. |
| **FrankenPHP + Caddy** | A single production image serves PHP with automatic HTTPS and HTTP/2/3 and no separate FPM/nginx wiring, which keeps the two-origin deployment (`app` vs `artifact-host` from the *same* image, different role) simple to reason about and reproduce. | Newer than the nginx+FPM default; smaller operational community. |
| **Reverb for realtime presence** | First-party Laravel WebSocket server, so presence rides the same auth/channel-authorization stack rather than a third-party service holding a token. It is **opt-in and off by default** (`BROADCAST_CONNECTION=null`) precisely so the base security surface stays minimal — see [Locking And Realtime](#locking-and-realtime). | Presence auth only runs at subscribe time; the residual is documented in [`THREAT-MODEL.md`](../THREAT-MODEL.md). |
| **Docker Compose, multi-role single image** | The origin boundary is real from the first `make up` because app and artifact host run as separate services locally, not just in production. One image, many roles, keeps dev/prod parity high. | Requires Docker for the supported path; no "just run `php artisan serve`" mode. |
| **Modular monolith, not microservices / not full DDD** | One deployable keeps the security boundary auditable in a single place and avoids inter-service auth surface. Business logic lives in application services with an enum-backed domain vocabulary — see [Application Modules](#application-modules) — which is enough structure without aggregate-root ceremony. | Horizontal scaling is per-role, not per-domain; some may prefer richer domain entities. |

## Runtime Topology

The same production image runs several roles. `APP_RUNTIME_ROLE` selects the role for the
HTTP surface split and the boot gate, but the two non-HTTP roles (`worker`, `scheduler`)
also override the container command to their start script — the default entrypoint always
starts the HTTP server. (Scans and search projections run **synchronously** inside the
write transaction, not on the worker; see [Events And Audit](#events-and-audit) and
[Search](#search).)

| Role | Responsibility |
| --- | --- |
| `app` | Authenticated web UI, sessions, CSRF, page/workspace management, MCP endpoint, search, and signed preview URL issuance. |
| `artifact-host` | Cookieless artifact origin: serves immutable saved HTML versions via signed URLs, plus the stateless, non-persisting pre-save draft preview (`POST /artifact-previews/draft`). |
| `worker` | Queue worker (`queue:work`). The only queued work today is outbound mail (invitations, membership notices, password resets). |
| `scheduler` | Laravel scheduler (`schedule:work`): runs the outbox relay `artifactflow:dispatch-domain-events` every minute and the nightly `prune-domain-events` / `prune-credentials` retention jobs. |
| `db` | PostgreSQL for app data, search vectors, queues, audit entries, and durable domain events. |
| `edge` | Optional Caddy reverse proxy for local named-host development or production ingress examples. |

Production deployments must use separate origins:

```text
APP_URL=https://app.example.internal
ARTIFACT_URL=https://artifacts.example.internal
ARTIFACT_FRAME_ANCESTORS=https://app.example.internal
```

The application runtime rejects artifact-host routes. The artifact-host runtime rejects login, dashboard, page-management, and health routes.

## Application Modules

The codebase keeps Laravel conventions but separates business behavior into application services grouped by feature area, with an explicit (mostly enum-backed) domain vocabulary. This is a layered modular monolith, not full Domain-Driven Design: there are no aggregate roots or repositories, and domain rules live in the application layer rather than in rich entities.

| Area | Owns |
| --- | --- |
| `Application/Identity` | Users, personal/shared workspaces, workspace roles, invitations, membership changes, two-factor auth (TOTP, recovery codes, trusted devices), theme preferences, and current workspace context. |
| `Application/PageCatalog` | Page creation, metadata, content versions, Markdown rendering, artifact preview signing/reading, search, tags, categories, access grants, lifecycle changes, and deletion. |
| `Application/Mcp` | MCP server: scoped bearer-token issuance/verification, the `search`/`read`/`create`/`create_category`/`create_tag`/`update`/`revert`/`list_workspaces`/`list_taxonomy` tools, and the Editor-capped effective-authority de-elevation shared with `PageAccess`. |
| `Application/Administration` | System Admin installation usage and runtime limit settings. |
| `Application/Diagnostics` | The read-only deployment doctor (config checks mirroring the production boot gate). |
| `Application/Installation` | Guided install wizard support (env writing, boot-gate value collection, admin bootstrap). |
| `Application/Events` | Durable event recording and stored-event dispatch. |
| `Application/Audit` | User-facing audit entries for important state changes. |
| `Domain/*` | Enums and domain vocabulary such as workspace roles, page types, page statuses, access roles, and theme preferences. |
| `Infrastructure/*` | Security configuration, runtime hardening, and framework adapters. |

Controllers stay thin: they validate HTTP boundary input, authorize through application behavior, call command/query services, and return views or redirects. Blade templates are presentation-only and receive explicit values from controllers and query/view-data helpers.

## Identifiers

Application-owned business records use ULID values exposed as `uid` and `*_uid`. Do not add auto-incrementing numeric IDs for users, workspaces, pages, versions, access grants, audit records, domain events, or other business entities.

Current business tables include:

| Table | Purpose |
| --- | --- |
| `users` | Login identity, system-admin flag, theme preference. |
| `workspaces` | Personal and shared workspaces. |
| `workspace_memberships` | Accepted workspace roles by `workspace_uid` and `user_uid`. |
| `workspace_invitations` | Pending invitations with lifecycle state. |
| `pages` | Current page metadata, ownership, workspace, status, access mode, and current version pointer. |
| `page_versions` | Immutable Markdown or HTML content versions (retention-capped: oldest pruned past `PAGE_MAX_PAGE_VERSIONS`). |
| `page_access_grants` | Page-level user or workspace overrides. |
| `categories`, `tags`, `page_tag` | Workspace-scoped categories, installation-wide tags, and page/tag relationships. |
| `mcp_access_tokens` | Scoped, expiring MCP bearer tokens (stored as hashes; read/write scope and workspace binding). |
| `trusted_devices` | Opaque hashed trusted-device tokens for the two-factor challenge. |
| `installation_settings` | System Admin runtime limits and two-factor enforcement flags. |
| `audit_entries` | User-facing append-only traceability. |
| `domain_events` | Durable transactional outbox records. |

## Page Model

The core unit is a page:

- Markdown pages store portable Markdown source, render sanitized HTML in the app origin, and support strict Mermaid rendering.
- HTML artifact pages store a single-file HTML version, never render that HTML in the app origin, and preview through the artifact-host origin.
- Every content write creates an immutable version. Version *content* is never mutated; the only history that is removed is retention pruning — appending past `PAGE_MAX_PAGE_VERSIONS` deletes the oldest whole version(s) (each recorded as a `page.version.pruned` event) so a page never hits an uneditable version ceiling.
- Version restore creates a new current version rather than mutating history.
- Page metadata, access grants, lifecycle transitions, and content writes record audit entries and durable domain events where traceability matters.
- Archived pages are hidden from default discovery. Hard deletion is irreversible and Admin-only.

## Authorization

Authorization is enforced server-side through the `PageAccess` application service and use-case handlers. Route policies provide a framework-level backstop for page routes and delegate to the same service. UI affordances are convenience only.

Workspace roles:

| Role | Capability |
| --- | --- |
| Reader | View/search inherited pages. |
| Editor | Create and edit allowed pages. |
| Admin | Manage workspace pages, access, memberships, and irreversible page deletion. |

Pages inherit workspace permissions by default. Page-level overrides can narrow or extend access to specific users or workspaces, subject to application rules. Parent/child navigation and search results are authorization-filtered so restricted titles and UIDs are not disclosed.

Registered human accounts form an installation-wide coworker directory. Their names, email addresses, and UIDs are intentionally discoverable to other authenticated humans; System Admin accounts participate like any other human account, while MCP/automation service accounts do not appear in human sharing pickers. These identifiers are never capabilities. Workspace additions still require invitation authority, page grants still require access-management authority with locked-row reauthorization, Reader grants may target any registered human, and Editor/Admin grants require membership in the page workspace.

System Admin is deliberately separate from content authority. It permits installation settings and user administration, but it does not enumerate, view, search, edit, move, share, or delete content in another user's personal workspace or any shared workspace the actor cannot normally reach. A System Admin needs the same workspace membership or explicit page grant as any other account. Installation-wide storage totals may remain aggregate operational telemetry, while workspace names, page titles, and per-workspace/page breakdowns are limited to the actor's own memberships. There is no implicit or hidden break-glass content bypass.

## Two-Factor Authentication

Accounts support TOTP two-factor authentication with single-use, one-way-hashed recovery
codes and revocable trusted devices (opaque hashed cookie tokens). TOTP secrets are
`APP_KEY`-encrypted at rest. Two-factor is required for System Admins by default and can be
enforced for all users through installation settings; enrollment is gated by the
`EnforceTwoFactorEnrollment` middleware. Sensitive actions require a recent password
confirmation (step-up), and minting an MCP token additionally requires a fresh TOTP code.

## MCP Server

An MCP server (`app/Application/Mcp`, `POST /mcp` on the **app** runtime only) lets approved
AI clients call `list_workspaces` / `list_taxonomy` / `search` / `read` / `create` / `create_category` / `create_tag` / `update` / `revert`
through the *same* command handlers, policies, scanners, and optimistic-concurrency checks
as humans. Authority flows through scoped, expiring bearer tokens (hashed at rest,
read-only or read-write, bound to selected workspaces) whose reach is the intersection of
the token scope and the acting user's live memberships. System Admin status never adds
content authority in browser or MCP contexts. `McpEffectiveAuthority` additionally collapses
workspace/page Admin to Editor while an MCP context is active, so a token can never exceed the Editor cap. Read
content is framed as an untrusted-data envelope and never authorizes a write; every write
still needs write scope, live access, and a matching base version.

`search` and `read` include a hierarchy object with the visible direct parent, root-to-parent
ancestor path, visible depth, and visible direct-child count. Ancestor traversal stops at the
first inaccessible page, child counts use the same exact page authorization as search, and
all page-derived titles remain inside untrusted-data envelopes. A hidden relative therefore
never becomes a UID, title, count, or structural metadata side channel.

`list_taxonomy` exposes the filter vocabulary needed to call `search`: global tag UIDs plus
workspace-qualified category UIDs. It includes categories from the principal's reachable
workspaces and tags/categories attached to individually granted pages. It uses the same token
workspace ceiling and exact authorization post-filter as page search, so categories from an
unreachable workspace and private-only tag labels are not a metadata side channel.
Every user-authored taxonomy label and slug is returned inside an `artifactflow.untrusted_data`
envelope. `create` can attach tag names and either select or create a category atomically with
the page; standalone taxonomy creation uses the same category/tag handlers, `mcp:create` scope,
write throttling, live Editor authority, and token workspace ceiling.

## Artifact Security Boundary

Untrusted artifact HTML is contained by isolation, not sanitization.

1. The app origin authorizes the viewer and issues a short-lived signed preview URL. Current and historical previews are distinct signed purposes; adding `purpose=history` to a current URL cannot grant historical access. There is no expiry timer and the application document is never reloaded. A successfully served saved artifact emits a fixed ready signal; if a later self-reload reaches an expired URL and returns without that signal, the authenticated parent renews and restores only that iframe's `src`, preserving any unsaved editor state.
2. The artifact-host origin verifies the HMAC signature, expiry, runtime role, and target page/version.
3. The artifact-host reads immutable content from private storage only after size and signature checks.
4. The response uses strict headers and no app session middleware.
5. The app embeds the preview in an iframe sandboxed with `allow-scripts` and without `allow-same-origin`.

The artifact response CSP is intentionally restrictive:

```text
default-src 'none';
sandbox allow-scripts;
script-src 'unsafe-inline';
style-src 'unsafe-inline';
img-src data: blob:;
font-src data:;
media-src data: blob:;
connect-src 'none';
object-src 'none';
base-uri 'none';
form-action 'none';
frame-src 'none';
child-src 'none';
worker-src 'none';
webrtc 'block';
frame-ancestors <configured app origin>
```

Do not add `allow-same-origin`, top navigation, forms, external scripts, outbound connections, public unauthenticated artifact access, or app-session middleware to the artifact surface without a written architecture decision and security tests.

## Markdown And Mermaid

Markdown and Mermaid source are untrusted user content.

- Markdown renders in the app origin only after sanitization.
- Raw HTML and JavaScript inside Markdown must not execute.
- Mermaid renders with strict security settings and without external network calls.
- Wiki-style links resolve only to authorized same-workspace pages.
- Markdown preview and saved rendering share the same security assumptions.

## Search

Page discovery uses PostgreSQL full-text search with explicit filters and authorization.

Search inputs are untrusted. The search layer uses bounded parsing and PostgreSQL query APIs rather than interpolating raw query text. Search combines metadata, workspace/owner context, tags, and current-version extracted text. Source text is included at lower weight so generated artifacts remain discoverable by technical terms without turning search into a content disclosure channel.

Tags are a single installation-wide vocabulary keyed by slug. Categories remain workspace-scoped because a workspace acts as the top-level project boundary; cross-workspace category filters therefore display `Category — Workspace`, while a single-workspace filter uses the category name alone. Members may discover all categories in their reachable workspaces, while page-only grants reveal only the category and global tags attached to pages the actor may actually view; private-only tag labels and unrelated foreign-workspace categories remain hidden. A workspace move preserves global tag relations and translates the page category by slug, reusing the target category or creating it transactionally when it does not exist.

**Search-vector maintenance (design note).** The `pages.search_vector` `tsvector` is maintained by the application (`PageSearchVectorUpdater`), not by a database trigger or generated column, and it stores a *denormalized* copy of some related labels — the page's owner name, workspace name, and category name — alongside the page's own fields. Every code path that changes an indexed input refreshes the vector explicitly (create, content append, metadata update, workspace move, status change, and — for the denormalized labels — workspace-settings update and member removal). This is safe today because the current model has **no** rename path for the denormalized entities: users and categories are effectively create-only. The consequence to keep in mind: **if a future feature lets a user or category be renamed, that path must also refresh every affected page's `search_vector`**, or search results will silently reflect the stale label until the page is next re-indexed. A database trigger or a `GENERATED` column would remove this obligation entirely and is the natural upgrade if the denormalized set grows or gains rename paths. Uses the `simple` text-search configuration (no stemming/stopwords) for predictable, language-agnostic matching.

## Events And Audit

Important state changes persist inside the same transaction as the write:

- durable domain event in `domain_events`;
- user-facing audit entry when the change should be explainable to users or operators;
- non-secret metadata only.

Events and audit metadata must never contain raw page content, credentials, tokens, authorization headers, full signed URLs, private artifact bytes, or raw search queries.

Examples of traceable actions:

- user login and user creation;
- workspace creation, invitation, membership changes, and settings updates;
- page creation, content version creation, version restore, metadata updates, workspace moves, access grant changes, lifecycle changes, and hard deletion;
- installation limit changes.

## Installation Limits

System Admins can adjust runtime limits for content size, artifact read size, workspace storage, page storage, page versions, and tag counts. The UI is guarded by recent password confirmation and server-side authorization. Installation-wide counts and bytes are aggregate; named workspace/page usage rows appear only for workspaces the System Admin has joined through normal membership. Limit writes are transactional, audited, event-recorded, and bounded by hard ceilings so the UI cannot silently disable memory/storage protections.

## Locking And Realtime

Content updates use optimistic concurrency control at the write boundary: each update includes the expected current version and returns a conflict response when another version won the race.

Reverb is the realtime path for advisory page-editing presence. It must remain outside the artifact security boundary:

- artifact HTML must not receive Reverb credentials;
- artifact-origin JavaScript must not connect to realtime channels;
- channel authorization must use the same user/workspace/page access rules as the web UI;
- realtime presence is advisory UX only, while server-side optimistic concurrency remains the correctness boundary;
- a committed content version may broadcast a minimal newer-version notice only when Reverb is enabled; viewers choose whether to navigate to it, and the client never automatically reloads the application document or discards unsaved editor state;
- page-editing state must be stamped by authenticated server endpoints, not trusted client whispers;
- Reverb client events stay disabled unless a future architecture decision adds a tested server-side relay;
- realtime connections must be rate limited and connection bounded in production.

## Production Fail-Closed Checks

Production boot rejects unsafe deployments, including:

- overlapping app and artifact origins;
- missing, placeholder, short, or reused app/artifact signing keys;
- non-HTTPS production origins;
- debug mode;
- unsafe session settings;
- a session domain that covers the artifact host;
- PostgreSQL transport modes that can downgrade below required TLS;
- public artifact storage;
- missing System Admin bootstrap path;
- artifact frame ancestors that do not match the configured app origin;
- a non-deliverable mail transport (`log`/`array`), which would silently drop invitation and password-reset mail;
- an empty, wildcard, or address-space-wide `TRUSTED_PROXIES` value;
- Reverb production mode without client-event rate limiting or a bounded connection limit.

## Quality Gates

Before release work or commits, run the gates required by `AGENTS.md`:

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
git diff --check
```

`make quality-full` is the authoritative aggregate for the `make` targets above: it runs all of them except `make compose-config` and also runs `make verify-reverb-origin`. The separate `git diff --check` pre-commit check is not part of that aggregate. Run `make compose-config` when Docker or environment files change, run `git diff --check` before committing, and keep `make ai-hooks-test` green whenever the AI guardrail files change.

## Later Surfaces

The following are deliberately outside the current launch boundary unless the architecture is updated first:

- collaborative editing beyond optimistic concurrency plus advisory Reverb presence;
- public sharing;
- SSO or enterprise RBAC expansion;
- S3/object storage migration;
- Redis/Meilisearch;
- multi-file or ZIP artifact uploads;
- approval workflow systems.
