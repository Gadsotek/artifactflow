# Searchable PDF Artifacts: Architecture Decision

Status: Accepted and implemented default-off; production opt-in requires the
documented deployment containment and operator evidence
Date: 2026-08-25

Supporting product requirements, delivery sequencing, and point-in-time spike
evidence are maintained as private working records. This public decision is the
reviewable security contract for the implemented boundary.

## Decision

ArtifactFlow will use a conventional two-part PDF design:

1. A dedicated isolated processor validates a bounded PDF and extracts embedded
   text before the application commits a new version.
2. The browser displays the exact authorized original with its native PDF
   viewer on ArtifactFlow's existing cookieless artifact origin.

There is no server raster renderer, preview derivative, custom browser viewer,
HTML conversion, PDF sanitizer, or third origin.

Preview is download-equivalent. It is an authorized transfer of the original,
not a protected representation that prevents save, print, copy, or links.

## Why this fits ArtifactFlow

The second origin already contains hostile HTML and normalized image previews.
It is the natural place for browser-native PDF parsing: application cookies and
the authenticated DOM are absent even if a browser viewer has a defect or a PDF
contains active-looking structures.

Search is different. The server must parse attacker-controlled bytes to obtain
embedded text, so that work stays outside the application process with the same
basic isolation philosophy as raster image decoding.

## Load-bearing invariants

1. The application process does not load a PDF parsing library.
2. The processor has no application source, database/artifact-storage
   credentials, signing/session keys, public listener, or outbound network.
3. Native processing is bounded by bytes, pages, text output, time, memory,
   processes, and temporary storage, and runs outside a database transaction.
4. New PDF originals cannot exceed either the processor input limit or the
   installation's artifact read ceiling, so every retained original remains
   readable through the normal artifact boundary.
5. A version commits only after parsing, extraction, scanning, quota checks, and
   private staging succeed.
6. Original PDF responses exist only on the configured cookieless artifact
   origin and use application-owned `application/pdf`, disposition, and
   filename headers with `nosniff`.
7. Every view, Range, and download request is bound to the exact page/version,
   purpose, expiry, artifact origin, and live access revision.
8. Missing, inaccessible, stale, malformed, and unsupported requests share
   uniform not-found behavior.
9. Search establishes page visibility before selecting extracted text,
   snippets, or matching page numbers.
10. Extracted text is untrusted, escaped, bounded, and never evidence of visual
   redaction or document safety.
11. Original object bytes are charged through the existing transactional page
    storage counter. Derived current-version text and PDF facts stay in the
    database and move or delete with the same version graph.

## Data flow

```text
upload or MCP bytes
    -> authentication and transport limits
    -> shared nonblocking admission
    -> isolated PDF processor (load + page-wise text extraction)
    -> bounded authenticated result
    -> existing content scan
    -> bounded private staging and SHA-256
    -> authorization/concurrency recheck and transaction
         -> private immutable original
         -> one-to-one PDF facts and bounded current-version text
         -> current permission-aware search projection
         -> storage accounting, audit row, durable domain event

authorized app page
    -> short-lived revision-bound signed URL
    -> cookieless artifact origin
    -> exact private PDF as application/pdf
    -> browser-native viewer
```

## Processor boundary

The processor does only four things:

- load the complete document with a maintained PDF engine;
- reject encryption and documents it cannot inspect within configured limits;
- report bounded technical facts such as page count and PDF version; and
- extract bounded page-wise UTF-8 text.

Extraction reads the PDF's existing text layer. It is not OCR. Scanned or
image-only pages can therefore produce no text and are recorded honestly as
`no_embedded_text`.

It does not render, rewrite, sanitize, fetch links, scan content, authorize
users, choose storage paths, or access the database.

The production service follows the existing internal-parser pattern: a pinned
non-root read-only container, strict request/response schema, authenticated
internal requests, dropped capabilities, `no-new-privileges`, PID/CPU/memory/
tmpfs/time limits, and one of two reviewed directional transports:

- Local and compatible orchestrators use the Unix-socket-only
  `pdf-processor-service` target with Docker network mode `none`. The socket
  relay may reach only the processor's own loopback-bound HTTP process.
- Private-network-only orchestrators use the separately published
  `pdf-processor-private-service` image. Before binding its HTTP listener, the
  launcher enables `PR_SET_NO_NEW_PRIVS`, installs a seccomp filter that every
  PHP/PDFBox child inherits, and proves TCP `connect` plus destination-bearing
  UDP `sendto` fail with `EPERM`. The filter also denies SCTP socket creation,
  `sendmsg`, `sendmmsg`, packet/netlink sockets, and io_uring so a compromised
  parser cannot choose an alternate outbound syscall path. The service receives
  no public domain. Its fixed Docker health process handles no document input
  and may connect only to loopback `/health`; that endpoint exercises PDFBox
  under the server's inherited filter, so listener or engine failure makes the
  container unhealthy. The route checks the direct peer address, ignores
  forwarding headers, and returns the bounded not-found response to every
  non-loopback caller before native-engine admission.

Both modes let the app initiate an authenticated request while denying the
processor a callback path into the app, cloud metadata, private peers, DNS, or
the internet. Cross-host deployments need an equivalently directional network
policy in addition to authenticated encrypted transport. Effective callback
and outbound denial are tested from the running container; an ordinary Docker
`internal` network is not proof of this property.

Every PDFBox invocation also passes through a fail-closed process-containment
launcher. Its seccomp filter denies `fork` and `vfork`, reports `clone3` as
unavailable so the JVM falls back to the inspectable `clone` path, and permits
`clone` only when `CLONE_THREAD` is present. PDFBox may therefore create JVM
threads but cannot leave a child process behind after a timeout or output-limit
kill. Runtime probes exercise the production image and verify that a timed-out
engine cannot retain a descendant or read a later request's temporary input.

The application validates the response schema, request nonce, input hash,
engine/profile, sizes, and completeness. An authenticated response does not
make parser output trusted.

Document rejections may return one bounded, allowlisted reason category such as
encryption, invalid structure, active content, or an interactive form. The app
maps that category to fixed user-facing copy; raw engine stderr, parser
exceptions, document content, and unrecognized diagnostics never cross the
processor boundary or enter logs.

The first release runs exactly one processor replica with native concurrency
`1`. It rejects concurrent work immediately with a bounded retry response
instead of building a queue. If a future deployment needs multiple processor
replicas, shared admission becomes a prerequisite for that topology; it is not
introduced for the initial one-maintainer deployment.

## Content confusion and PDF features

The app performs only cheap envelope checks; the engine decides whether the
bytes form a loadable PDF. The corpus includes renamed HTML/JavaScript,
malformed/truncated data, late headers, trailing payloads, polyglots, encrypted
documents, compressed amplification, recursive structures, and time/memory
exhaustion.

PDFs containing JavaScript, actions, links, forms, or embedded files are tested
explicitly. ArtifactFlow need not invent a complete PDF sanitizer. The selected
engine may reject clearly unsupported structures; otherwise browser handling is
contained by the artifact origin and documented as a residual. A material
server-engine/browser interpretation disagreement is rejected until understood.

Valid PDF text such as `<script>alert(1)</script>` is ordinary untrusted text.
It is escaped in snippets, UI, logs, and MCP and is never rendered as HTML.

## Persistence

The existing `page_versions` row already owns the immutable private object key,
original byte count/SHA-256, and bounded current-version `extracted_text` used
by search. The minimum schema therefore adds only one `pdf_version_facts`
extension row per PDF version: page count, PDF version, honest extraction
state, and processor profile. It does not duplicate original-storage facts or
introduce a second text object.

Historical originals remain authoritative. Restoring or reprocessing an old
version reruns the current processor profile, matching the existing lifecycle
that retains extracted search text only for the current version. Bounded
per-page text rows are deferred until the page-reference snippet slice needs
them.

No text/raster derivative object, render job, preview state, task manifest, or
preview storage reservation is introduced.

If operational extraction-attempt history is needed, it is app-origin-only.
The artifact host keeps the repository's established read-only grants on
`pages` and `page_versions`, which include the current extracted-text column,
because that runtime already mounts every retained original it can serve. PDF
does not add a grant on `pdf_version_facts`, audit/event tables, users, or
application counters. A compromised artifact-host runtime is therefore not
treated as a text-confidentiality boundary; it is already a private-storage
compromise and remains outside this threat model.

## Write boundary and events

A typed application command handler coordinates each create, replace, restore,
or reprocess use case:

1. validate and authorize;
2. enforce transport limits, stage/hash bytes, acquire admission, call the
   processor, verify its bounded response, and scan extracted text outside a
   database transaction;
3. open a transaction, lock in the existing order, recheck live authorization
   and optimistic concurrency, attach storage references, update actual quota
   and search, and persist the audit row plus durable domain event; and
4. clean unreferenced staging after failure and dispatch observational listeners
   after commit.

Controllers, jobs, Blade templates, and Eloquent models do not own this
workflow. Phase 0 changes no domain state and therefore emits no domain event.

## Native-viewer boundary

The app issues signed presentation URLs after live authorization. Claims bind
schema version, purpose (`current`, `history`, or `download`), page UID, version
UID, configured artifact origin, issue/expiry time, and access revision.

The artifact host verifies the claims with a narrow live page/version query,
then streams the exact retained object:

- view: `Content-Type: application/pdf` and inline disposition;
- download: the same bytes with attachment disposition;
- both: safe application-generated filename, `private, no-store`, `nosniff`,
  no reflected upload metadata, no CORS, and no application cookies.

Headed Chromium evidence proves that its native viewer remains blank whenever
the iframe or response CSP has a `sandbox`, including with `allow-scripts` and
`allow-same-origin`. PDF viewing therefore uses a narrow format-specific
exception: its iframe has no `sandbox` attribute, keeps `allow=""` and
`referrerpolicy="no-referrer"`, and may load only the signed artifact-origin
URL. Its response CSP has no `sandbox` directive and denies resources with
`default-src 'none'`, `object-src 'none'`, `base-uri 'none'`, and
`form-action 'none'`, while `frame-ancestors` names only the configured app
origin. HTML and image profiles do not change.

This PDF frame has the real artifact origin rather than an opaque origin. The
security boundary is therefore the existing app/artifact same-origin-policy
separation, absence of app cookies and artifact credentials, signed
revision-bound authorization, restricted artifact-host data access, and
rejection of active PDF structures. Browser PDF viewers are privileged browser
UI, so CSP is defense in depth and is not described as DRM or a PDF sanitizer.

Cross-engine tests record whether Range or HEAD is necessary. If Range is
needed, the route accepts one syntactically valid byte range against the exact
recorded size and applies identical signature/access checks. Invalid or
multiple ranges fail closed. If a supported browser cannot display with the
PDF-specific profile, that browser gets an explicit download fallback; the app
origin and the HTML/image sandbox profiles are not weakened.

## Search, MCP, and lifecycle

Current-version extracted text joins the existing PostgreSQL search projection.
Authorization is applied in the query before text or snippets are selected.

MCP PDF create/replace composes the existing operation scope with
`mcp:upload`, exact workspace ceiling, live Editor authority, and optimistic
concurrency. MCP reads return only bounded escaped text and safe extraction
facts in an explicit untrusted-data envelope. PDF bytes, signed URLs, storage
paths, and processor diagnostics remain unavailable.

Replacement and restore append versions. Reprocessing changes only derived
text/search facts and emits its own audit/event record. Moves transfer actual
original/text bytes atomically. Pruning and hard deletion remove the complete
version graph through existing post-commit cleanup.

External sharing reuses the accepted anonymous share/session capability rather
than authenticated PDF view URLs. Its short-lived artifact URL is bound to the
share UID, anonymous view-session UID, current page/version, copied access
revision, expiry, and artifact origin. The artifact host then returns the same
PDF-specific inline response profile. PDF disablement, share revocation,
expiry, archival, move, access-revision change, or current-version mismatch
closes future loads. There is no separate anonymous download endpoint, but
native viewing is download-equivalent because the browser receives the exact
retained bytes and may offer save, print, and copy controls.

## Focused production-enablement gate

Before enabling PDF in a production deployment:

1. Pin the dedicated processor image by digest and verify its provenance/SBOM.
   The running deployment must show the outbound-denial startup proof, stay
   private, and preserve single-replica/concurrency-one resource limits.
2. Browser tests cover Chromium, Firefox, and WebKit; released Safari/iOS gets
   the existing manual security pass. Evidence covers origin/cookie isolation,
   CSP/sandbox compatibility, signed URL expiry/revision, Range behavior, and
   deliberate fallback.
3. Freeze the exact app and processor targets and run an evidence-first read-only
   security review of authorization, parser admission, storage/concurrency,
   logs/events, and browser boundaries. Fix confirmed defects and repeat before
   enabling PDF support.

This is a focused feature gate, not a requirement to prove the safety of every
valid PDF feature or replace the browser vendor's security work.

## Accepted residuals

- The browser's maintained native PDF parser processes attacker-controlled bytes
  on the authorized user's device, isolated from the app origin.
- An authorized viewer receives the original and may save, print, copy, or
  follow supported links.
- Extracted text may include hidden/stale content and prompt injection.
- A malicious PDF may consume its admitted processor budget before termination.
- Revocation prevents future loads but cannot erase bytes already delivered.
- Endpoint, browser extension, operator, host, or private-storage compromise is
  outside this boundary.

## Rejected alternatives

- **App-origin viewing:** collapses the existing browser isolation boundary.
- **Raster previews:** add a renderer, normalizer, queue, derivatives, storage,
  and visual-differential problems without enough benefit for this internal
  product.
- **HTML conversion/custom JavaScript viewer:** creates a larger client-side
  parsing and sanitization surface.
- **Parsing in the app process:** gives a parser failure application credentials
  and authority.
- **Header-only validation:** does not provide searchable text or bounded parser
  behavior.
