# ArtifactFlow: Architecture (one-pager)

Two self-contained SVGs (open in any browser; embeddable in the main README):

> For the full **written** architecture — layers, application modules, the runtime-role split — see [`../ARCHITECTURE.md`](../ARCHITECTURE.md). This page is just the diagram index.

| Diagram | What it answers |
|---|---|
| [`overview.svg`](overview.svg) | **Where what lives**: the layers, the application modules, and the directory map |
| [`workflows.svg`](workflows.svg) | **Design & workflows**: the two-origin security model + the request flows that matter |

> Diagrams reflect the *actual* current code. Yes, the implementation is AI-assisted; the rigor behind it (repeated security audits, PHPStan-max, a documented threat model, a broad test suite including browser-level sandbox proofs) is the point.

## The 30-second model

**Layered, modular, two-origin Laravel app.**

- **HTTP** (`app/Http`): thin controllers (parse → authorize → delegate → respond), a middleware pipeline that enforces the runtime-role split + security headers + sudo step-up, and FormRequests for validation.
- **Application** (`app/Application`): where the logic lives, as `command → handler` use-cases:
  - `Identity/`: users, workspaces (Personal/Shared), memberships (Admin/Editor/Reader), invitations.
  - `PageCatalog/` ★ (the core): pages (Markdown | HtmlArtifact), immutable versions, access grants, categories/tags, search, rendering, the artifact-preview signing/serving.
  - `Administration/`: installation-wide limit settings (newest, cleanest code).
  - `Events/` (transactional outbox) + `Audit/` (append-only trail): cross-cutting.
  - `PageAccess`: the central authorization service: `canView` / `canEdit` (content) / `canManageAccess` + `canChangeAccessMode` + `canArchive` + `canHardDelete` + `canTransferOwnership` (admin-class). Thin Laravel Policies (`app/Policies`) delegate to it so routes can use `can:` middleware as a defense-in-depth backstop (see `docs/ARCHITECTURE.md`).
- **Domain** (`app/Domain`): backed enums + exceptions (anemic; rules live in Application).
- **Persistence**: Eloquent models (ULID PKs, `$fillable`-guarded under strict mass-assignment) → PostgreSQL (incl. a `search_vector` GIN index, `domain_events`, `audit_entries`) + an `artifacts` filesystem disk (`pages/{uid}/versions/{n}-{version_uid}/…`).
- **Cross-cutting / boot**: `Infrastructure/Security/ProductionSecurityConfiguration` (fail-closed prod boot) and `Providers/AppServiceProvider` (strict mass-assign, rate limiters, the outbox listener).

## The crown jewel: the two-origin "cage"

Untrusted AI-generated HTML is contained by **isolation, not sanitisation**:

- **App origin** (`APP_RUNTIME_ROLE=app`): cookie/session, CSRF, nonce-CSP, `RejectArtifactHostRuntime`. The user's trust boundary; nothing untrusted executes here.
- **Artifact-host origin** (`APP_RUNTIME_ROLE=artifact-host`): cookieless, `RequireArtifactHostRuntime`, serves untrusted artifact bytes with `sandbox allow-scripts; connect-src 'none'; default-src 'none'`. The artifact's JS *runs* here: in an opaque origin with no cookies, no network, and no reach back to the app origin.
- They're the **same codebase**; `runtime_role` (config) flips the behaviour. They're bridged by a short-lived **HMAC-signed URL** loaded into an `<iframe sandbox="allow-scripts">` (no `allow-same-origin`).

See `workflows.svg` for the full create-page write pipeline, the cross-origin preview flow, the admin step-up, and the outbox.

## Current cleanup state

- The page write pipeline is factored through explicit collaborators such as `PageVersionWriter`, `WorkspaceStorageQuota`, `TagSynchronizer`, `ActorId`, and `SlugGenerator`.
- Authorization is enforced by the shared `PageAccess` application service and handler-level checks, with route-level `can:` middleware backed by thin Policies as a second layer.
- **MCP ships today**: `POST /mcp` (JSON-RPC, scoped bearer tokens, `app/Application/Mcp/`) is part of the runtime surface, as is Reverb-backed realtime presence/locking (`pages.presence.update`, broadcast auth via `PageAccess`).

## Genuinely future surfaces

- Public (non-MCP) APIs.
- Collaborative editing.
