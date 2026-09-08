# Threat model

ArtifactFlow stores work from people and AI clients. Treat every uploaded file,
HTML script, Markdown document, extracted string, image, and producer claim as
untrusted. Authentication permits an operation; it does not make its input safe.

The assets to protect are app credentials, private artifacts, workspace access,
retained history, and service availability. Attackers may be artifact authors,
authorized users exceeding their permissions, compromised MCP clients, or
anonymous visitors probing external shares.

## Boundaries at a glance

| Threat | Required control | What it does not promise |
| --- | --- | --- |
| Artifact HTML steals app authority | Separate cookieless host, opaque scripts-only iframe, restrictive HTTP header CSP | Perfect network isolation or safe content inside the frame |
| Nested frames or browser API gaps bypass restrictions | Server rewriting before parse, early synchronous DOM guard, cross-browser attack corpus | A complete HTML parser or an unbypassable guard |
| Markdown or Mermaid becomes app-origin script | Sanitized Markdown, strict Mermaid, bounded parsing, app CSP | That the text is true or free of social engineering |
| Binary input compromises a parser | Dedicated isolated processors, authenticated bounded requests/responses, one-worker admission | Immunity to native-code, container, or kernel flaws |
| A user or agent reads another workspace | Live server-side authorization, exact token scope, locked write rechecks | Confidentiality of intentionally discoverable human coworker identities |
| A stale link survives access loss | Short-lived revision-bound capabilities and live state checks | Erasing bytes already delivered |
| Content tricks an AI into writing | Untrusted-data envelopes plus independent scope, authority, concurrency, and scanner checks | Solving prompt injection in the client |
| An anonymous share broadens access | One-page expiring or one-time capability, separate window proof, live revocation | Recipient identity, anti-copy protection, or public publishing |

## Rendering by format

| Format | Retained bytes and presentation |
| --- | --- |
| HTML | Single-file source; executable only on the artifact origin under `sandbox="allow-scripts"` without `allow-same-origin` |
| Markdown + Mermaid | Source retained; sanitized/strict rendering in the app |
| PNG/JPEG | Only re-encoded pixels retained; fixed scriptless artifact-origin viewer with `sandbox=""` |
| XLSX | Exact private original; preview/search/MCP consume only a validated typed manifest |
| PDF | Validated private original; native viewing on the artifact origin |
| DOCX | Exact private original; preview/search use only an independently validated PDF derivative |

PDF and DOCX-PDF viewing are **download-equivalent**. Their native viewer is the
sole format-specific exception to iframe/CSP sandboxing. Separate origin,
restrictive PDF CSP, live authorization, and active-content rejection remain
required. Never extend that exception to HTML, images, or XLSX.

PDF, XLSX, and DOCX are default-off. Enabling DOCX also requires the independent
PDF processor. Use each format's deployment checklist before enabling it.

## Residual risks to understand

- **HTML self-navigation can still send artifact-controlled or user-entered data**
  to another site, together with IP/view timing. CSP does not fully prevent a
  scripted frame from navigating itself. Do not enter secrets into an artifact.
- **WebRTC blocking is browser-dependent.** Chromium and WebKit do not enforce
  `webrtc 'block'`. The maintained guard and parser corpus close known paths;
  they are not proof of a perfect network seal.
- **Delivery cannot be undone.** Revocation stops future authorized loads. It
  cannot erase displayed content, saved PDFs, downloaded originals, or copied
  external-view credentials.
- **Scans are advisory.** Obvious credential patterns block writes, but a clean
  scan is not proof that no secret or malicious instruction remains. Embedded
  PDF text may include visually hidden material and cannot certify redaction.
- **Parsers remain native attack surfaces.** Resource limits and containment
  reduce impact; shared-kernel escapes and denial of service within admitted
  budgets remain possible. Previews are not antivirus verdicts.
- **The host is trusted.** A compromised app host, database administrator,
  artifact-storage reader, browser extension, or device is outside content
  isolation guarantees. The artifact host can read private retained storage.
- **Presence revocation is bounded.** An already-subscribed, non-cooperative
  Reverb client may retain presence names/UIDs until its socket closes. It gets
  no page content and cannot resubscribe after access loss.
- **Optional services have tradeoffs.** Trusted-device cookies are bearer
  credentials. Turnstile adds Cloudflare data processing and an authentication
  availability dependency. The MCP bridge receives its token.

## Detailed contracts

| Read when changing or reviewing | Reference |
| --- | --- |
| HTML CSP, sandbox, parser guard, saved/draft capabilities | [HTML previews](docs/security/html-previews.md) |
| PNG/JPEG admission, decoding, storage, or viewing | [Images](docs/security/images.md) |
| Authorization, accounts, MCP, provenance, or sharing | [Access and agents](docs/security/access-and-agents.md) |
| Workspace inheritance or reparenting | [Nested workspaces](docs/architecture/nested-workspaces.md) |
| Document parsing or presentation | [PDF](docs/architecture/pdf-artifacts.md), [XLSX](docs/architecture/xlsx-artifacts.md), [DOCX](docs/architecture/docx-artifacts.md) |
| Anonymous page capabilities | [External sharing](docs/architecture/external-sharing.md) |

## Verification and reporting

PHP tests cover authorization, signing, parser contracts, races, and persistence.
The full browser suite runs on Chromium; `@artifact-security` also runs on
Firefox and WebKit. The parser differential corpus checks rewritten responses
without the runtime guard, so cleanup cannot hide a pre-parse failure.
Playwright WebKit does not replace the [released Safari/iOS pass](docs/operations/browser-checks.md).

Run the required [engineering gates](AGENTS.md#required-gates) and verify the
actual deployment's [processor containment](docs/operations/processors.md).
Passing CI is evidence for covered cases, not a proof of complete security.
ArtifactFlow has not received an independent third-party security audit.

Report vulnerabilities privately through [SECURITY.md](SECURITY.md).
