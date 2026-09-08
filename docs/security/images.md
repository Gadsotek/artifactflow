# Image security

[Threat model](../../THREAT-MODEL.md) · [Processor deployment](../operations/processors.md)

PNG/JPEG uploads are hostile binary input. ArtifactFlow retains decoded,
re-encoded pixels and discards the uploaded container. It performs no OCR.

## Admission and normalization

| Layer | Enforced limits and checks |
| --- | --- |
| App boundary | Upload validity, extension/header agreement, PNG/JPEG envelope, compressed bytes, dimensions, pixels |
| PNG preflight | At most 1,024 chunks and 1 MiB ancillary data; CRCs, IHDR/palette rules, consecutive IDAT, exact scanline inflation budget including Adam7 |
| JPEG preflight | At most 256 pre-frame markers within the upload envelope; bounded length fields; reject restart markers before scan data |
| Shared admission | One non-blocking normalization slot across app replicas; per-principal and installation pixel/work budgets |
| Parser | One native worker, authenticated request, fixed ceilings, isolated GD/EXIF decode and same-format re-encode |
| App response validation | Exact authenticated bytes, format, dimensions, limits, and normalized envelope checked again before storage |

New uploads have a 16 Mi-pixel hard ceiling. Retained normalized versions stay
readable up to the historical 40 Mi-pixel envelope, so lowering an upload cap
does not invalidate history. The signed output-byte budget may exceed the
compressed input budget, within `ARTIFACT_MAX_BYTES`.

Before GD, PNG text/profile chunks are stripped and IDAT inflation must match
exactly the scanlines implied by IHDR. Early end, extra expansion, or invalid
filter bytes fail closed. Final image conformance still belongs to GD/libpng;
the bounded walker is not a complete decoder.

Input-work accounting charges compressed bytes, metadata bytes again, and
1 Ki work units per chunk/marker. Every dispatched attempt consumes the
reserved pixel and work budgets, including parser rejection. Only failures
proven to occur before dispatch are refunded. Busy shared admission returns
retryable 503 immediately; exhausted user budgets return 429.

An uncertain timeout/stream failure keeps the admission lease until its bounded
expiry. The parser also has a 15-second execution ceiling. Do not shorten the
lease or add prefork workers to hide busy responses.

## Processor boundary

Requests and responses use timestamped, nonce-bound HMACs with a dedicated
secret. The parser has no app source, database, artifact-storage mount, public
port, or outbound network route. Local deployment uses a Unix socket with
Docker `network_mode: none`. Cross-host transport requires encrypted private
transport and effective directional egress denial, not only an internal subnet.

The 512 MiB container runs non-root with a read-only root, no capabilities,
`no-new-privileges`, bounded CPU/PIDs, and no-exec temporary storage. It admits
one normalization process; extra replicas provide failover without increasing
the shared admission count. A new concurrency design requires adversarial
memory/CPU measurements.

GD/EXIF are absent from the production app image. Optional WebP is absent from
the parser. The health check proves a fixed one-pixel PNG decode/re-encode,
not just a listening port. App reads request identity encoding, reject encoded
responses, and stop after the signed output limit plus one sentinel byte.

## Storage, preview, and MCP

Only normalized output becomes an immutable version. EXIF/GPS, comments,
profiles, and appended payloads are discarded. Restore copies normalized bytes
exactly rather than introducing another lossy JPEG encoding.

The artifact host revalidates the raster and puts it in a fixed application-owned
viewer as a `data:` image. Both iframe and response CSP use an empty sandbox;
scripts, connections, and objects are denied. The viewer is iframe-only,
cookieless, and limited to the configured app ancestor.

Scriptless image frames load eagerly and do not emit HTML ready handshakes or
renew on a timer. Expiry/revocation closes future loads, not a displayed image.

Search uses metadata. MCP reads return a normalized image block beside an
untrusted-data envelope. Pixels can contain prompt injection; without OCR the
server does not inspect those instructions. A resulting description write
still requires update scope, live Editor authority, the observed content UID,
a fresh metadata revision, scanner success, and rate-limit budget.
MCP image create/replace also requires `mcp:upload` and the same normalization.

## Residuals

The parser is a long-running container, not a fresh VM for every upload.
Native-code, runtime, and kernel escapes remain possible. Keep image libraries
patched. HMAC proves integrity/authenticity, not confidentiality or safe pixels.
An authorized reader can retain already-delivered image data.
