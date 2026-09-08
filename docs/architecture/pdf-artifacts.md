# PDF artifacts

Implemented, default-off. Production enablement requires this format's
deployment and browser evidence. OCR is outside the current profile.

## Decision

A dedicated processor validates the PDF and extracts bounded native text.
The app retains the exact private original. An authorized browser views that
original with its native PDF viewer on the cookieless artifact origin.

Preview is download-equivalent. There is no raster renderer, preview derivative,
HTML conversion, custom JavaScript viewer, or third origin. The browser may
offer save, print, copy, and supported link controls.

## Write and processing contract

```text
web upload or canonical MCP bytes
  -> scope, authority, transport and quota checks
  -> one-worker admission
  -> isolated PDFBox validation and native-text extraction
  -> authenticate/bound result, scan text, stage private bytes
  -> locked authority/concurrency recheck
  -> commit version, facts, search, quota, audit and event
```

Laravel loads no PDF parser. Processing runs outside a database transaction.
Admission bounds input bytes, pages, text, time, memory, PIDs, and tmpfs.
Retained originals must fit both the processor input limit and installation
artifact read limit. One replica/concurrency one rejects overlapping work
retryably; more replicas require a shared admission design.

MCP Base64 expansion must fit the smallest edge/PHP/framework/memory envelope
with fixed headroom. Do not widen generic unauthenticated JSON limits to match
the PDF hard ceiling. Check operation/upload scopes and exact authority before
decoding or admitting parser work.

The engine rejects encryption, malformed/ambiguous structures, active content,
interactive forms, embedded files, and resource exhaustion. Corpus cases include
renamed HTML, late headers, trailing payloads, polyglots, compression bombs,
recursive structures, actions, and server/browser interpretation differences.
Only explicitly supported passive link behavior is accepted. A material parser/
browser disagreement fails closed until understood.

The app authenticates the response nonce/input hash, schema, engine/profile,
sizes, and completeness. Only fixed allowlisted rejection categories cross
the boundary; diagnostics and document strings stay private. Authenticated
output remains untrusted. Extracted `<script>` text is escaped, never HTML.

## Processor containment

The processor has no app source, database, artifact storage, signing/session
keys, public ingress, or outbound route. Use a pinned non-root read-only
container, no capabilities, no-new-privileges, and hard resource ceilings.

| Topology | Required containment |
| --- | --- |
| Unix socket | `pdf-processor-service`, network mode `none`, relay only to its internal loopback listener |
| Private network | Published `pdf-processor-private-service`, private HTTPS proxy, inherited outbound syscall deny and successful startup self-test |

The private service denies TCP/UDP connection/send alternatives, SCTP,
packet/netlink sockets, and io_uring. Every native engine additionally denies
fork/vfork, disables clone3, and permits clone only for JVM threads. Forced
termination must leave no descendant observing later temporary inputs.

The fixed health process handles no document bytes and reaches only loopback
`/health`. The endpoint verifies direct loopback peer and a fresh domain-separated
timestamp/nonce HMAC before its shared engine lease; forwarding through a
loopback TLS sidecar grants no unauthenticated native work. Both listener and
PDFBox health must pass under the inherited filter.

HMAC is not encryption. Socketless production requires private HTTPS; an
internal subnet alone is not directional denial. Test effective callbacks and
egress from the running container. See [processor operations](../operations/processors.md).

## Data and lifecycle

`page_versions` owns original path, bytes, SHA-256, and bounded current-version
text. `pdf_version_facts` records page count, PDF version, extraction status,
and processor profile. No duplicate text object, render queue, or preview state
is added. Image-only PDFs are marked `no_embedded_text` and remain discoverable
through authorized metadata.

Replacement and restore append versions. Restore reruns the current processor.
Reprocessing verifies the retained original and updates current text, facts,
scan state, and search without changing the original or appending a version.
Moves, quotas, pruning, deletion, and integrity checks follow the same retained
version graph. Cleanup happens after commit.

The artifact host needs no PDF-facts grant beyond its existing presentation
tables. It already mounts private originals and is not a separate confidentiality
boundary against its own compromise. No audit/event metadata contains extracted
text, originals, paths, signed URLs, or document-derived facts.

## Native viewer exception

Signed purposes distinguish current, history, and download. They bind exact
page/version, configured artifact origin, issue/expiry, and live access revision.
Every view, range, and download request applies the same authorization.
Missing/inaccessible/stale/malformed requests are uniformly unavailable.

Native viewer evidence shows sandboxed PDF frames render blank in supported
Chromium. **PDF-only iframe and response CSP omit sandbox.** They retain
`allow=""`, `referrerpolicy="no-referrer"`, separate cookieless origin,
app-only frame ancestors, no CORS, and restrictive resource policy.
The frame therefore has the real artifact origin rather than an opaque one.
Never generalize this exception to HTML, images, or typed XLSX.

Responses use fixed `application/pdf`, safe application-generated filenames,
inline or attachment disposition, `private, no-store`, and `nosniff`.
Upload metadata is not reflected. If range support is needed, accept only a
single valid range against the exact size with identical checks. Unsupported
viewers get deliberate download fallback, not weaker app-origin rendering.

## Search, MCP, and external sharing

Search establishes visibility before selecting bounded text/snippets. Embedded
text may include clipped, hidden, transparent, overpainted, or stale strings.
It is neither proof of visual redaction nor trustworthy model instructions.

MCP create/replace requires create/update plus `mcp:upload`, exact scope, live
Editor authority, and fresh revisions. Reads return only bounded enveloped text
and safe facts, never PDF bytes, signed URLs, storage paths, or diagnostics.

External sharing uses separate share/session-purpose capabilities bound to
current version, access revision, expiry, and origin. It uses the same native
PDF response and has no separate anonymous download endpoint. Share revocation,
expiry, archive, move, access invalidation, feature disablement, and version
mismatch close future artifact loads; a refreshed live viewer follows the latest
version. Delivered bytes remain copyable.

Disabling PDF closes processing, delivery, refresh/download, and PDF MCP
read/search. Authorized normal catalog metadata stays visible: the flag is
a processing/delivery switch, not access revocation.

## Enablement and residuals

Before enabling, pin/verify the processor digest and SBOM, prove actual
containment and one-worker limits, complete Chromium/Firefox/WebKit plus
released Safari/iOS tests, and close the evidence-first review of the exact
app/processor versions. Cover origin/cookies, native-view compatibility, signed
expiry/revisions, ranges/fallback, transport, storage races, and non-disclosure.

Native parser/runtime/kernel flaws, browser PDF vulnerabilities, admitted DoS,
hidden-text secrets/prompt injection, user-followed links, and already-delivered
bytes remain risks. Scanning is advisory and native viewing is not DRM.

Rejected alternatives: app-origin viewing, parsing in Laravel, header-only
validation, raster rendering, and HTML/custom-viewer conversion. Each either
breaks isolation or adds a new pipeline without enough benefit.
