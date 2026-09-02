# Word Document Artifacts: Architecture Decision

Status: Accepted and implemented default-off; production opt-in requires both
the DOCX and PDF containment evidence described here
Date: 2026-08-30

## Decision

ArtifactFlow retains an accepted modern `.docx` package as the immutable
authoritative original. A dedicated network-denied LibreOffice processor validates
it, sanitizes conversion-only data that must not reach native parsers, and converts
that copy to one bounded PDF. The separate PDF processor validates that exact output
under a DOCX-preview-specific profile and extracts its text. Only the validated
derived PDF is used for preview and external sharing.

The initial profile is native-text-only: a non-empty accepted document must yield
bounded, non-whitespace, selectable/searchable PDF text. Image-only documents,
legacy `.doc`, macro-enabled `.docm`, encrypted packages, and unsupported active
content are outside this release.

## Load-bearing invariants

1. Laravel does not parse DOCX or run an office converter. The browser never
   receives DOCX bytes for preview.
2. The LibreOffice container has no application source, database, artifact
   storage, app or PDF-processor credentials, public listener, user profile,
   Docker socket, host home, or outbound network.
3. A strict ZIP/OPC/XML preflight runs before LibreOffice. It rejects traversal,
   duplicate or ambiguous entries, excessive expansion, DTD/entity/XInclude
   behavior, macros, OLE/embedded packages, `altChunk`, active fields, unsafe
   relationships, and non-DOCX content confusion. The single standard
   settings-owned external attached-template relationship may be admitted only
   because both it and the matching `w:attachedTemplate` reference are removed
   from the revalidated conversion copy before LibreOffice. Bounded passive
   standard parts such as charts, diagrams, modern comment metadata, and custom
   XML are admitted only through internal Open XML relationship and content-type
   vocabularies.
   Bounded OOXML obfuscated-font parts may exist only behind the font-table
   relationship; their bytes and references are removed before LibreOffice, so
   untrusted font programs never reach its native font parser.
   Custom XML data stores and bindings, printer-only payloads, and attached-template
   references are likewise removed from the revalidated conversion copy; cached
   visible document content remains.
4. External HTTP, HTTPS, and email hyperlink relationships may be retained in
   the exact private original, but their relationships and WordprocessingML
   wrappers are removed from the conversion copy while visible child content is
   preserved. The converter therefore cannot fetch or emit them. The settings-owned
   attached-template exception is discarded before conversion and is never
   fetched. File links, UNC paths, credentials, fragments or queries in package
   targets, and all other external relationship types fail closed.
5. Alpine's pinned LibreOffice Writer 25.8.7.3 package runs one conversion at a time under a 30-second process-
   group deadline and bounded bytes, memory, PIDs, CPU, files, and tmpfs. The
   whole process group is terminated on timeout or any failed run.
6. The generated PDF is untrusted processor output. The independently isolated
   PDFBox profile rejects active structures and requires a complete bounded PDF
   with indexed text before storage.
7. Original DOCX and derived PDF are distinct private blobs with independent
   hash/size facts. Both are quota charged, integrity checked, pruned, restored,
   and deleted as one version graph. A composite database key binds the facts to
   the same version and the fixed DOCX-preview derivative kind.
8. Preview capabilities bind the derived PDF while original-download capabilities
   bind the DOCX. Purpose substitution, stale current versions, cross-page rows,
   expiry, access revision changes, feature disablement, and blob tampering fail
   closed with uniform not-found behavior.
9. External shares expose only the current derived PDF. There is no anonymous
   DOCX download endpoint.
10. A derived preview is not an antivirus verdict or a promise of visual fidelity.
    Explicit original download transfers untrusted Office bytes to the user's
    device.
11. Rejection details cross the processor boundary only through fixed allowlisted
    categories. A document containing an embedded file or OLE object receives an
    actionable message naming that unsupported category; package paths, filenames,
    parser errors, and other document-derived diagnostics remain private.

## Data flow

```text
web upload or canonical MCP Base64
    -> authorization, extension, byte, quota, and admission checks
    -> DOCX processor
         -> strict ZIP/OPC/XML profile
         -> sanitize embedded fonts, custom XML bindings, printer metadata,
            attached-template references, and external hyperlink actions from
            a validated conversion copy
         -> headless LibreOffice -> bounded PDF
    -> authenticated response verification
    -> separate PDF processor, docx-preview profile
         -> active-structure rejection + bounded native-text extraction
    -> advisory extracted-text scan
    -> private staging and transaction
         -> exact immutable DOCX original
         -> validated PDF derivative and safe facts
         -> current search projection, quota, audit, domain event

authorized preview or external view
    -> revision/share-bound signed URL
    -> cookieless artifact origin
    -> application-owned application/pdf response
    -> browser-native PDF viewer
```

## Package and conversion profile

The processor caps input and output at 16 MiB, expanded package bytes at 64 MiB,
and ZIP entries at 2,000. The admitted relationship graph is limited to the main
WordprocessingML document and bounded passive supporting Word/Open XML parts.
Standard internal chart, diagram, drawing, comment, and related XML parts may be
accepted when they use recognized Open XML or Microsoft Office relationship and
content-type namespaces. The only external non-hyperlink exception is the exact
standard `attachedTemplate` relationship owned by `word/settings.xml`; its target
is bounded, never fetched, and removed together with every matching
`w:attachedTemplate` element before native conversion. ActiveX, macros,
OLE/embedded packages, all other external non-hyperlink relationships,
attacker-defined relationship namespaces, and unreferenced parts still fail
closed. Each entry name, content type, namespace,
relationship ID/type/target, aggregate count, media count, and XML structure must
stay inside the profile. Embedded document media is
limited to signature- and content-type-matched PNG/JPEG plus a narrow EMF
vector profile. PNG/JPEG keep independent count, byte, dimension, and pixel
ceilings. EMF requires the exact image relationship, `image/emf` or the legacy
Office `image/x-emf` declaration, the standard signature and header, exact
declared byte and record counts, aligned contiguous records, bounded handles
and record count, and a terminal EOF; printer-driver, OpenGL, and named escape
records fail before conversion. WMF, SVG, and other document image formats remain
unsupported. A package thumbnail may additionally use a common EMF or WMF
thumbnail content type only through the exact root thumbnail relationship; it is
discarded without native parsing before conversion. The passive profile accepts
both the classic Word styles part and
modern Microsoft companions such as `stylesWithEffects` under the bounded
official relationship/content-type vocabulary. OOXML obfuscated fonts are accepted only as bounded
`.odttf` parts with the exact font-table relationship and
`application/vnd.openxmlformats-officedocument.obfuscatedFont` content type.
The processor never decodes or parses those font programs: it removes every
embedded-font part, relationship, content-type declaration, and `w:embed*`
reference from a new ZIP, revalidates that conversion copy, and only then invokes
LibreOffice. The authoritative original remains byte-exact. Missing local fonts
may therefore cause fallback fonts, pagination changes, or other preview fidelity
differences.

Bounded `customXml/itemN.xml` data stores and their paired properties may be
retained in the exact private original. Before conversion, the processor removes
those parts and relationships, removes `w:dataBinding`, unwraps `w:customXml`,
and preserves already-cached visible WordprocessingML children. Printer-settings
binary parts and package thumbnails, including common PNG/JPEG/EMF/WMF variants,
are also omitted from the conversion copy without being rendered.
External hyperlink relationships are removed from the same conversion copy and
their `w:hyperlink` wrappers are unwrapped without deleting visible runs. The
exact original and its safe non-content link count remain unchanged. The
sanitized ZIP is fully preflighted again before LibreOffice. Dynamic custom
XML refresh, printer-specific fidelity, and external template attachment are
therefore intentionally not part of preview behavior.

LibreOffice receives a fresh private temporary home and output directory. Its
environment is allowlisted, inherited caller state is removed, macro and update
behavior is disabled, and success requires exactly one complete PDF within the
output bound. A non-root FrankenPHP listener on the group-only Unix socket is
pinned to one PHP thread; its PHP entrypoint accepts only the health and
conversion paths. Every authenticated conversion rechecks network containment
before package inspection or native conversion.

The PDF processor receives only the generated PDF, not the DOCX or the DOCX
processor's secret. Its profile accepts only bounded in-document `GoTo`
destinations and rejects URI, JavaScript, launch, submit, remote-go-to, embedded-file,
interactive-form, and other active PDF structures. Extracted text is untrusted,
escaped, bounded index material; it is not exposed as the authoritative document
or evidence of redaction.

## Preview, hyperlinks, and downloads

The derived PDF uses the PDF format's narrow native-viewer exception: the iframe
has no sandbox attribute because supported browser PDF viewers otherwise render
blank. It keeps `allow=""`, `referrerpolicy="no-referrer"`, the distinct
cookieless artifact origin, a revision-bound URL, no CORS or cookies, and a
restrictive PDF CSP without a `sandbox` directive. Viewing remains download-
equivalent; browser UI may expose save, print, copy, and bounded internal links.

An authenticated original download uses a separate short-lived purpose and
always returns attachment disposition with an application-generated filename.
Upload filenames and document metadata are never reflected. The UI explicitly
states that opening the original delegates parsing to local Office software.

## Lifecycle, sharing, and MCP

Create, replace, restore, and reprocess rerun both processors. Replacing appends
an immutable version. Restore reconverts the historical original under the
current profiles. Reprocess atomically replaces only the current derivative,
facts, and search projection and deletes the abandoned generation after commit;
it never mutates the original or appends a version.

External sharing follows the latest version and sends only the validated PDF
derivative. MCP `create_docx` and `replace_docx` require the normal create/update
scope plus `mcp:upload`. MCP read and read-authorized search return only bounded
extracted text and safe conversion facts; search-only tokens receive catalog
metadata without content-derived facts. Neither surface returns DOCX/PDF bytes,
processor diagnostics, storage paths, or signed URLs.

## Operations

DOCX is independently default-off and also requires PDF support. Local Compose
runs separate authenticated Unix-socket-only DOCX and PDF processors with
`network_mode: none`; neither receives the other's credential or a direct path to
the other service. The app sequences them and independently validates both
responses.

When enabled locally, `artifactflow:install` provisions distinct DOCX and PDF
secrets and runs the doctor; after the required service reload, `make doctor`
must pass both signed live checks. Cross-host production requires encrypted authenticated transport plus effective
directional network denies, pinned images, read-only non-root containers, dropped
capabilities, `no-new-privileges`, one active conversion, bounded resources,
runtime callback/network/process cleanup probes, SBOM/advisory/image scans,
browser-native viewer evidence, and an `artifactflow:doctor` signed live challenge
that distinguishes the DOCX converter/containment result from the downstream PDF
processor result, followed by all release gates.
Workers and schedulers keep both flags disabled and receive no processor
credentials; the artifact host receives presentation flags only. Scanning is
advisory; the two processor cages and artifact-origin separation are the security
boundaries.
