# Artifact identity, drafts, and versions

ArtifactFlow preserves deliberate outputs as managed artifacts. This document explains when an artifact keeps its identity, what a saved version means, how drafts behave, and how the model extends to the default-off PDF milestone and future DOCX work.

It separates three kinds of statements:

- **Current invariant:** behavior enforced by the application today.
- **Product guidance:** the choice ArtifactFlow recommends to people and agents, but cannot infer from content alone.
- **Roadmap direction:** a design constraint for future format work, not implemented alpha behavior.

## Artifact identity

**Current invariant:** a page is the managed artifact. It has a stable artifact UID that remains the same while its content changes. Each stored content revision has its own version UID and version number. The page points to its current version.

Creating an artifact stores its initial immutable version. Updating its content appends another immutable version and advances the current-version pointer. Retained versions remain available through version history. When the configured per-artifact limit is exceeded, ArtifactFlow prunes the oldest whole versions and records that pruning.

This gives links, permissions, ownership, hierarchy, taxonomy, and audit history a stable record while the retained payload evolves.

## New artifact or new version?

**Product guidance:** append a version when the work still represents the same durable thing.

Keep the same artifact when:

- it serves the same purpose or job;
- existing links should continue to identify it;
- readers should normally see the replacement as the latest form of earlier work;
- ownership, audience, and access remain conceptually continuous;
- the change is a substantial rewrite, but not a separate asset.

Create a new artifact when:

- both results should coexist as independently useful assets;
- the work has forked into a different purpose, audience, owner, or access boundary;
- retaining the old identity would make links or history misleading;
- the new result should have an independent lifecycle.

**Guidance, not an enforced invariant:** ArtifactFlow cannot determine semantic identity from how many bytes changed. A radically rewritten calculator may remain one artifact if it still fills the same role. A lightly edited copy may be a new artifact if it serves a different team or purpose. The person or agent performing the write makes that choice.

## Drafts

**Current invariant:** Draft is a lifecycle status, not mutable content.

A newly created artifact starts in Draft with an immutable first version. Every saved content update appends an immutable version, including while the artifact remains in Draft. Editing content on an Approved or Deprecated artifact returns it to Draft because the revised content has not retained the previous status.

A status transition changes lifecycle state. It does not, by itself, create a content version.

ArtifactFlow also supports an unsaved draft preview for single-file HTML. That preview is an ephemeral rendering of the editor input on the isolated artifact origin. It creates no version and does not alter the stored artifact. The term “draft” therefore appears in two related but distinct contexts:

- **Draft status:** saved artifact state backed by immutable content versions.
- **Unsaved draft preview:** temporary editor content that has not been persisted.

## Content versions and metadata

**Current invariant:** a content version retains the authoritative payload. For current HTML artifacts, that payload is also the executable single-file result. For Markdown, the payload is Markdown source and the rendered view is derived from it. For PNG/JPEG uploads, the retained authoritative payload is ArtifactFlow's normalized raster derivative; the untrusted original upload is intentionally discarded after pixel decoding and re-encoding. For the default-off PDF milestone, the authoritative payload is the exact validated private original; extracted text and PDF facts are derived projections.

Catalog metadata such as title, description, category, parent, owner, and tags belongs to the stable artifact record. Metadata writes use a separate optimistic metadata revision and produce domain events and audit entries. A metadata revision is not a content-version snapshot, and content version history does not currently promise to restore historical catalog metadata.

This distinction keeps content concurrency and metadata concurrency explicit without claiming a complete snapshot of the whole artifact record for every content version.

## Version provenance and lineage

**Current invariant:** every stored content version has one ArtifactFlow-observed ingest record. It
copies the version UID/number, exact retained-byte SHA-256, operation, ingest method, ArtifactFlow
actor, timestamp, and MCP submitter metadata when present. These are observed facts, not claims
about who generated the content.

A version may additionally have declared AI, human, or software producer assertions. MCP AI
assertions may be exact or partial: every safe supplied provider/model fact is retained independently,
and `partial` describes missing exact identity rather than rejecting a claim. The reported provider
is preserved beside its normalized search key; a model family/label does not require a fabricated
provider-defined model ID, and a typed external reference may stand alone as the only known producer
fact. Bounded extension pairs retain forward-compatible identity metadata while
prompt/reasoning, credential, authorization, URL, and content-payload classes remain forbidden.
Assertions are labelled self-reported and remain distinct from unverified MCP-reported client
name/version metadata. Missing provenance creates no invented “unknown model” row.

An MCP full read defines every visible producer assertion once in a deterministic `producers`
catalog. Page-origin, direct-version, and effective-content-origin lineage use ordered producer UID
references into that catalog. This changes only the response representation: evidence, precision,
authorization, search, and retention semantics are unchanged. Present untrusted strings retain their
complete field-level envelope, while absent optional description, change-summary, producer/client,
and external-reference values are omitted instead of represented by empty envelopes.

A restore creates a new version and records the selected source as derivation lineage. When the
retained bytes match, the write also resolves and stores the root content-origin version so reads
remain constant-cost even after a long equivalence chain. The user who restored content is
therefore not mislabeled as its producer.
Ordinary retention pruning may delete an old `page_versions` row and artifact blob, but keeps its
ingest/provenance record; page hard deletion removes both.

External artifact, conversation, session, and source references are optional sensitive metadata.
They inherit page authorization, are never fetched by ArtifactFlow, and are excluded from audit
payloads, logs, and full-text search.

## Current format behavior

### Single-file HTML

The retained payload is the HTML source and executable result. Saved and unsaved previews run on the separate artifact origin under the documented iframe, CSP, signed-capability, and no-app-cookie boundary.

### Markdown and Mermaid

The retained payload is Markdown source. The application derives the rendered view and processes Mermaid under the documented strict rendering boundary. Raw user HTML and JavaScript do not execute in the authenticated application DOM.

### Images and screenshots

The retained payload is a normalized PNG or JPEG containing decoded pixels, not the original file container. ArtifactFlow validates the format envelope, extension, compressed byte size, dimensions, and pixel count, then sends the original bytes to its isolated parser service. That service decodes and re-encodes the image; the app verifies its signed response before retaining it. This intentionally removes EXIF/GPS data, comments, color profiles, and bytes appended after the image.

Current image artifacts have no OCR or extracted text. Their searchable content is catalog metadata: title, editable description, category, tags, owner, status, and type. Replacing an image appends an immutable version, and restoring a historical image copies the selected normalized bytes exactly without another lossy JPEG generation.

Previews use a fixed scriptless viewer on the separate artifact origin. An MCP content read returns normalized rasters up to the configured `ARTIFACT_MAX_BYTES` read limit (10 MiB by default, hard-capped at 64 MiB; base64 framing expands the response by roughly a third) as image content (`content_too_large` is returned before reading a derivative above that limit). A metadata-only read performs the same authorization but skips the raster read and makes no content-availability claim. An authorized `update_description` call can revise only the page description when both the observed content-version UID and metadata revision remain current: the first binds the description to the inspected pixels, and the second protects concurrent catalog edits. MCP `create_image` and `replace_image` accept only canonical Base64 PNG/JPEG bytes under the combined page-operation and `mcp:upload` scopes, then use this same isolated normalization path; they do not fetch URLs or retain the submitted container. MCP image revert copies a retained normalized derivative exactly and therefore needs `mcp:update`, not `mcp:upload`.

## PDF artifacts and DOCX direction

**Current opt-in implementation:** PDF support is a default-off production opt-in; DOCX remains roadmap direction only. Production PDF enablement requires the dedicated isolated processor and the deployment evidence in the public architecture decision.

Each PDF replacement appends an immutable artifact version that retains its private original. The first PDF slice derives bounded embedded text through an isolated processor; OCR remains a later milestone. Authorized users view the exact original with their browser's native PDF viewer on the existing cookieless artifact origin. Embedded text is untrusted and is not proof that a string is visible or that the document was visually redacted. Preview is download-equivalent and may expose the browser's normal save, print, copy, and link controls.

The implemented default-off PDF model is:

- one stable artifact identity;
- one private original for each document version;
- bounded current-version extracted text, regenerated from a retained original
  when an old version is restored or reprocessed;
- visible extraction status and safe failure behavior;
- consistent authorization across search, native viewing, download, history, MCP, and deletion;
- actual storage ownership that moves atomically with the complete
  retained version graph.

Standalone reprocessing verifies the retained original's hash and size, reruns
the current processor/scanner outside the database transaction, and updates
only the current version's text projection, scan state, PDF facts, and search
projection. It does not create a new version or modify the retained original.

Per-version catalog metadata is not promised. Whether future document versions snapshot title, tags, ownership, or other catalog fields needs a separate product and data-model decision.

For generated DOCX, preserving an optional generator source such as Markdown or Python beside the binary original remains an open design question. ArtifactFlow must not pretend every uploaded document has such a source.

## Examples

| Change | Recommended identity |
| --- | --- |
| The team replaces a capacity calculator with a redesigned implementation for the same job | Append a version |
| A runbook receives a corrected procedure while existing links should stay valid | Append a version |
| A calculator is adapted for a different business unit with independent access and ownership | Create a new artifact |
| One dashboard forks into two independently maintained operational views | Create a new artifact |
| A PDF report is replaced by its next retained revision | Append a version |

## Related boundaries

- [Architecture](ARCHITECTURE.md) documents application handlers, storage, preview flows, and runtime roles.
- AI provenance records observed ingestion separately from declared producers, unverified MCP-reported client metadata, evidence, lineage, sensitive references, search, and retention. Detailed product and decision records remain internal.
- The public PDF [architecture decision](architecture/pdf-artifacts.md) defines the implemented default-off native-text-first boundary and its production-enablement gate; supporting product, delivery, and spike records remain private working material.
- [Roadmap](../ROADMAP.md) is authoritative for PDF and DOCX candidate scope and required proof.
- [Threat model](../THREAT-MODEL.md) documents executable HTML isolation and residual risks.
