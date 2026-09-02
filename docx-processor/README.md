# ArtifactFlow DOCX processor

This dedicated service validates a bounded modern DOCX package and converts it
with Alpine's pinned LibreOffice Writer 25.8.7.3 package to one passive PDF. It accepts only the
`docx-passive-pdf-v1` profile over its private Unix socket. The application
then submits the exact output bytes to the separate PDF processor's
`pdfbox-3.0.8-docx-preview-v1` profile before storage, indexing, or delivery.

The container is designed to run with no network, a read-only root filesystem,
all capabilities dropped, `no-new-privileges`, one conversion at a time, a
bounded tmpfs, and explicit PID, memory, CPU, and wall-time limits. It receives
no application source, database settings, artifact storage, or PDF-processor
credentials. An accepted preview is not an antivirus verdict; original DOCX
download remains an explicit transfer of untrusted bytes.

Run the deterministic package/authentication contract suite in the pinned
container:

```sh
docker build --tag artifactflow-docx-processor:local docx-processor
docker run --rm --network none --read-only --cap-drop ALL \
  --security-opt no-new-privileges --pids-limit 128 --memory 768m --cpus 1 \
  --tmpfs /tmp:rw,noexec,nosuid,nodev,size=192m,mode=1777 \
  --mount type=bind,src="$PWD/docx-processor/test",dst=/srv/docx-processor/test,readonly \
  --entrypoint php artifactflow-docx-processor:local \
  /srv/docx-processor/test/run.php
```

The Dockerfile's runtime copy deliberately excludes tests. For a source-tree
contract run, bind only `test/` read-only at `/srv/docx-processor/test`; do not
mount the repository, application source, artifact storage, or credentials.
The real-engine proof runs `test/round-trip.php` in a test-stage build or an
equivalent read-only mount, retains the generated PDF, and submits those exact
bytes to the separately built PDF processor's
`pdfbox-3.0.8-docx-preview-v1` inspection command. Success requires bounded
indexed text, not merely a `%PDF-` prefix.

The service accepts exactly `POST /v1/docx/previews` plus an authenticated
`GET /health` challenge over an absolute Unix socket. Domain-separated HMACs
bind timestamp and nonce for the health request; the response signature binds
that challenge nonce and the exact JSON bytes. Conversion additionally binds
profile, input media type, length, SHA-256, and exact response bytes. Health succeeds
only when the pinned LibreOffice version runs and the process sees no
non-loopback network interface. Replays, stale timestamps, partial or oversized
bodies, response metadata mismatches, and multiple PHP CLI workers fail closed.
The socket directory is supplied by the deployment; no public or general TCP
listener is exposed.

The accepted package is a bounded passive modern WordprocessingML/Open XML profile. ZIP entry
names, sizes, CRCs, namespaces, content types, relationships, relationship IDs,
targets, XML structure, fields, media, exact ZIP boundary, and aggregate
expansion are validated before LibreOffice. The passive style profile includes
both the classic `styles.xml` part and modern Word's fixed
`stylesWithEffects.xml` companion. Media is limited to signature- and
dimension-checked PNG/JPEG plus bounded EMF vectors. EMF admission requires its
exact image relationship and content type, a valid header and signature, exact
byte/record accounting, aligned contiguous records, a terminal EOF, and no
driver/OpenGL/named escape records. Media retains independent count and byte
ceilings, with dimension/pixel ceilings for raster images and record/handle
ceilings for EMF. Root package thumbnails may also use common PNG/JPEG/EMF/WMF
thumbnail content types behind the exact thumbnail relationship because those
bytes are stripped without native parsing before conversion. Macros, encryption,
symlink-like entries, unsupported compression,
trailing container data, OLE or embedded packages, `altChunk`, external
relationships other than reviewed hyperlinks and the sanitized attached-template
exception, file/UNC relationships, active fields, traversal,
DTD/entities/XInclude, embedded-document WMF/SVG, attacker-defined relationship vocabularies, and
unknown binary parts are rejected. Standard internal passive XML parts such as
charts, diagrams, drawings, and modern comment metadata are admitted under the
official Open XML/Microsoft Office relationship and content-type vocabulary.
HTTP/HTTPS/email
hyperlinks are allowed because the converter has no network and the resulting
PDF must pass the separate active-structure rejection profile.

Rejected embedded files and OLE objects return only the fixed `embedded_file`
reason category so the application can explain the unsupported feature. Raw
package paths, filenames, parser messages, and all other document-derived
diagnostics remain behind opaque operator log codes.

Bounded `.odttf` font parts are accepted only with the exact OOXML obfuscated-font
content type and a relationship owned by `word/fontTable.xml`. The processor does
not decode or submit those font programs to LibreOffice. It removes the parts,
relationships, declarations, and `w:embed*` references from a conversion-only ZIP,
revalidates that ZIP, and converts it using locally available fallback fonts. The
application still retains the byte-exact original, so the PDF preview may differ
in font selection or pagination without weakening original-file preservation.

Bounded custom XML data stores are accepted only with paired item-properties
relationships. The conversion copy removes the data stores, relationships,
content-type declarations, and `w:dataBinding` nodes while preserving cached
visible WordprocessingML. Printer-settings payloads and package thumbnails are
also omitted. A standard external `attachedTemplate` relationship is accepted
only when owned by `word/settings.xml`; its bounded target and every matching
`w:attachedTemplate` element are removed before conversion, so LibreOffice can
never fetch or use the template. The sanitized archive is preflighted again
before LibreOffice.

Every conversion gets a fresh temporary home and output directory. The
environment is allowlisted, success requires exactly one complete bounded PDF,
and timeout or failure terminates the complete LibreOffice process group before
temporary input is removed. Keep the production container networkless,
single-worker, non-root, read-only, capability-free, and within the Compose
resource envelope documented in `docs/OPERATIONS.md`.
