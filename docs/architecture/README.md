# Architecture maps and decisions

ArtifactFlow is a self-hosted workspace backed by a versioned artifact vault.
Start with the [architecture overview](../ARCHITECTURE.md).

| Diagram | Shows |
| --- | --- |
| [System map](overview.svg) | Runtime boundaries, layers, and modules |
| [Design and workflows](workflows.svg) | Storage, preview, and request flows |

| Decision | Contract |
| --- | --- |
| [External sharing](external-sharing.md) | Expiring or one-time page capabilities, isolated viewer sessions, live revocation |
| [Nested workspaces](nested-workspaces.md) | Three levels, downward membership inheritance, exact MCP scope |
| [PDF](pdf-artifacts.md) | Default-off validation, native text, download-equivalent viewing |
| [XLSX](xlsx-artifacts.md) | Default-off typed projection and isolated read-only grid |
| [DOCX](docx-artifacts.md) | Default-off conversion with independent PDF validation |

The [threat model](../../THREAT-MODEL.md) explains controls and residual risks.
[Operations](../OPERATIONS.md) explains how to run and verify them.
