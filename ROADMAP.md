# Roadmap

ArtifactFlow is an open-source, self-hosted workspace for AI-generated artifacts.
This is direction, not a release promise. The product remains a public alpha.

## Available now

| Capability | Current scope |
| --- | --- |
| Artifacts | Markdown/Mermaid, single-file HTML, normalized PNG/JPEG |
| Organization | Personal/shared workspaces, visible page trees, categories, tags, ownership |
| Retrieval | Permission-aware full-text search, scoped MCP reads and writes |
| History | Immutable content versions, source diffs, restore, configurable retention |
| Provenance | Observed ingests, optional exact or partial self-reported producers, restore lineage |
| Sharing | Authenticated access and narrow expiring or one-time page links, shipped in v0.0.7 |
| Account security | TOTP, recovery codes, trusted devices, installation policy |

MCP includes binary create/replace through `mcp:upload`, catalog changes through
`mcp:organize`, and owner-limited external-link creation through `mcp:share`.
All remain subject to token scope and live authority, capped at Editor.

## Released in v0.0.9: nested shared workspaces

Shared workspaces support three levels with downward membership inheritance,
local exclusions, and serialized reparenting. Personal workspaces stay
standalone. Libraries, categories, storage, and selected MCP scopes remain exact
to each workspace. Choosing a parent does not select its descendants.

See the [nested workspace contract](docs/architecture/nested-workspaces.md).

## Opt-in searchable PDF artifacts

Implemented, **default-off**. Retains exact private originals, extracts bounded
native text, and supports search, versions, MCP ingestion, reprocessing, and
native viewing on the cookieless artifact origin. Preview is download-equivalent.
Embedded text is not proof of visual redaction. OCR remains a later milestone.

[PDF contract](docs/architecture/pdf-artifacts.md) · [GitHub issue #32](https://github.com/Gadsotek/artifactflow/issues/32)

## Default-off searchable XLSX workbook artifacts

Implemented, **default-off**. An isolated SheetJS processor produces a bounded
typed manifest for search and an application-owned read-only grid. Formulas
are not recalculated. Hidden content and unsupported visual features are
omitted. The exact original remains a separate authenticated download.

[XLSX contract](docs/architecture/xlsx-artifacts.md)

## Default-off searchable Word document artifacts

Implemented, **default-off**. A networkless LibreOffice processor converts
accepted DOCX to PDF. A separate PDFBox processor validates the exact result
and extracts native text. Only that validated PDF is previewed or shared.
DOCX requires PDF enablement; legacy DOC, DOCM, and image-only documents are
outside the accepted profile.

[DOCX contract](docs/architecture/docx-artifacts.md) · [GitHub issue #33](https://github.com/Gadsotek/artifactflow/issues/33)

## Before enabling document formats

Each deployment must prove dedicated processor secrets, private transport,
effective outbound denial, one-worker resource limits, signed health checks,
hostile-input tests, and browser behavior including released Safari/iOS.
An internal network name alone is insufficient containment.
Follow [processor operations](docs/operations/processors.md) and the
[release checklist](RELEASE-CHECKLIST.md).

HTML retains the documented self-navigation and browser-dependent WebRTC residuals.
Sharing cannot revoke bytes already delivered. See the [threat model](THREAT-MODEL.md).

## Later candidates

- PDF OCR as a separate bounded processing feature.
- Browser provenance entry, assertion amendment, and reference redaction/retention.
- Verified provider attestations, distinct from self-reported claims.
- Better navigation and source/history ergonomics based on real usage.

Simultaneous editing, a visual diff UI, automatic chat capture, agent execution,
vector search, arbitrary ZIP/multi-file uploads, public browsing/marketplaces,
enterprise RBAC, SSO, and mandatory external storage/search services are not
promised. New security boundaries need a written decision and tests first.

For release history use the [changelog](CHANGELOG.md); for identity and retention
use the [artifact lifecycle](docs/ARTIFACT-LIFECYCLE.md).
