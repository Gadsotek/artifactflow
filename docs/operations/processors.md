# Processor deployment

[Operations](../OPERATIONS.md) · [Release checklist](../../RELEASE-CHECKLIST.md)

Each parser is a separate security boundary. Do not run native processing in
Laravel or a browser, share processor secrets, or treat a private subnet as
proof of outbound containment.

## Common contract

| Requirement | Deployment rule |
| --- | --- |
| Credentials | One strong dedicated HMAC secret per processor, supplied only to it and app-role callers |
| Transport | Authenticated Unix socket with no network, or reviewed private HTTPS transport with directional outbound denial |
| Isolation | Non-root, read-only root, no capabilities, no-new-privileges, bounded CPU/memory/PIDs/tmpfs |
| Data access | No app source, database, artifact-storage mount, signing keys, public ingress, or other processor credentials |
| Admission | One worker; reject concurrent work retryably rather than queueing unlimited app requests |
| Verification | Signed live health checks, hostile-input corpus, timeout/cleanup checks, actual containment probes, image scans |

HMAC authenticates bytes but does not encrypt them or make parser output safe.
Production rejects socketless plain HTTP. Proxies must preserve exact signed
response bytes and must not compress image-parser responses.

Only app-role containers receive URL/socket/secret fields. The artifact host
receives document enablement flags for presentation with empty connection fields.
Worker/scheduler roles keep document flags false and connection fields empty.
All normalized secret bytes must differ from app, previous app, signing, and
every other parser/processor key. Canonical `base64:` secrets are decoded before
comparison and HMAC use.

## Images

Build the separately tagged `artifactflow-image-parser:production` image through
`make build-prod`. It is separate from the app and published document images.
The app image contains no GD/EXIF.

Prefer `IMAGE_PARSER_SOCKET_PATH` with network mode `none`. Cross-host use
requires a private encrypted authenticated path and denial of parser-to-app,
metadata, peer, and internet connections.

Keep one normalization process per 512 MiB container. Maximum-pixel decode,
rotation, and re-encoding can approach that memory budget. Startup rejects
prefork worker counts above one. Extra replicas only provide failover under
the shared single admission slot.

Requests sign input/output/pixel/dimension budgets. Admission charges pixels
and non-pixel work independently. Monitor retryable busy responses and
`image_parser.request_failed`. An uncertain timeout preserves its slot lease
until expiry; shortening the lease can admit overlapping native work.
The process has a 15-second execution ceiling. `/health` proves a fixed PNG
decode/re-encode. See [image security](../security/images.md).

## PDF

Leave `PDF_PROCESSOR_ENABLED=false` when unused. Supported topologies:

- `pdf-processor-service` with a shared Unix socket and `network_mode: none`.
- Published `pdf-processor-private-service` with a private HTTPS proxy, no
  public domain, and the inherited outbound syscall-denial filter.

For private networking, use one non-root replica, read-only root, dropped
capabilities, no-new-privileges, at most 32 PIDs, 512 MiB memory, one CPU,
and a 32 MiB noexec/nosuid `/tmp` tmpfs. Startup must report both:

```text
ArtifactFlow processor outbound syscall deny active.
ArtifactFlow PDF engine process creation deny active.
```

Absence or a failed health check is a deployment failure. The filter denies
outbound connection/send paths including SCTP, packet/netlink, and io_uring.
Native engines also deny child processes while permitting JVM threads, so
timed termination cannot leave a descendant observing later inputs.

The fixed health process reaches only loopback `/health` with a fresh,
domain-separated timestamp/nonce HMAC. The endpoint accepts only a direct
loopback peer and authenticates before acquiring the shared engine lease.
Forwarded peer headers are ignored. A proxy forwarding requests to loopback
must not grant unauthenticated native work.

Set `PDF_PROCESSOR_URL` to a pure private HTTPS origin and empty the socket path
for private networking. Socket deployments may use `http://localhost` because
the actual transport is the socket. The artifact host receives presentation
enablement only. [PDF architecture](../architecture/pdf-artifacts.md) defines
accepted content and the native-viewer exception.

## XLSX and DOCX

Both are default-off. DOCX also requires PDF on app and artifact-host roles.
The app passes LibreOffice output to the independently credentialed PDFBox
boundary. Neither processor receives the other's secret.

Shipped Office images expose authenticated Unix sockets only. Run with
`network_mode: none`. Sockets must be `0660`, owned by dedicated UID/GID:
XLSX `10003`, DOCX `10004`, PDF `10002`. App containers join only required socket
groups. Do not use world-writable sockets.

Use the reviewed Compose resource ceilings as minimum restrictions. XLSX
health and each authenticated projection verify loopback-only containment.
DOCX uses rootless single-thread FrankenPHP, checks containment before every
conversion, creates a fresh LibreOffice profile, and kills the full process
group on timeout/failure. Health/conversion nonce claims cover the full accepted
timestamp window, including positive clock skew.

Cross-host deployment needs a reviewed private TLS proxy forwarding only the
processor operation to its Unix socket. Deny processor-initiated app, artifact,
other processor, DNS, metadata, private-peer, and internet traffic at the host
or orchestrator. Do not add a general TCP listener to these images without a
new security review and runtime corpus.

For local XLSX, run `make build-assets` before enablement and after viewer
changes. The artifact host ignores the shared Vite hot marker and uses hashed
same-origin assets under its strict CSP.

## Enablement checklist

1. Pin and verify every image digest, SBOM, provenance, and scan result.
2. Configure per-role flags, independent secrets, sockets/TLS, and restricted
   artifact-host database grants.
3. Prove one-worker admission, resource limits, actual no-network behavior,
   HMAC/replay/response checks, and timeout/process cleanup.
4. Run the doctor. XLSX must return the pinned profile/schema/engine and live
   containment result. DOCX must start the pinned converter and also pass the
   downstream PDF health challenge. Any stage failing blocks enablement.
5. Run hostile-format contracts, complete Chromium/Firefox/WebKit checks and
   the [released Safari/iOS pass](browser-checks.md), then close the final
   evidence-first security review and full required gates.

After local installer changes, exit the container and rerun `make up`, then
`make doctor`, so services receive the new flags and secrets.

The [XLSX](../architecture/xlsx-artifacts.md) and
[DOCX](../architecture/docx-artifacts.md) decisions define their accepted
profiles. The Office migration refuses rollback while either page type exists.
Before a downgrade, deliberately export/delete those artifacts and verify
their retention requirements. Never relabel originals or silently delete them
to make rollback succeed.
