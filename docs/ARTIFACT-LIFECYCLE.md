# Artifact identity, drafts, and versions

ArtifactFlow preserves deliberate outputs as managed artifacts. This document explains when an artifact keeps its identity, what a saved version means, how drafts behave, and how the model is intended to extend to PDF and DOCX.

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

**Current invariant:** a content version retains the authoritative payload. For current HTML artifacts, that payload is also the executable single-file result. For Markdown, the payload is Markdown source and the rendered view is derived from it.

Catalog metadata such as title, description, category, parent, owner, and tags belongs to the stable artifact record. Metadata writes use a separate optimistic metadata revision and produce domain events and audit entries. A metadata revision is not a content-version snapshot, and content version history does not currently promise to restore historical catalog metadata.

This distinction keeps content concurrency and metadata concurrency explicit without claiming a complete snapshot of the whole artifact record for every content version.

## Current format behavior

### Single-file HTML

The retained payload is the HTML source and executable result. Saved and unsaved previews run on the separate artifact origin under the documented iframe, CSP, signed-capability, and no-app-cookie boundary.

### Markdown and Mermaid

The retained payload is Markdown source. The application derives the rendered view and processes Mermaid under the documented strict rendering boundary. Raw user HTML and JavaScript do not execute in the authenticated application DOM.

## PDF and DOCX direction

**Roadmap direction:** PDF and DOCX support is roadmap work, not current behavior.

Each document replacement is intended to append an immutable artifact version that retains its private original. Bounded, isolated processing may derive searchable text, OCR output, structural metadata, and a safe non-executable preview. Authorized users may read the preview and download the original without making the binary public or inheriting the authenticated application origin.

The planned model is:

- one stable artifact identity;
- one private original for each document version;
- extracted searchable text and preview derivatives tied to that version;
- visible processing status and safe failure behavior;
- consistent authorization across search, preview, download, history, MCP, and deletion.

Per-version catalog metadata is not promised. Whether future document versions snapshot title, tags, ownership, or other catalog fields needs a separate product and data-model decision.

For generated DOCX, preserving an optional generator source such as Markdown or Python beside the binary original remains an open design question. ArtifactFlow must not pretend every uploaded document has such a source.

## Examples

| Change | Recommended identity |
| --- | --- |
| The team replaces a capacity calculator with a redesigned implementation for the same job | Append a version |
| A runbook receives a corrected procedure while existing links should stay valid | Append a version |
| A calculator is adapted for a different business unit with independent access and ownership | Create a new artifact |
| One dashboard forks into two independently maintained operational views | Create a new artifact |
| A PDF report is replaced by its next retained revision after document support ships | Append a version |

## Related boundaries

- [Architecture](ARCHITECTURE.md) documents application handlers, storage, preview flows, and runtime roles.
- [Roadmap](../ROADMAP.md) is authoritative for PDF and DOCX candidate scope and required proof.
- [Threat model](../THREAT-MODEL.md) documents executable HTML isolation and residual risks.
