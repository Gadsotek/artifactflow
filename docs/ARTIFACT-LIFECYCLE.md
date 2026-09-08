# Artifact identity and versions

A page is one managed artifact. It keeps its stable artifact UID as the work
changes. Each saved content version has its own UID, number, and immutable
payload. Links and permissions stay attached to the page.

## New version or new artifact?

**Product guidance:** keep the identity when the output still does the same job.
Create another artifact when both results should live independently.

| Change | Usually choose |
| --- | --- |
| Correct a runbook or redesign the same calculator | New version |
| Replace a report with its next revision | New version |
| Adapt a tool for another team with separate ownership | New artifact |
| Fork one dashboard into two maintained views | New artifact |

**Guidance, not an enforced invariant:** the writer decides whether the purpose
is still the same. ArtifactFlow cannot infer that from the number of changed bytes.

## What each action changes

**Current invariant:** content, catalog metadata, and lifecycle are separate.

| Action | Result |
| --- | --- |
| Create | Stable page and immutable first version, initially Draft |
| Save content | Append a version and advance the current pointer; Approved or Deprecated returns to Draft |
| Restore | Append a new version derived from retained content |
| Edit title, description, placement, category, tags, or owner | Update catalog metadata under its separate metadata revision |
| Change status | Update lifecycle without appending content |
| Preview unsaved HTML | Render temporarily on the artifact origin; creates no version |
| Reprocess a document | Refresh current derived data under concurrency checks; keep the original and version identity |
| Archive | Hide from default discovery; remain recoverable |
| Hard-delete | Irreversibly remove the page and its retained graph; Admin-only |

Draft is a lifecycle status, not mutable content. Saving a Draft still appends
an immutable version. Unsaved preview is a separate, temporary operation.

A metadata revision is not a content-version snapshot. Restoring content does
not restore historical titles, tags, ownership, or other catalog fields.

## What is retained

| Format | Authoritative payload | Derived presentation and search |
| --- | --- | --- |
| HTML | Single-file source | Isolated executable preview and extracted text |
| Markdown | Markdown source | Sanitized rendering, strict Mermaid, extracted text |
| PNG/JPEG | Re-encoded pixels | Scriptless preview; metadata search, no OCR |
| PDF | Exact validated private original | Bounded embedded text and native PDF viewing |
| XLSX | Exact validated private original | Typed visible-cell manifest; no formula calculation |
| DOCX | Exact validated private original | Independently validated passive PDF and its bounded native text |

Original image containers are discarded, including EXIF/GPS, profiles, comments,
and appended payloads. Restoring an image copies retained normalized bytes
without another lossy JPEG generation.

PDF, XLSX, and DOCX support are independent default-off production opt-ins.
DOCX additionally requires PDF processing. PDFs use the browser's native PDF viewer on the existing cookieless artifact origin.
DOCX uses that viewer only for its validated derivative. Both are
download-equivalent. Extracted text is untrusted and does not prove visibility
or visual redaction. XLSX and DOCX originals require a separate authenticated
attachment download; they are never the browser preview.

Reprocessing verifies the original, runs current processing/scanning, and
updates current facts, search text, and derivatives. It appends no version.
Any increased derivative size consumes quota without borrowing pruning credit.

## Concurrency and retention

Content writes require a fresh base-version UID. Metadata writes require a
fresh metadata revision. Description writes require both, so observations about
old content cannot overwrite a description for new content.

Appending past the configured version cap prunes the oldest retained content
and records each pruning. The default is 200 versions; history is not unlimited.
Originals and derivatives count together toward page and workspace quotas.
Moves transfer the complete retained graph atomically. Hard deletion and
retention remove the corresponding files; interrupted cleanup is handled by
the age-gated orphan reaper.

## Provenance

Every version has an observed ingest record: actor, time, method, exact retained
hash, and lineage. Optional producer claims are separate and self-reported.
Known provider/model facts may be partial. The server never invents a model
from an MCP client name or upgrades a declaration into verified authorship.

A restore records who restored it and where the bytes came from. Ordinary
content pruning preserves ingest/provenance records; hard page deletion removes
them. Optional external references inherit page authorization, are never
fetched, and stay out of search, logs, and audit payloads.

[MCP setup](operations/mcp.md) covers reads and writes;
[provenance reference](operations/provenance.md) covers declarations and wire fields.

## Not promised

**Roadmap direction:** browser provenance entry, assertion amendment, audited
reference redaction/retention, and provider attestations remain future work.
Per-version catalog metadata is not promised. Preserving an optional generator source
beside a document original needs a separate decision.

Read the [format decisions](architecture/README.md), [threat model](../THREAT-MODEL.md),
and [roadmap](../ROADMAP.md) for the remaining boundaries.
