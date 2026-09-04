# Excel Workbook Artifacts: Architecture Decision

Status: Accepted and implemented default-off; production opt-in requires the
documented processor containment and operator evidence
Date: 2026-08-30

## Decision

ArtifactFlow retains an accepted `.xlsx` file as the immutable authoritative
original and creates one canonical typed JSON manifest in a dedicated isolated
processor. The browser receives only that manifest through an application-owned
read-only grid on the existing cookieless artifact origin. Neither Laravel nor
the browser parses the original workbook, and SheetJS HTML output is never used.

The accepted profile uses SheetJS CE 0.20.3 after an independent ZIP, XML, content-
type, and OPC relationship gate. It supports bounded visible cells, cached
formula results, merged ranges, and typed HTTP, HTTPS, email, and same-workbook
links. It deliberately rejects hidden-data ambiguity and optional workbook
features that can execute, embed another package, escape the package, or require
external access. Bounded passive optional parts may remain in the exact original
but never expand the typed projection.

## Load-bearing invariants

1. The application and browser never load the workbook parser or original bytes
   for preview.
2. The processor has no application source, database, artifact storage,
   signing/session keys, Docker socket, public listener, or outbound network.
3. Input is accepted only as a modern `.xlsx` ZIP package. CSV, legacy `.xls`,
   macro-enabled workbooks, encryption, malformed ZIPs, ambiguous entry names,
   attacker-defined relationship vocabularies, and active or external workbook features fail
   closed.
4. Every admitted ZIP entry is expanded under a 64 MiB aggregate ceiling and
   checked against its actual size and CRC. Standard 32-bit ZIP data descriptors,
   with or without their optional signature, are accepted only when their CRC and
   sizes exactly match the central directory and unambiguously end the local
   record. After the original package passes this complete gate, the processor
   creates a parser-only ZIP with equivalent validated compressed payloads and
   explicit local sizes so SheetJS does not need to interpret descriptors. The
   retained original remains byte-exact. Encryption, ZIP64 descriptors, gaps,
   overlaps, and conflicting local metadata fail closed. XML 1.0 is parsed as
   bounded UTF-8; DTDs, processing instructions, CDATA, and unexpected graph
   nodes are rejected.
5. SheetJS never evaluates formulae. Formula source and a producer-stored cached
   result are separate fields; an absent cache is shown honestly as unavailable.
6. Hidden and very-hidden sheets and hidden rows and columns do not enter the
   preview or search projection. Bounded passive tables, drawings, images,
   comments/VML, custom XML, worksheet custom-property binaries, pivots,
   connections, and formatting may be accepted structurally but are omitted.
   ActiveX, macros, OLE/embedded packages, web extensions, and external
   non-hyperlink relationships are rejected.
7. The application independently authenticates and validates the complete
   processor response and canonical manifest before staging or persistence.
8. Original and manifest bytes are separate private blobs, both hash/size bound,
   quota charged, integrity checked, pruned, restored, and deleted as one version
   graph. A composite database key binds the facts to the same version and the
   fixed XLSX-manifest derivative kind.
9. Signed preview and original-download capabilities bind the exact page,
   version, purpose, origin, expiry, and live access revision. Missing, stale,
   disabled, tampered, and cross-page claims return uniform not-found responses.
10. External sharing exposes only the current typed manifest. It never provides
    an anonymous original-workbook download.

## Data flow

```text
web upload or canonical MCP Base64
    -> authorization, extension, byte, quota, and admission checks
    -> dedicated single-worker XLSX processor
         -> strict ZIP/OPC/XML preflight
         -> SheetJS data parse with formula evaluation disabled
         -> bounded typed visible-sheet manifest + flattened search text
    -> authenticated response verification
    -> independent application manifest validation + advisory text scan
    -> private staging and transaction
         -> exact immutable workbook original
         -> canonical manifest derivative and non-content facts
         -> current search projection, quota, audit, domain event

authorized preview
    -> revision-bound signed URL
    -> cookieless artifact origin
    -> fixed application HTML + canonical manifest + pinned local viewer assets
    -> opaque sandboxed iframe
```

## Bounds and accepted profile

The processor hard-caps input and manifest bytes at 16 MiB, expanded package
bytes at 64 MiB, entries at 2,000, visible sheets at 20, rows per sheet at
20,000, columns at 256, projected cells at 100,000, merged ranges at 1,000, and
search text at 8 MiB. Installation artifact limits may lower the retained input
or derivative ceilings but never raise the processor profile.

Only sparse cells are traversed; a worksheet dimension does not authorize dense
range allocation. Manifest counts, extents, coordinate order, cell kinds,
formula/cache state, link targets, merged ranges, search text, exact key sets,
schema, engine, and profile are checked again in PHP. Stored bytes must equal the
canonical JSON serialization or delivery fails.

The OPC gate accepts bounded passive optional XML/VML, common image media, and
printer-metadata parts only when they are reachable through internal Open XML or
Microsoft Office relationship vocabularies and carry compatible content types.
Legacy worksheet custom-property data is accepted only at the exact
`xl/customPropertyN.bin` path, behind the official internal worksheet
`customProperty` relationship and its exact SpreadsheetML content type. These
opaque bytes remain only in the private original; SheetJS property parsing is
disabled and the bytes contribute nothing to preview, search, sharing, or MCP.
Generic, externally targeted, or wrongly owned binary parts still fail closed.
Every XML/VML part shares the aggregate XML budgets and rejects DTDs, entities,
processing instructions, and CDATA. Optional part text and binary bytes are not
copied into the manifest. Root relationships remain an exact workbook/properties/
thumbnail set, while external relationships remain limited to validated worksheet
hyperlinks.

## Viewer and links

The artifact response contains fixed markup, a base64url-encoded manifest in a
non-executable element, and only same-origin hashed JavaScript and CSS assets.
Its iframe and response CSP use:

```text
sandbox allow-scripts
default-src 'none'
script-src 'self'
style-src 'self'
connect-src 'none'
frame-src 'none'
object-src 'none'
form-action 'none'
```

Workbook strings enter the DOM only through text APIs. An external link is a
button, not an anchor: the opaque child sends only its normalized HTTP, HTTPS,
or `mailto` target to the parent. The app-origin parent accepts only the exact
message shape from that iframe's opaque origin, validates the target again, and
shows the complete destination before offering a second-click anchor with
`noopener noreferrer` and `referrerpolicy="no-referrer"`. The artifact frame has
no popup or navigation capability. Same-workbook links are buttons that select and focus a validated visible
cell; a range focuses its first cell. Defined names, hidden or out-of-profile
destinations, and other internal targets that cannot be represented safely are
omitted rather than rejecting the workbook. Formula text is display-only and is
never interpreted as a URL or code.

## Lifecycle, sharing, and MCP

Create, replace, restore, and reprocess all use the same processor and validator.
Replacing a workbook appends a version and removes stale extracted text from the
current search vector. Reprocessing changes only the current version's manifest,
facts, and search projection; it does not rewrite the retained original or append
a version.

Authenticated users with page access can download the exact original through a
separate attachment-only purpose. External shares follow the latest version and
render only the typed manifest. MCP `create_xlsx` and `replace_xlsx` require the
normal create/update scope plus `mcp:upload`. An MCP content `read` requires an
exact visible worksheet name plus a canonical uppercase A1 range of at most
1,000 cells, returns a response-size-checked typed selection, and reports whether
cells or merges remain outside that range. Metadata-only reads need no selector.
Search returns bounded snippets and safe facts only when the token also has
`mcp:read`; search-only tokens receive catalog metadata without those
content-derived facts. Neither surface returns original bytes, storage paths,
or signed URLs.

## Operations

XLSX support is independently default-off. Local Compose uses a group-only authenticated
Unix socket, `network_mode: none`, one worker, a read-only root, dropped
capabilities, `no-new-privileges`, and explicit CPU, memory, PID, file, tmpfs, and
time limits. The app role joins only the processor socket group. The service
image excludes the test corpus, and every authenticated projection rechecks
network containment before starting its isolated worker. The artifact-host role ignores the app's shared Vite hot marker and
loads only built, hashed, same-origin viewer assets; local developers run
`make build-assets` before enablement and after viewer changes. Cross-host operation additionally requires encrypted authenticated
transport and an effective directional deny from the processor to the app,
metadata services, private peers, DNS, and the public network.

When enabled locally, `artifactflow:install` provisions a dedicated secret and
runs the doctor; after the required service reload, `make doctor` must pass its
signed live profile/containment check. Production enablement requires a dedicated secret, app-only processor
credentials, presentation-only enablement on the artifact host, disabled flags
and no credentials on workers/schedulers, green hostile-corpus and browser
evidence, SBOM/advisory/image scans, and an `artifactflow:doctor` signed live
challenge that pins the profile/schema/engine and verifies loopback-only network
containment, followed by the full release
gates. Scanning remains advisory; processor and browser isolation are the security
boundaries.
