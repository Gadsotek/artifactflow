# HTML preview security

[Threat model](../../THREAT-MODEL.md) · [Browser checks](../operations/browser-checks.md)

Single-file HTML is executable untrusted content. It may run inline scripts
inside its preview, but must never acquire app-origin authority.

## Three browser controls

1. **A separate hostname.** App cookies must not reach the artifact host. Ports
   do not isolate cookies; broad parent-domain cookies are also unsafe.
2. **An opaque iframe.** HTML uses `sandbox="allow-scripts"`, without
   `allow-same-origin`, top navigation, forms, popups, or download permission.
3. **A response-header CSP.** The artifact response carries its own policy,
   including `sandbox` and `frame-ancestors`. Meta CSP cannot provide those
   directives; the iframe `csp` attribute is not a portable boundary.

The policy allows inline script/style and bounded self-contained data resources.
It denies ordinary connections, objects, base URLs, forms, frames, workers,
and external scripts. `ArtifactSandboxResponder` owns the delivered headers.
Proxies must preserve them and keep `Set-Cookie` absent, including errors.

The app separately uses nonce-based script/style CSP, denies framing, and
limits artifact frames/forms to the configured artifact origin. Optional
Turnstile sources apply only to the three authentication GET surfaces.
This is defense in depth, not a promise that app-origin injection is harmless.

## Server rewriting and the early guard

These controls enforce the supported HTML profile in addition to browser
isolation. They do not replace it.

| Unsupported path | Required behavior |
| --- | --- |
| Static `iframe`, `frame`, `fencedframe`, `portal` | Neutralize before browser parsing |
| Declarative shadow roots | Rename static `shadowrootmode` before a hidden tree can form |
| Dynamic element creation and HTML parsing | Guard synchronous sinks before native insertion |
| DNS/prefetch/preconnect hints and refresh metas | Remove static targets, normalize browser-equivalent character references, intercept later attribute/property mutations |
| Top-realm WebRTC | Block constructors in the early guard where browsers ignore the CSP directive |
| Fullscreen/pointer lock | Deny artifact API use; the app control only CSS-maximizes the existing iframe |

Guarded sinks include HTML setters, `insertAdjacentHTML`, `setHTMLUnsafe`,
`DOMParser`, `Range`, shadow roots, document writes, and legacy
`document.execCommand('insertHTML')`. Stateful string arguments are coerced
once, inspected, then passed to the native sink as that same primitive.
MutationObserver removal is a fallback: it can run after network work or child
script execution has already started.

The response rewriter is a partial, maintained tokenizer/tree-builder model.
It must preserve genuine script/text-control bytes while handling foreign
SVG/MathML content, raw-text transitions, select/frameset modes, comments,
CDATA differences, and numeric references with optional semicolons.
Known-case tests cannot establish completeness.

Nested `srcdoc` or initial `about:blank` realms can exist despite `frame-src
'none'`. A fresh child also bypasses its parent's patched APIs. Chromium and
WebKit ignore `webrtc 'block'`, so preventing the child before parse is
required. Do not credit Permissions Policy or a late observer with closing it.

Artifact responses also send `X-DNS-Prefetch-Control: off`. Browser handling
of resource hints is not consistently governed by `connect-src`.

## Saved preview capabilities

The app authorizes access and signs the configured artifact origin, exact page
and version, purpose, expiry, and access revision with the dedicated
`ARTIFACT_URL_SIGNING_KEY`. It must differ from `APP_KEY`. The default and hard
maximum lifetime are 60 seconds. Current and history purposes are distinct.

The artifact host rechecks signature, expiry, role, live page/version state,
access revision, and private-file integrity before serving bytes. A saved URL
is a bearer capability, not bound to the user's session. Invalid capabilities
receive uniform unavailable responses.

Grants, membership/role changes, moves, access changes, and archival invalidate
affected revisions. This closes old loads, not already-delivered content.
Archival is lifecycle state: a still-authorized internal user may obtain a new
preview, while an anonymous share fails closed.

Modern top-level HTML requests are refused using `Sec-Fetch-Dest`. Saved HTML
permits a missing header for compatibility; header CSP still applies in that
case. Missing fetch metadata is not proof of a legacy browser and must not be
treated as an additional security guarantee.

There is no periodic parent-page reload. Scripted previews use a per-load
ready handshake. If a later expired iframe load fails that handshake, the
parent requests a fresh URL under live authorization and changes only that
iframe. Unsaved editor state must remain intact.

## Unsaved draft capabilities

The authenticated app endpoint `POST /pages/draft-preview-capabilities`
requires CSRF, rate-limit budget, and create authority in the chosen workspace.
It signs purpose/version, configured artifact origin, workspace, expiry,
nonce, exact UTF-8 byte length, and SHA-256. The app receives binding facts,
not the draft HTML body.

The browser submits the capability and exact HTML to the stateless artifact
receiver at `POST /artifact-previews/draft`. That receiver requires explicit
`Sec-Fetch-Dest: iframe`, verifies bounded token grammar and HMAC before claim
decoding, checks canonical claims and exact content binding, then responds
without storing a version. Signature comparison uses `hash_equals`.

The capability lasts at most 60 seconds. Exact-byte replay during that window
is allowed; changing bytes, line endings, or normalization is not. Membership
revocation after issuance does not retroactively revoke this already-issued
short-lived draft capability.

Never replace this path with app-origin `blob:`, `data:`, `srcdoc`, a meta CSP,
or a reused application nonce.

## Residuals and evidence

Scripts can still navigate their own frame and send embedded or user-entered
data, IP, and view timing to a destination. An artifact may also deceive users,
consume local CPU, or draw misleading content. Separate-origin controls limit
what it can read; they do not make all content safe or prevent every egress path.

Maintained browser fixtures cover parser differentials, recursive child realms,
closed roots, network-hint mutation, coercion races, UDP STUN and TCP collectors,
cookies, signed access, and parent-state preservation. Some historical defects
created nested contexts without crossing origin isolation; bounded egress is
still a defect and must not be dismissed as harmless.

Relevant implementation: [responder](../../app/Http/Support/ArtifactSandboxResponder.php),
[document guard](../../app/Application/PageCatalog/ArtifactPreviewDocumentGuard.php),
[saved preview tests](../../tests/e2e/saved-artifact-preview.spec.ts), and
[guard-free differential corpus](../../tests/e2e/artifact-parser-differential-fuzz.spec.ts).
Run `make fuzz-capabilities` and the affected `make e2e` corpus when this
boundary changes, plus the full required gates before commit.
