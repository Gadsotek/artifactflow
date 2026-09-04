# SheetJS CE XLSX processor

This is ArtifactFlow's dedicated XLSX processor. It uses SheetJS CE `0.20.3`
behind a narrow typed-projection boundary for hostile `.xlsx` input. XLSX is a
default-off capability; the processor is a separate networkless service and is
never linked into the Laravel runtime or browser bundle.

Run the local contract tests with:

```sh
cd xlsx-processor-spike
npm ci --ignore-scripts
npm test
```

Build and run the containerized checks with:

```sh
docker build -f xlsx-processor-spike/Dockerfile --target xlsx-processor-spike \
  --tag artifactflow-xlsx-processor-spike:local xlsx-processor-spike
docker run --rm --network none --read-only --cap-drop ALL \
  --security-opt no-new-privileges --pids-limit 32 --memory 384m --cpus 1 \
  --tmpfs /tmp:rw,noexec,nosuid,size=64m \
  artifactflow-xlsx-processor-spike:local
```

The same Dockerfile exposes an `xlsx-processor-spike-service` target whose only
entry point is `node src/start-server.cjs`. It requires a dedicated shared
secret (raw text or canonical `base64:` encoding, decoded to the exact HMAC key
bytes used by Laravel) and an absolute `XLSX_PROCESSOR_SOCKET_PATH` ending in `.sock`; the
socket directory must be supplied by the deployment. No TCP listener is
configured. Docker Compose runs this target with `network_mode: none`, a
read-only root, dropped capabilities, `no-new-privileges`, and explicit
resource limits.

Production enablement additionally requires the matching application and
artifact-host configuration, private derivative storage, dedicated secret,
doctor checks, image scan, and operator verification described in
`docs/OPERATIONS.md`.

## Findings frozen by the spike

- SheetJS guesses non-XLSX formats, including CSV-like bytes. A strict ZIP/OPC
  preflight must run before `XLSX.read`.
- `cellHTML` is explicitly disabled, and no SheetJS HTML converter is used.
- Formula expressions are preserved but never evaluated. Cached results remain
  separate; a formula without a cache has an unknown result type and is emitted
  as `kind: formula` with `cachedResultAvailable: false`.
- Hidden and very-hidden sheets, hidden rows, and hidden columns are omitted
  from the typed manifest and flattened search text.
- Only sparse cell keys are traversed. A hostile worksheet dimension does not
  authorize range expansion.
- Hyperlinks are separately typed. The spike accepts normalized same-workbook,
  HTTP, HTTPS, and mailto destinations and fails closed on unsafe external
  schemes. Same-sheet targets and cell ranges normalize to a bounded visible
  cell; defined names, hidden/out-of-profile destinations, and other ambiguous
  internal targets are omitted from the derived manifest.
- ZIP accounting expands every admitted entry under the aggregate ceiling and
  verifies its actual byte count and CRC instead of trusting matching local and
  central directory claims. Standard 12-byte or signature-prefixed 16-byte data
  descriptors are accepted only when their CRC and sizes exactly match the
  central directory and local-record boundary. Only after full validation, the
  processor supplies SheetJS with a parser-only descriptor-free ZIP assembled
  from those exact validated compressed payloads; the retained original is not
  rewritten.
- Every XML/VML and relationship part is parsed as bounded UTF-8 XML 1.0 with DTD,
  processing-instruction, and CDATA rejection. Content types, part paths, and
  the reachable relationship graph must match the bounded passive admitted profile.
- The service accepts exactly `POST /v1/xlsx/manifests` over its configured
  Unix socket plus an authenticated `GET /health` challenge. Domain-separated
  HMACs bind exact request and response bytes, profile, schema, media types,
  nonce, input hash, and engine version. The health response is separately
  signed over the challenge nonce and exact JSON bytes, so a local intermediary
  cannot forge readiness. Health reports the exact live profile
  only after verifying that every visible network interface is loopback. A
  bounded replay cache rejects nonce reuse on both operations. Before binding
  the socket, processor startup runs one bounded child-process rejection probe
  so a cold SheetJS runtime cannot be advertised as ready prematurely. Startup
  and its container healthcheck normalize `base64:` secrets identically to the
  Laravel client and deployment doctor.
- Admission is locked before reading a request body, so only one parse can be
  active. Projection runs in a child process with a 15-second process-group timeout;
  the child inherits no caller environment and has no process or network API. The
  32-task cgroup limit remains bounded while allowing the listener, that one child,
  and Docker's authenticated Node health probe to overlap without exhausting Node's
  runtime threads.

The package gate admits bounded passive tables, charts, drawings, common images,
comments/VML, custom XML, pivots, connections, formatting, and similar internal
Open XML parts. It also admits the legacy worksheet custom-property binary only
at `xl/customPropertyN.bin`, with the exact SpreadsheetML content type and an
official internal worksheet relationship. SheetJS property parsing is disabled,
and those opaque bytes remain only in the exact private original. All optional
parts are omitted from preview, search, sharing, and MCP output. ActiveX, macros,
OLE/embedded packages, generic or externally targeted binary parts, web
extensions, external non-hyperlink relationships, attacker-defined relationship
types, and malformed XML remain rejected. The Laravel client independently
validates the authenticated response and complete manifest before it can be
stored or shown.
