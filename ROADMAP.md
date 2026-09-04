# ArtifactFlow Roadmap

ArtifactFlow is a self-hosted, versioned artifact vault for deliberate outputs created with AI. It preserves the artifact, its authoritative source or original, retained versions, searchable content, ownership, permissions, previews, and audit history. It is not agent memory, a chat archive, or an AI generation platform.

The open-source alpha has shipped. Its bounded formats are Markdown, self-contained HTML, normalized PNG/JPEG screenshots or images, and default-off native-text PDF, Excel `.xlsx`, and Word `.docx` artifacts. Three-level nested shared workspaces shipped in v0.0.9. Alpha work should stay focused on security, correctness, release readiness, documentation, and deliberately scoped feature slices. PDF OCR is a separate later milestone.

This roadmap records direction, not a release promise. Every item still requires tests-first implementation and the security gates in `AGENTS.md`.

Public summary: https://artifactflow.app/roadmap/

Artifact identity and version semantics: [docs/ARTIFACT-LIFECYCLE.md](docs/ARTIFACT-LIFECYCLE.md)

## Alpha boundary

The alpha keeps the current model:

- personal workspaces remain standalone;
- shared workspaces may form a tree with at most three levels under the accepted inheritance, exclusion, reparenting, and non-disclosure rules below;
- pages may have parent/child relationships only inside one exact workspace;
- pages inherit their exact workspace's effective access by default and retain their existing page-level overrides;
- registered human accounts are installation-wide discoverable coworkers whose identifiers do not confer authority; Reader page grants may target any registered human, while Editor/Admin grants require effective page-workspace membership;
- pages, categories, storage accounting, Library filters, and selected MCP workspace scopes remain exact-workspace concerns even when workspace membership is inherited.

## Alpha: AI artifact provenance

Current provenance records immutable ArtifactFlow-observed ingest facts for every version and
optional AI, human, or software producer assertions. MCP create/update accepts exact provider/model
claims without making them mandatory or pretending they are verified. Authorized web and MCP reads
show the distinction, restore lineage resolves byte-equivalent content to its earlier producer, and
search filters page origin, current version, or any historical ingest by provider/model. Provenance
survives version-content pruning and is deleted with the owning page.

The accepted internal product requirements and architecture decision keep later work explicit:
assertion amendment UI, external-reference redaction/retention controls, browser-side provenance
entry, and genuine provider attestations are not part of this first slice.

## Alpha: visible page hierarchy

The page model and current Library expose parent/child relationships without changing page authorization.

Current experience:

- the Library and Overview show an authorization-filtered content tree for the selected workspace;
- page rows show visible parent context, and page detail exposes visible parent and child structure;
- MCP search/read responses include visibility-filtered parent, ancestor-path, depth, and direct-child-count metadata;
- inaccessible ancestor and child titles, UIDs, counts, and placeholders remain undisclosed;
- parent selection and workspace moves preserve the same-workspace hierarchy boundary.

This remains presentation and navigation over the existing `parent_page_uid` model, not a second permission system. Further post-alpha hierarchy work may improve expansion and navigation ergonomics, but must preserve the same authorization rules.

## Alpha: normalized screenshots and images

Current image support deliberately stays small:

- PNG and JPEG only; SVG, animated formats, and arbitrary image containers remain unsupported;
- detected MIME, filename extension, compressed bytes, dimensions, and total pixels are bounded;
- uploads are decoded and re-encoded, and only the normalized derivative is retained;
- previews are fixed, scriptless documents served on the isolated artifact origin;
- no OCR is performed, so full-text discovery comes from editable catalog metadata;
- MCP may read the normalized image and update only its description under ordinary token, authorization, scan, concurrency, rate-limit, and audit rules.

Raster, PDF, XLSX, and DOCX processing each run behind separately resource-isolated, internal-only services with format-specific parser, renderer, and output limits. No native office parser runs in the Laravel runtime or a browser.

## Alpha: expiring and one-time external share links

The product direction and security architecture for a deliberately narrow external sharing surface
are documented in [`docs/architecture/external-sharing.md`](docs/architecture/external-sharing.md).
The implementation and required cross-browser security proof shipped in v0.0.7. The surface lets an
authorized page access manager create a high-entropy capability link for someone who does not have
an ArtifactFlow account, with two explicit modes:

- **time-bounded link:** usable until a required expiry chosen within an operator-configured maximum;
- **one-time link:** no expiry, atomically consumed by the first successful redemption.

This does not reuse internal user grants or make external recipients installation accounts
implicitly. The accepted decision settles fragment-based secret exchange, distinct anonymous
viewing sessions, latest-version behavior, no downloads or recipient verification, and the existing
Markdown, HTML, and image rendering boundaries. MCP creation is separately
opt-in through `mcp:share` and is narrower than browser access management: the
principal may create a share only for an in-scope page it owns and can still
edit while the workspace permits Editors and page owners to share, and the
bearer URL is returned once.

Implemented security properties:

1. Store only a hash of the random share secret; never log the raw token, include it in audit metadata, or expose it after the creation response.
2. Re-check page state and link state on every redemption. Revocation, expiry, page archival/deletion, and relevant access revisions must fail closed with a uniform not-found response.
3. Consume one-time links atomically under a lock so concurrent requests cannot redeem the same capability twice.
4. Reveal only the explicitly shared page/version. Never expose workspace membership, coworker directory entries, sibling titles, taxonomy, search, history, MCP, or authenticated application navigation.
5. Keep executable HTML on the isolated artifact origin under the existing opaque sandbox and network-restrictive CSP. Ordinary fetch and connection APIs stay blocked, while the documented self-navigation and browser-dependent WebRTC residuals still apply. No external share may place untrusted content or a bearer token into the authenticated app DOM or cookies.
6. Rate-limit creation and redemption, record non-secret create/revoke/redeem audit events, and give access managers a clear inventory with expiry, status, and last-redemption metadata.
7. Prove the MCP path requires `mcp:share`, token-workspace reach, page ownership, live edit authority, and the workspace's live editor-sharing permission for both human and service-account principals, without leaking the returned URL into persistence, events, or audit metadata.
8. Add browser-level proof for token leakage, one-time concurrency, revocation/expiry, uniform failures, and the HTML sandbox boundary before enabling the feature.

## Opt-in searchable PDF artifacts

Tracking: [GitHub issue #32](https://github.com/Gadsotek/artifactflow/issues/32)

Public security contract: [PDF architecture decision](docs/architecture/pdf-artifacts.md).
Supporting product, delivery, and point-in-time spike records remain private
working material rather than published roadmap dependencies.

Implementation status (2026-08-25): the native-text application boundary is
implemented end to end and released as a default-off production opt-in,
including web and MCP ingestion, extraction/search, native preview/download,
version restore, derived-fact reprocessing, and lifecycle/operations coverage.
The dedicated private-network processor image provides inherited outbound
syscall denial for platforms without shared Unix sockets. Each deployment must
still complete the released-Safari/iOS pass, verify its running containment,
and close the final evidence-first review before enabling the flag.

A PDF should participate in the same workspace catalog, permissions, lifecycle, versioning, tags, and search experience as existing pages while remaining a distinct document type. The first delivery is deliberately native-text PDF only: OCR follows as a separate milestone after the parser, storage, artifact-origin, authorization, and deletion boundaries are proven.

Planned experience:

- upload a PDF into a personal or shared workspace and attach the usual title, description, category, and tags;
- extract bounded embedded text so permission-aware full-text search can find content inside the PDF;
- show image-only PDFs honestly as having no embedded text, while keeping them discoverable through catalog metadata until a later OCR slice;
- show search snippets and page references from extracted text without exposing content from an inaccessible PDF;
- display the authorized original with the browser's native PDF viewer on the existing cookieless artifact origin and provide a normal forced-attachment download;
- create a new page version when the PDF is replaced, preserving each original and its version-scoped processing facts while re-extracting retained historical versions when restored or updating derived facts in place during explicit reprocessing.

PDFs must not be converted into executable HTML or displayed in the authenticated app origin. The original remains private binary content; extracted text is untrusted embedded plain text and must always be escaped when displayed. It can include clipped, off-page, transparent, hidden-layer, or stale strings that are absent from the browser-visible document, so it is not proof of visual redaction. Preview is download-equivalent: an authorized browser receives the original and may expose save, print, copy, or link controls.

### Security and processing plan

1. Define browser and MCP transport envelopes plus byte, page, parser-time, memory, temporary-storage, and text-output limits before accepting PDFs.
2. Validate the file signature and structure rather than trusting the extension or browser-supplied MIME type. Malware scanning remains advisory.
3. Validate and extract text in a dedicated isolated processor whose effective OS/container/network boundary denies processor-initiated public, metadata, loopback, and private-peer connections and enforces one native child plus hard resource limits. Record the parser profile so documents can be reprocessed after security updates.
4. Store originals in private storage and serve an exact authorized version through a short-lived, revision-bound signed URL on the cookieless artifact origin with `application/pdf`, `nosniff`, the existing CSP, and no app cookies. The first release uses one processor replica and no render queue.
5. Index only normalized extracted text and non-secret metadata. Extraction failures must not make the original public or silently mark it as fully searchable.
6. Apply workspace/page authorization consistently to upload, extraction status, native viewing, download, search snippets, MCP access, version history, archival, moves, and deletion. PDF adds no artifact-host database grant beyond the established read-only page/version presentation tables.

### Required proof before deployment enablement

- native-text PDFs become searchable, while image-only PDFs are clearly marked as not text-searchable without OCR;
- replacing a PDF creates a version and removes stale text from current search results;
- malicious, malformed, encrypted, active-content, oversized, parser-exhaustion, decompression, and output-amplification inputs fail safely;
- restricted PDF titles, snippets, extracted text, originals, and processing status never leak through search, Library, direct URLs, MCP, logs, or background jobs;
- pruning or hard-deleting a PDF removes every original, PDF fact, current extracted-text projection, and search projection required by the existing retention rules;
- workspace moves atomically transfer original bytes, and tests prove restricted artifact-host grants, exact transport boundaries, signed URL expiry/revision, and processor concurrency limits;
- browser tests prove the native viewer stays on the artifact origin, sends no app cookies, cannot access app-origin credentials, and uses the narrowly documented PDF-only sandbox exception or falls back to deliberate download without changing the HTML/image cage.

## Default-off searchable XLSX workbook artifacts

Public security contract: [XLSX architecture decision](docs/architecture/xlsx-artifacts.md).

Implementation status (2026-08-30): bounded `.xlsx` support is implemented as a
default-off production opt-in. The exact original is retained privately; a
dedicated SheetJS CE processor emits only the canonical `xlsx-view-manifest-v1`
typed projection; search indexes normalized visible cell content; and an
application-owned Tabulator viewer renders that projection on the opaque,
cookieless artifact origin. Formulas are never evaluated. Hidden sheets, rows,
columns, objects, comments, charts, and advanced formatting are deliberately
omitted. Web and MCP creation/replacement, current and historical originals,
version restoration, in-place reprocessing, lifecycle accounting, integrity
checks, and the narrow external-share presentation use the same authorization
and feature-flag boundary.

XLSX processing is not an antivirus verdict. Downloading the retained original
is an explicit transfer of untrusted workbook bytes. Production enablement must
pin the reviewed processor image, prove one-worker resource and network
containment, configure its dedicated HMAC secret, and complete the browser and
evidence-first review gates in the public decision.

## Default-off searchable Word document artifacts

Tracking: [GitHub issue #33](https://github.com/Gadsotek/artifactflow/issues/33)

Implementation status (2026-08-30): bounded modern `.docx` support is
implemented as a default-off production opt-in. Legacy binary `.doc`,
macro-enabled `.docm`, encrypted packages, and documents containing unsupported
active or embedded content remain outside the accepted profile. A Word document
participates in the same workspace catalog, permissions, lifecycle, versioning,
tags, search, preview, exact-original download, external-sharing, and MCP
experience as other artifacts.

Implemented experience:

- upload a DOCX artifact into a personal or shared workspace with the usual title, description, category, tags, and owner;
- extract paragraphs, headings, lists, tables, links, document properties, and other useful text into permission-aware search;
- provide a safe, non-executable preview without injecting converted document HTML into the authenticated application DOM;
- download the authorized original while keeping it in private storage;
- replace the document by appending an immutable artifact version and retaining the original plus extracted-text history for each version;
- keep optional generator-source preservation out of this slice rather than pretending every uploaded document has one.

DOCX is a ZIP/XML container, not trusted text. Processing must reject malformed packages, external relationships, macros, embedded active content, oversized decompression, excessive part counts, parser exhaustion, and unsupported encryption before any derived preview becomes available.

### Security and processing boundary

1. Compressed bytes, expanded bytes, package parts, relationships, media, pages, output bytes, parser time, memory, processes, and temporary storage are bounded independently.
2. The dedicated DOCX processor validates ZIP/OPC structure, exact namespaces, content types, relationships, targets, and active-content exclusions before conversion; extension and client MIME are never trusted.
3. LibreOffice runs as one isolated conversion process under a networkless container and hard resource limits. The resulting PDF is independently submitted to the PDFBox processor's DOCX-preview profile before it can be stored, indexed, or delivered.
4. The exact original stays private. Only the validated passive PDF derivative is previewed on the artifact origin; converted HTML never enters the authenticated application DOM.
5. Extracted text and metadata remain untrusted and escaped. Parser or preview failure fails the whole write without exposing the original or a partial derivative.
6. Workspace and page authorization applies consistently to upload, processing status, preview, original download, search snippets, MCP access, version history, archival, deletion, and every derivative.

### Required proof before production enablement

- ordinary DOCX files become searchable and previewable as text-bearing passive PDFs;
- replacing a Word document appends a version and removes stale extracted text from current search results;
- malformed ZIPs, zip bombs, external relationships, macros, embedded objects, encrypted files, and parser-exhaustion inputs fail safely;
- restricted titles, snippets, extracted text, originals, preview derivatives, and processing status never leak through search, Library, direct URLs, MCP, logs, or jobs;
- deletion and retention rules remove the original, extracted text, and preview derivative for the affected version;
- browser tests prove document previews cannot execute document-provided active content or access app-origin credentials.

The complete accepted contract and operator evidence requirements are in
[`docs/architecture/docx-artifacts.md`](docs/architecture/docx-artifacts.md).

## Released in v0.0.9: nested shared workspaces

Nested shared workspaces are implemented, verified, and included in v0.0.9. The governing security and data-model decision is documented in [`docs/architecture/nested-workspaces.md`](docs/architecture/nested-workspaces.md). [Confluence Cloud currently keeps spaces flat](https://support.atlassian.com/confluence-cloud/docs/navigate-spaces/) and nests content inside each space; ArtifactFlow is therefore making a deliberate product choice rather than copying Confluence parity.

### Agreed product rules

- The maximum hierarchy is **three levels total**: root workspace, child workspace, grandchild workspace.
- Only shared workspaces may participate. Personal workspaces remain standalone.
- Every level remains a normal page-bearing workspace.
- Pages, categories, storage counters, and Library filters stay separate per workspace. Selecting a parent shows that workspace's pages, not a merged descendant library.
- Parent memberships flow downward with the same role by default; workspace creation has a default-on opt-out control.
- A child Admin may remove one inherited user at that boundary. The exclusion also blocks ancestor-derived access below that child, but preserves independent direct memberships at the child or below.
- A child may add direct members. At an exclusion boundary, the direct local role is authoritative without reviving a stronger ancestor role.
- Effective authority is the strongest applicable direct or inherited role whose path is not blocked by a workspace inheritance boundary or user exclusion.
- Child-only members receive no parent or sibling access.
- Inherited members are labelled with their origin and can be removed locally by a child Admin.
- Role-affecting workspace settings may be stricter in a child but may not loosen an ancestor's restriction.
- Reparenting requires Admin authority over the child, its old parent, and its new parent.
- A parent with children cannot be deleted through a cascading content deletion.
- MCP tokens with selected workspace scopes remain exact: choosing a parent does not silently add current or future descendants. An explicit all-workspaces token continues to follow the principal's live reach.

### Implemented security and architecture

1. Write an architecture decision and threat-model update before the migration. Define depth, cycle prevention, reparenting, deletion, settings inheritance, and token-scope semantics as server-side invariants.
2. Add a parent relationship plus an indexed ancestry representation suitable for authorization and search queries. Do not copy inherited memberships into child membership rows.
3. Centralize effective membership resolution so web policies, `WorkspaceAccess`, `PageAccess`, search visibility, navigation, page grants, realtime channel authorization, and MCP all consume the same result.
4. Make membership removal, inherited exclusion, role downgrade, hierarchy creation, and reparenting transactionally invalidate every affected descendant page preview revision and revoke lost realtime presence.
5. Audit and event-record hierarchy changes with non-secret reach summaries. A newly attached child must clearly disclose that ancestor members gain access.
6. Add tree navigation and member-origin labels only after the authorization boundary is proven.

### Completed beta proof

- cycle and three-level depth constraints hold under concurrent writes;
- inherited roles cross only enabled boundaries; local exclusions remove one user's ancestor-derived authority without suppressing independent direct descendant roles;
- parent removal and downgrade revoke descendant access immediately;
- restricted page titles and UIDs never leak through trees, search, taxonomy, invitations, realtime, or MCP;
- page grants to a child include its effective inherited members, while child-only members do not gain grants addressed to a parent;
- selected MCP scopes never expand because a workspace is reparented or a new descendant is created;
- storage, categories, page moves, and exact-workspace Library filters remain isolated at each level;
- browser tests cover the real hierarchy UI and saved artifact preview revocation path.

These invariants are implemented and verified by application tests, PostgreSQL concurrency tests, and multi-engine browser tests in the released v0.0.9 line.
