# ArtifactFlow Roadmap

ArtifactFlow's first open-source alpha is feature-frozen. Before that release, work should stay focused on security, correctness, release readiness, documentation, and small usability fixes to existing behavior. New authorization or content-boundary models belong after the alpha has real team feedback.

This roadmap records direction, not a release promise. Every item still requires tests-first implementation and the security gates in `AGENTS.md`.

## Alpha boundary

The alpha keeps the current model:

- personal and shared workspaces are flat, independent permission boundaries;
- pages may already have parent/child relationships inside one workspace;
- pages inherit workspace access by default and retain their existing page-level overrides;
- registered human accounts are installation-wide discoverable coworkers whose identifiers do not confer authority; Reader page grants may target any registered human, while Editor/Admin grants require page-workspace membership;
- categories, storage accounting, search filters, memberships, invitations, and MCP scopes remain workspace-specific.

No nested-workspace schema or effective-membership behavior should be introduced before the first open-source alpha.

## Alpha: visible page hierarchy

The page model and current Library expose parent/child relationships without changing page authorization.

Current experience:

- the Library and Overview show an authorization-filtered content tree for the selected workspace;
- page rows show visible parent context, and page detail exposes visible parent and child structure;
- MCP search/read responses include visibility-filtered parent, ancestor-path, depth, and direct-child-count metadata;
- inaccessible ancestor and child titles, UIDs, counts, and placeholders remain undisclosed;
- parent selection and workspace moves preserve the same-workspace hierarchy boundary.

This remains presentation and navigation over the existing `parent_page_uid` model, not a second permission system. Further post-alpha hierarchy work may improve expansion and navigation ergonomics, but must preserve the same authorization rules.

## Post-alpha: expiring external share links

External sharing is deliberately outside the Alpha. A later sharing surface should let an authorized page access manager create a high-entropy capability link for someone who does not have an ArtifactFlow account, with two explicit modes:

- **time-bounded link:** usable until a required expiry chosen within an operator-configured maximum;
- **one-time link:** atomically consumed by the first successful redemption, with an optional short expiry as a backstop.

This must not reuse internal user grants or make external recipients installation accounts implicitly. Before implementation, write an architecture decision and update the threat model to settle the share origin, Markdown and HTML rendering surfaces, current-versus-pinned version semantics, download behavior, and whether recipient verification is required.

Required security properties:

1. Store only a hash of the random share secret; never log the raw token, include it in audit metadata, or expose it after the creation response.
2. Re-check page state and link state on every redemption. Revocation, expiry, page archival/deletion, and relevant access revisions must fail closed with a uniform not-found response.
3. Consume one-time links atomically under a lock so concurrent requests cannot redeem the same capability twice.
4. Reveal only the explicitly shared page/version. Never expose workspace membership, coworker directory entries, sibling titles, taxonomy, search, history, MCP, or authenticated application navigation.
5. Keep executable HTML on the isolated artifact origin under the existing opaque sandbox and no-network CSP. No external share may place untrusted content or a bearer token into the authenticated app DOM or cookies.
6. Rate-limit creation and redemption, record non-secret create/revoke/redeem audit events, and give access managers a clear inventory with expiry, status, and last-redemption metadata.
7. Add browser-level proof for token leakage, one-time concurrency, revocation/expiry, uniform failures, and the HTML sandbox boundary before enabling the feature.

## Beta candidate: searchable PDF pages

PDF upload is deferred until beta. A PDF should participate in the same workspace catalog, permissions, lifecycle, versioning, tags, and search experience as Markdown pages and HTML artifacts, while remaining a distinct non-executable content type.

Planned experience:

- upload a PDF into a personal or shared workspace and attach the usual title, description, category, and tags;
- extract embedded text and document metadata so permission-aware full-text search can find content inside the PDF;
- run OCR for image-only or scanned pages, with a visible extraction status when processing is pending, partial, or unsuccessful;
- show search snippets and page references from extracted text without exposing content from an inaccessible PDF;
- provide a safe in-app reading experience and an authorized original-file download;
- create a new page version when the PDF is replaced, preserving the original file and extracted-text history for each version.

PDFs must not be converted into executable HTML or rendered directly in the authenticated app origin. The original remains private binary content; extracted text is untrusted plain text and must always be escaped when displayed.

### Security and processing plan

1. Define upload size, page-count, decompression, parser time, memory, and OCR limits before accepting PDFs.
2. Validate the file signature and structure rather than trusting the extension or browser-supplied MIME type. Malware scanning remains advisory.
3. Extract text and metadata in an isolated, no-network worker with hard resource limits. Record parser and OCR versions so documents can be safely reprocessed after security updates.
4. Store originals in private storage and serve them only through authorized, short-lived access. The reader must use either non-executable rendered pages or an equally isolated viewer boundary; embedded JavaScript, actions, links, attachments, forms, and external fetches must never inherit the app origin.
5. Index only normalized extracted text and non-secret metadata. Extraction failures must not make the original public or silently mark it as fully searchable.
6. Apply workspace/page authorization consistently to upload, processing status, reading, download, search snippets, MCP access, version history, archival, and deletion.

### Required proof before beta

- text PDFs and scanned PDFs become searchable after extraction or OCR;
- replacing a PDF creates a version and removes stale text from current search results;
- malicious, malformed, encrypted, oversized, and parser-exhaustion inputs fail safely;
- restricted PDF titles, snippets, extracted text, originals, and processing status never leak through search, Library, direct URLs, MCP, logs, or background jobs;
- deleting or hard-deleting a PDF removes every original, rendered derivative, OCR artifact, and search projection required by the existing retention rules;
- browser tests prove the reader cannot execute PDF-provided active content or access app-origin credentials.

## Beta candidate: nested shared workspaces

Nested workspaces are deferred until after alpha feedback. [Confluence Cloud currently keeps spaces flat](https://support.atlassian.com/confluence-cloud/docs/navigate-spaces/) and nests content inside each space; ArtifactFlow would therefore be making a deliberate product choice rather than copying Confluence parity.

### Agreed product rules

- The maximum hierarchy is **three levels total**: root workspace, child workspace, grandchild workspace.
- Only shared workspaces may participate. Personal workspaces remain standalone.
- Every level remains a normal page-bearing workspace.
- Pages, categories, storage counters, and Library filters stay separate per workspace. Selecting a parent shows that workspace's pages, not a merged descendant library.
- Parent memberships flow downward with the same role.
- A child may add direct members. Direct child membership may add access or elevate an inherited role, but may not reduce inherited authority.
- Effective authority is the strongest applicable direct or inherited role. The first version has no deny rules.
- Child-only members receive no parent or sibling access.
- Inherited members are shown separately and managed at the workspace where their membership originates.
- Role-affecting workspace settings may be stricter in a child but may not loosen an ancestor's restriction.
- Reparenting requires Admin authority over the child, its old parent, and its new parent.
- A parent with children cannot be deleted through a cascading content deletion.
- MCP tokens with selected workspace scopes remain exact: choosing a parent does not silently add current or future descendants. An explicit all-workspaces token continues to follow the principal's live reach.

### Security and architecture plan

1. Write an architecture decision and threat-model update before the migration. Define depth, cycle prevention, reparenting, deletion, settings inheritance, and token-scope semantics as server-side invariants.
2. Add a parent relationship plus an indexed ancestry representation suitable for authorization and search queries. Do not copy inherited memberships into child membership rows.
3. Centralize effective membership resolution so web policies, `WorkspaceAccess`, `PageAccess`, search visibility, navigation, page grants, realtime channel authorization, and MCP all consume the same result.
4. Make membership removal, role downgrade, hierarchy creation, and reparenting transactionally invalidate every affected descendant page preview revision and revoke lost realtime presence.
5. Audit and event-record hierarchy changes with non-secret reach summaries. A newly attached child must clearly disclose that ancestor members gain access.
6. Add tree navigation and member-origin labels only after the authorization boundary is proven.

### Required proof before beta

- cycle and three-level depth constraints hold under concurrent writes;
- inherited roles cannot be reduced or bypassed by direct child records;
- parent removal and downgrade revoke descendant access immediately;
- restricted page titles and UIDs never leak through trees, search, taxonomy, invitations, realtime, or MCP;
- page grants to a child include its effective inherited members, while child-only members do not gain grants addressed to a parent;
- selected MCP scopes never expand because a workspace is reparented or a new descendant is created;
- storage, categories, page moves, and exact-workspace Library filters remain isolated at each level;
- browser tests cover the real hierarchy UI and saved artifact preview revocation path.

Only after those invariants are accepted should nested workspaces be scheduled for beta.
