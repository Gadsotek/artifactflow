# ArtifactFlow Architecture

Status: MVP alpha architecture
Last updated: 2026-08-30
Primary audience: operators, engineering teams, security reviewers, maintainers, and OSS contributors

> Looking for the diagrams? See the [architecture one-pager](architecture/README.md) (`overview.svg` + `workflows.svg`). **This** document is the full written architecture; that one is just the diagram index.

ArtifactFlow is a security-first Laravel modular monolith and self-hosted, versioned artifact vault for tools and documents created with AI. The current alpha preserves Markdown/wiki pages, isolated single-file HTML artifacts, normalized images, and default-off PDF, XLSX, and DOCX documents with their authoritative source or original, retained versions, searchable content, permissions, and audit history. PostgreSQL is the source of truth. The app is not event-sourced and is not fully event-driven: command handlers synchronously persist important state changes, audit entries, and durable domain events in the same transaction, while normal relational tables hold current state. The durable `domain_events` table is a transactional outbox: a scheduled relay (`artifactflow:dispatch-domain-events`, run every minute by the `scheduler` role) dispatches recorded events after commit, and the single listener registered today is observational (it logs dispatch). Side effects move onto listeners only when asynchronous retry is worth more than same-transaction atomicity.

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
| `app` | Authenticated web UI, sessions, CSRF, page/workspace management, MCP endpoint, search, saved-preview URL issuance, and Editor-authorized content-bound draft-capability issuance. |
| `artifact-host` | Cookieless artifact origin: serves immutable saved HTML versions via signed URLs and verifies short-lived capabilities before reflecting a non-persisted pre-save draft (`POST /artifact-previews/draft`). |
| `image-parser` | Networkless PNG/JPEG decoder and normalizer over an authenticated Unix socket. |
| `pdf-processor` | Networkless PDF validator and bounded text extractor; also independently validates DOCX-derived previews. |
| `xlsx-processor` | Networkless strict XLSX package validator and SheetJS typed-manifest projector. |
| `docx-processor` | Networkless strict DOCX validator and pinned LibreOffice passive-PDF converter. |
| `worker` | Queue worker (`queue:work`). The only queued work today is outbound mail (invitations, membership notices, password resets). |
| `scheduler` | Laravel scheduler (`schedule:work`): runs the outbox relay `artifactflow:dispatch-domain-events` every minute and the nightly `prune-domain-events`, `prune-credentials`, `prune-external-shares`, and `prune-rate-limit-cache` retention jobs. |
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
| `Application/Provenance` | Immutable per-version ingest records, typed producer assertions, external origin references, restore lineage, and authorized provenance read models. |
| `Application/Mcp` | MCP application behavior: scoped bearer-token issuance/verification, transport-neutral tool results, tool handlers, and the Editor-capped effective-authority de-elevation shared with `PageAccess`. |
| `Mcp` | Official Laravel MCP server and tool adapters: protocol negotiation, JSON-RPC transport, tool schemas, and delegation into `Application/Mcp`. |
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
| `page_versions` | Immutable authoritative payload versions, including exact private PDF/XLSX/DOCX originals (retention-capped: oldest pruned past `PAGE_MAX_PAGE_VERSIONS`). |
| `page_version_derivatives` | Hash/size-bound XLSX typed manifests and DOCX preview PDFs owned by one immutable version. |
| `xlsx_version_facts`, `docx_version_facts` | Non-content processor/profile/count facts that bind each office derivative to its version. |
| `page_version_ingests` | Durable ArtifactFlow-observed ingest facts, immediate lineage, and resolved root content origin for each version; retained after ordinary version-content pruning. |
| `producer_assertions` | Append-only AI, human, or software producer claims with explicit evidence type and supersession lineage. |
| `external_origin_references` | Sensitive, separately redactable artifact/conversation/session/source references attached to assertions. |
| `page_access_grants` | Page-level user or workspace overrides. |
| `categories`, `tags`, `page_tag` | Workspace-scoped categories, installation-wide tags, and page/tag relationships. |
| `mcp_access_tokens` | Scoped, expiring MCP bearer tokens (stored as hashes; read/write scope and workspace binding). |
| `mcp_client_sessions` | Bounded, unverified MCP-reported `clientInfo` metadata, capped at the newest 64 transport sessions per access token and deleted with that token. |
| `trusted_devices` | Opaque hashed trusted-device tokens for the two-factor challenge. |
| `installation_settings` | System Admin runtime limits and two-factor enforcement flags. |
| `audit_entries` | User-facing append-only traceability. |
| `domain_events` | Durable transactional outbox records. |

## Page Model

The core unit is a page:

The product-facing rules for stable identity, immutable content versions, draft lifecycle state, metadata revisions, and future document payloads are documented in [ARTIFACT-LIFECYCLE.md](ARTIFACT-LIFECYCLE.md).

- Markdown pages store portable Markdown source, render sanitized HTML in the app origin, and support strict Mermaid rendering.
- HTML artifact pages store a single-file HTML version, never render that HTML in the app origin, and preview through the artifact-host origin.
- Image pages accept only bounded PNG/JPEG uploads. A dedicated internal parser container performs native decoding and re-encoding; the app verifies its signed normalized response before storage, keeps no OCR text, and previews the derivative through a fixed scriptless artifact-host document.
- PDF pages retain a validated exact original and bounded embedded-text facts;
  the browser-native viewer is a documented download-equivalent exception on
  the cookieless artifact origin.
- XLSX pages retain the exact original plus a canonical typed visible-sheet
  manifest from the isolated SheetJS processor. Preview and MCP use only the
  manifest; formula code is never evaluated.
- DOCX pages retain the exact original plus a passive PDF converted in the
  isolated LibreOffice processor and independently accepted by the PDFBox
  DOCX-preview profile. Preview never uses converted HTML or DOCX bytes.
- Every content write creates an immutable version. Version *content* is never mutated; the only history that is removed is retention pruning — appending past `PAGE_MAX_PAGE_VERSIONS` deletes the oldest whole version(s) (each recorded as a `page.version.pruned` event) so a page never hits an uneditable version ceiling.
- Every content write also creates an immutable ingest record in the same transaction. Observed actor/client/method/hash facts are separate from zero or more declared producer assertions; detailed product and architecture decision records remain internal.
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

The active beta design allows shared workspaces to form a maximum three-level
tree. Direct memberships remain stored once at their origin and flow downward;
inheritance is enabled by default at each child, while a creation-time opt-out
or per-user child exclusion can stop ancestor origins at that boundary.
Effective authority is the strongest surviving direct or inherited role, and
a direct role at an exclusion boundary remains independently effective. Personal
workspaces remain standalone, selected workspace catalogs remain exact rather
than rolling up descendants, and selected MCP workspace scopes never expand
implicitly. The complete persistence, mutation, settings, revocation, and
disclosure contract is in
[`architecture/nested-workspaces.md`](architecture/nested-workspaces.md).

Registered human accounts form an installation-wide coworker directory. Their names, email addresses, and UIDs are intentionally discoverable to other authenticated humans; System Admin accounts participate like any other human account, while MCP/automation service accounts do not appear in human sharing pickers. These identifiers are never capabilities. Workspace additions still require invitation authority, page grants still require access-management authority with locked-row reauthorization, Reader grants may target any registered human, and Editor/Admin grants require membership in the page workspace.

System Admin is deliberately separate from content authority. It permits installation settings and user administration, but it does not enumerate, view, search, edit, move, share, or delete content in another user's personal workspace or any shared workspace the actor cannot normally reach. A System Admin needs the same workspace membership or explicit page grant as any other account. Installation-wide storage totals may remain aggregate operational telemetry, while workspace names, page titles, and per-workspace/page breakdowns are limited to the actor's own memberships. There is no implicit or hidden break-glass content bypass.

## Two-Factor Authentication

Accounts support TOTP two-factor authentication with single-use, one-way-hashed recovery
codes and revocable trusted devices (opaque hashed cookie tokens). TOTP secrets are
`APP_KEY`-encrypted at rest. Two-factor is required for System Admins by default and can be
enforced for all users through installation settings; enrollment is gated by the
`EnforceTwoFactorEnrollment` middleware. Account-security actions require a recent password
confirmation (step-up), entering Administration requires a live authenticator or single-use
recovery code, and minting an MCP token additionally requires a fresh TOTP code. Revoking one
trusted device advances the user's authentication revision: the revoker's current session is
rebound to the committed revision, while every older or concurrently finalized session fails its
next live revision check.

## MCP Server

An MCP server (`app/Mcp`, backed by the official `laravel/mcp` package, with application
behavior in `app/Application/Mcp`) is exposed at `POST /mcp` on the **app** runtime only. It lets approved
AI clients call `list_workspaces` / `list_taxonomy` / `search` / `read` /
`create` / `create_image` / `create_pdf` / `create_xlsx` / `create_docx` /
the matching binary replacement tools / `create_category` / `create_tag` /
`update` / `update_description` / `revert` / `create_external_share`
through the *same* command handlers, policies, scanners, and optimistic-concurrency checks
as humans. Authority flows through scoped, expiring bearer tokens (hashed at rest,
read-only or read-write, bound to selected workspaces) whose reach is the intersection of
the token scope and the acting user's live memberships. Under the nested-workspace beta design,
selected workspace UIDs remain exact: parent scope does not imply descendant scope, while live
authority in an explicitly selected child may be inherited. System Admin status never adds
content authority in browser or MCP contexts. `McpEffectiveAuthority` additionally collapses
workspace/page Admin to Editor while an MCP context is active, so a token can never exceed the Editor cap. Read
text content is framed as an untrusted-data envelope, while normalized image reads add a standard MCP image content block beside an untrusted metadata envelope. XLSX content reads require an exact visible sheet and an uppercase A1 range capped at 1,000 cells, then return only that response-size-checked typed selection and safe facts; DOCX reads return only text extracted from the validated preview and safe facts. Neither office original nor the DOCX PDF derivative is returned. None authorizes a write; every write
still needs write scope, live access, and the matching content-version or metadata-revision token required by that operation.

Laravel request objects and schema arrays stop at the MCP adapter. Every argument-bearing application
tool receives a dedicated immutable input DTO, and every success or tool error is a named payload
composed from typed page, hierarchy, taxonomy, provenance, and format-fact views. One explicit encoder
calls whitelisted `toWire()` methods at the transport edge. It does not reflect over objects, retain
Eloquent models in response DTOs, or call model `toArray()`, so a newly added persistence attribute
cannot silently become part of the MCP contract. Typed lists and final JSON projections remain arrays
where they represent the framework or wire format; anonymous associative arrays are not application
tool contracts.

Each tool invocation takes a shared credential-scoped PostgreSQL advisory lease, reloads the live
token and principal after acquiring it, and holds the lease until the tool finishes. Manual and
principal-wide token revocation take the matching exclusive lease, making use and revocation a
single ordering: an invocation either finishes before revocation commits or observes the revoked
credential and does not enter the tool. The lease is session-scoped rather than an outer database
transaction, so image parser, scanner, and storage work does not hold transactional locks open.

`update_description` requires both the observed current-version UID and the page's separate optimistic metadata revision, then deliberately changes only the description. The version value binds observation-derived text to the exact content or pixels the caller inspected; the metadata revision prevents overwriting a concurrent description or other catalog edit. Together they prevent image-derived text from being attached after the pixels are replaced while preserving human-managed title, owner, parent, category, and tags and reusing the normal description scanner, search projection, audit event, and MCP token/session attribution.

`create_external_share` requires the separately opt-in `mcp:share` write scope.
It does not inherit browser access-management authority: the page must be
inside the token's workspace ceiling, owned by the MCP principal, and still
editable by that principal, and the workspace must allow Editors and page
owners to share pages. The shared application handler performs the normal
policy, lock, installation-limit, secret-hashing, event, and audit work.
Human and service-account principals follow the same rule. MCP authority is
de-elevated to Editor even for an underlying workspace administrator, so the
workspace sharing switch always applies on this path. The raw bearer URL is
returned once in the tool response and is excluded from persistence, events,
and audit metadata.

Laravel MCP owns protocol-version negotiation, standard session IDs, JSON-RPC framing,
tool discovery/schema serialization, and lifecycle notifications. ArtifactFlow middleware
still runs before the package transport to enforce installation readiness, runtime/origin
isolation, pre-authentication IP throttling, token authentication, and authenticated rate
limits. The framework adapter activates scoped authority only while a tool executes and
clears it afterward so long-lived workers cannot retain MCP authority between calls.

`search` and `read` include a hierarchy object with the visible direct parent, root-to-parent
ancestor path, visible depth, and visible direct-child count. Ancestor traversal stops at the
first inaccessible page, child counts use the same exact page authorization as search, and
all page-derived titles remain inside untrusted-data envelopes. A hidden relative therefore
never becomes a UID, title, count, or structural metadata side channel.

`read` accepts optional `content` and `provenance` sections. Omitting `include` selects both and
preserves full-read behavior; `include: []` returns core page/version/catalog/hierarchy metadata and
lightweight type facts only. Content-only and provenance-only requests omit the other section.
Authorization and PDF feature checks still happen before selective work, while absent content skips
artifact storage, image inspection, response-byte checks, and image blocks, and absent provenance
skips its read model. Metadata-only success therefore makes no claim that retained bytes are currently
available.

`list_taxonomy` exposes the filter vocabulary needed to call `search`: global tag UIDs plus
workspace-qualified category UIDs. It includes categories from the principal's reachable
workspaces and tags/categories attached to individually granted pages. It uses the same token
workspace ceiling and exact authorization post-filter as page search, so categories from an
unreachable workspace and private-only tag labels are not a metadata side channel.
Every user-authored taxonomy label and slug is returned inside an `artifactflow.untrusted_data`
envelope. `create` can attach tag names and either select or create a category atomically with
the page; standalone taxonomy creation uses the same category/tag handlers, `mcp:create` scope,
write throttling, live Editor authority, and token workspace ceiling.

Content-version write tools accept optional producer provenance. An AI assertion retains every
safe known field independently: a reported provider is stored beside its normalized search key,
while an exact provider-defined model ID, a model family/label, a provider version, a generation
timestamp, typed references, and bounded identity extensions remain independently optional. At
least one meaningful fact is required, but missing exactness never discards another valid field.
MCP-supplied assertions are always
`self_reported`; callers cannot select stronger evidence. The client name/version reported during
MCP initialization is stored as unverified submitter metadata and never interpreted as a
provider/model or attested implementation identity. Every retained provenance string is checked
for the same obvious credential patterns that block artifact writes. Extension keys also reject
prompt/reasoning, credential, authorization, URL, and content-payload classes; values are bounded
and URLs must use typed external references.
Full `read` returns ingest facts and defines each visible assertion once in a deterministic
`producers` catalog. Ordered `page_origin_producer_uids`, `direct_version_producer_uids`, and
`effective_content_origin.producer_uids` identify the lineage roles without repeating producer
blocks. Every referenced UID resolves in that same response; contradictory definitions for one UID
are treated as an internal invariant failure. Restore writes resolve the root content-origin UID so
reads need at most one origin lookup regardless of lineage depth. All present declared strings use
the existing untrusted-data envelope, while absent optional description, change-summary, identity,
client, and reference values are omitted instead of becoming empty-string envelopes. Successful MCP
content writes retain the self-contained `stored_provenance` receipt with persisted direct assertions
and computed `none`, `partial`, or `complete` state.

## Artifact Security Boundary

Untrusted artifact HTML is contained by isolation, not sanitization.

1. The app origin authorizes the viewer and issues a short-lived signed preview URL. Current and historical previews are distinct signed purposes; adding `purpose=history` to a current URL cannot grant historical access. The application document is never reloaded. Script-capable HTML previews emit a fixed ready signal; if a later self-reload reaches an expired URL and returns without that signal, the authenticated parent renews and restores only that iframe's `src`, preserving any unsaved editor state. Scriptless image previews load eagerly once and do not renew on a timer; expiry closes future loads without retransmitting bytes that are already rendered.
2. The artifact-host origin verifies the HMAC signature, expiry, runtime role, and target page/version.
3. The artifact-host reads immutable content from private storage only after size and signature checks.
4. The response uses strict headers and no app session middleware.
5. The app embeds the preview in an iframe sandboxed with `allow-scripts` and without `allow-same-origin`.

Normalized image previews reuse the signed artifact-origin route but not the executable HTML
policy. The artifact host generates a fixed scriptless viewer, embeds only the re-encoded raster,
and sends an empty CSP `sandbox` plus `script-src 'none'`; the app iframe uses `sandbox=""`.

XLSX preview is another application-owned document, not workbook HTML. The
artifact host revalidates the hash-bound canonical manifest and emits fixed
local Tabulator assets under an opaque sandbox that permits scripts and
explicit hyperlink popups but no same-origin authority, forms, frames, or
connections. DOCX preview uses only the independently validated PDF derivative
and inherits the PDF-only browser-native viewer exception; the exact DOCX is
available only as an authenticated attachment. Details are fixed in the public
[XLSX](architecture/xlsx-artifacts.md) and
[DOCX](architecture/docx-artifacts.md) decisions.

Native image parsing is a separate trust boundary. The app performs only bounded PNG/JPEG envelope
inspection, then sends the original bytes in a timestamped, nonce-bound HMAC request to the private
`image-parser` service. That minimal image has GD/EXIF but no application source, database access,
artifact storage, public listener, or outbound route. It runs non-root with a read-only filesystem
and explicit CPU, memory, PID, capability, and temporary-filesystem restrictions. The shipped
512 MiB service uses one normalization process so maximum-pixel native image operations cannot
multiply across prefork workers; its startup script rejects worker counts above one. The app uses
the shared rate-limit cache for a non-blocking installation-wide admission slot plus independent
decoded-pixel and input-work budgets per principal and installation. Input work charges raw
compressed bytes, metadata bytes, and chunk/marker count. A busy slot fails immediately instead of queueing an app worker,
and extra independently memory-bounded parser replicas provide failover without increasing admitted
concurrency. Every dispatched attempt consumes both reserved budgets; only failures proven to
precede dispatch are refunded. An uncertain transport or response-stream failure retains the shared
slot until its lease expires. The app disables response decompression, rejects encoded responses,
and incrementally reads no more than the signed output-byte ceiling plus one sentinel byte. It
accepts only a matching HMAC-signed normalized response and independently rechecks its format,
dimensions, pixel/byte limits, and header envelope before making it an immutable version.
New-upload limits
bound parser input to 16 Mi pixels; immutable versions are read against the installation artifact
limit and the historical 40 Mi-pixel raster ceiling so lowering a write cap cannot invalidate
retained history.

Only app-role replicas may hold the parser shared secret; production boot and doctor checks reject
it on artifact-host, worker, and scheduler roles. The parser image omits optional WebP support, its
request envelope admits only PNG/JPEG, and `/health` proves a one-pixel PNG decode/re-encode rather
than listener liveness alone.

For PNG, the app and parser cap the stream at 1,024 chunks and 1 MiB of ancillary data. The parser
validates chunk structure and CRCs, strips `tEXt`, `zTXt`, `iTXt`,
and `iCCP` metadata from the bytes passed to GD, then performs an output-limited zlib pass over
IDAT. The decompressed pixel stream must contain exactly the scanlines implied by IHDR (including
Adam7 passes), with no trailing expansion. Native decode therefore cannot inflate metadata that
is unrelated to the dimensions charged by admission.

Unsaved draft preview follows the same execution boundary without storing a version. The
authenticated app origin authorizes page creation in the selected workspace and signs a capability
over the artifact origin, purpose, workspace, expiry, nonce, exact UTF-8 byte length, and SHA-256.
Only that capability and the exact matching HTML are accepted by the session-free artifact-host
receiver. The capability lasts at most 60 seconds and may be replayed only for the same exact draft
within that window; it cannot be moved to another artifact origin or different content.

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
fenced-frame-src 'none';
child-src 'none';
worker-src 'none';
webrtc 'block';
frame-ancestors <configured app origin>
```

Nested browsing contexts are not part of the artifact feature: actual static `iframe`, `frame`,
`fencedframe`, and `portal` tokens are converted to inert templates before the hostile document is
parsed without rewriting matching bytes inside genuine HTML script-data or text-control contexts.
SVG/MathML elements whose children browsers parse as markup stay visible to the scanner, including
SVG/MathML `style`, SVG/MathML `plaintext`, and SVG/MathML `script`; scripting-enabled HTML
`noscript` remains raw text. Foreign `<![CDATA[` sections at HTML integration points follow both
Firefox's spec-conforming `]]>` boundary and the maintained Chromium/WebKit bogus-comment
interpretation so neither parser can hide a live child context from the other branch; ordinary
foreign CDATA remains verbatim. Raw-text tokenizer transitions are suppressed
for start tags that active HTML `select` or `frameset` tree-builder modes ignore, while recognized
`script` and `noframes` carriers retain their tokenizer states so fake container closes inside their
text cannot desynchronize the scanner. Static
`shadowrootmode` attributes are renamed before parse so declarative Shadow DOM cannot hide a context
in an open or closed tree from the residual light-DOM sweep. The early guard blocks dynamic creation
and common markup-parsing sinks. This layers over CSP because `frame-src 'none'` does not stop inline
`srcdoc` realms in the maintained engines. Chromium and WebKit also ignore `webrtc 'block'`; the
directive is best-effort hardening, not a credited barrier. Static response rewriting must therefore
happen before parse, while MutationObserver cleanup remains a timing-dependent residual. The guard
remains defense in depth rather than an authorization or isolation boundary. The reviewed parser
differentials did not cross the opaque sandbox or artifact/app origin split, and the corrected stack
emits no network traffic in the maintained browser corpus.

Do not add `allow-same-origin`, top navigation, forms, external scripts, outbound connections, public unauthenticated artifact access, or app-session middleware to the artifact surface without a written architecture decision and security tests.

## Markdown And Mermaid

Markdown and Mermaid source are untrusted user content.

- Markdown renders in the app origin only after sanitization.
- A linear delimiter budget rejects pathological link-heavy source at write and preview boundaries; the renderer independently fails closed to fixed application-owned text if that budget is exceeded.
- Raw HTML and JavaScript inside Markdown must not execute.
- Mermaid renders with strict security settings and without external network calls.
- Wiki-style links resolve only to authorized same-workspace pages.
- Markdown preview and saved rendering share the same security assumptions.

## Search

Page discovery uses PostgreSQL full-text search with explicit filters and authorization.

Search inputs are untrusted. The search layer uses bounded parsing and PostgreSQL query APIs rather than interpolating raw query text. Search combines metadata, workspace/owner context, tags, current-version extracted text, and a deterministic, deduplicated maximum of 256 non-sensitive provider/model labels. Source text is included at lower weight so generated artifacts remain discoverable by technical terms without turning search into a content disclosure channel or exceeding PostgreSQL's `tsvector` size. Structured provenance filters remain exhaustive: they match normalized provider plus model ID/label across page origin, current version, or any retained ingest record; the outer page-visibility query remains the authorization boundary.

External provenance URLs and opaque conversation/session references never enter the search vector.

Tags are a single installation-wide vocabulary keyed by slug. Categories remain workspace-scoped because a workspace acts as the top-level project boundary; cross-workspace category filters therefore display `Category — Workspace`, while a single-workspace filter uses the category name alone. Members may discover all categories in their reachable workspaces, while page-only grants reveal only the category and global tags attached to pages the actor may actually view; private-only tag labels and unrelated foreign-workspace categories remain hidden. A workspace move preserves global tag relations and translates the page category by slug, reusing the target category or creating it transactionally when it does not exist.

**Search-vector maintenance (design note).** The `pages.search_vector` `tsvector` is maintained by the application (`PageSearchVectorUpdater`), not by a database trigger or generated column, and it stores a *denormalized* copy of some related labels — the page's owner name, workspace name, and category name — alongside the page's own fields. Every code path that changes an indexed input refreshes the vector explicitly (create, content append, metadata update, workspace move, status change, and — for the denormalized labels — workspace-settings update and member removal). Workspace rename is an existing denormalized-label path and refreshes every affected page after the rename transaction commits. Users and categories currently have no rename path and are effectively create-only. The consequence to keep in mind: **if a future feature lets a user or category be renamed, that path must also refresh every affected page's `search_vector`**, or search results will silently reflect the stale label until the page is next re-indexed. A database trigger or a `GENERATED` column would remove this obligation entirely and is the natural upgrade if the denormalized set grows or gains more rename paths. Uses the `simple` text-search configuration (no stemming/stopwords) for predictable, language-agnostic matching.

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
- producer assertion creation (`page.version.producer_asserted`) without user-controlled provider/model strings, external URLs, or opaque references;
- installation limit changes.

## Installation Limits

System Admins can adjust runtime limits for content size, artifact read size, workspace storage, page storage, page versions, and tag counts. The Administration UI is guarded by recent live two-factor confirmation and server-side authorization; a password or trusted-device cookie cannot satisfy that prompt. Installation-wide counts and bytes are aggregate; named workspace/page usage rows appear only for workspaces the System Admin has joined through normal membership. Limit writes are transactional, audited, event-recorded, and bounded by hard ceilings so the UI cannot silently disable memory/storage protections.

## Locking And Realtime

Content updates use optimistic concurrency control at the write boundary: each update includes the expected current version and returns a conflict response when another version won the race.

The nested-workspace beta design requires hierarchy mutations to take a transaction-scoped PostgreSQL advisory
lock so cycle, depth, and reparent checks cannot race another hierarchy write.
They then preserve the existing page-to-workspace row-lock order while
invalidating descendant access revisions. Membership and invitation mutations,
page placement, and workspace-subject grant writes that depend on stable
ancestry take the same hierarchy lock before authorization and row locking.
Ordinary page reads and content updates do not take it; see
[`architecture/nested-workspaces.md`](architecture/nested-workspaces.md).

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
- enabled PDF, XLSX, or DOCX processing without a pure private origin/socket,
  bounded timeouts, and a strong secret dedicated to that processor;
- office/PDF processor credentials on non-app roles, enabled office formats on
  worker/scheduler roles, or DOCX enabled without PDF processing/presentation;
- non-HTTPS production origins;
- debug mode;
- unsafe session settings;
- a session domain that covers the artifact host;
- PostgreSQL transport modes that can downgrade below required TLS;
- public artifact storage;
- missing System Admin bootstrap path;
- artifact frame ancestors that do not match the configured app origin;
- a non-deliverable mail transport (`log`/`array`), which would silently drop invitation and password-reset mail;
- a node-local, transient, undefined, or unknown rate-limiter cache driver; ordinary application caching may use any shared-capable driver, but production rate limiting currently supports only the dedicated database limiter stores so app and artifact-host credentials remain isolated;
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
git diff --check
```

`make quality-full` is the authoritative aggregate for the `make` targets above: it runs all of them except `make compose-config` and also runs `make verify-reverb-origin`. Its production build and scan stages include the XLSX processor contract/health proof, the DOCX package/LibreOffice proof, the DOCX-to-PDFBox native-text chain, and Trivy scanning for both Office images. The Rector dry run, Semgrep rule-fixture test, and `git diff --check` are separate required checks. Run `make compose-config` when Docker or environment files change, run the separate checks before committing, and keep `make ai-hooks-test` green whenever the AI guardrail files change.

## Later Surfaces

The following are deliberately outside the current launch boundary unless the architecture is updated first:

- collaborative editing beyond optimistic concurrency plus advisory Reverb presence;
- broad public publishing, search, and navigation beyond the bounded external
  capability design in `docs/architecture/external-sharing.md`;
- SSO or enterprise RBAC expansion;
- S3/object storage migration;
- Redis/Meilisearch;
- multi-file or ZIP artifact uploads;
- approval workflow systems.
