# Safari and iOS security checks

[Operations](../OPERATIONS.md)

Automated E2E runs the full suite on Chromium and the artifact security corpus on Firefox and
Playwright WebKit. Playwright WebKit is not released Safari and cannot reproduce every macOS/iOS
integration detail, so an occasional run in released Safari still matters. Before a
security-sensitive release, and after changing artifact CSP,
iframe sandbox flags, preview routing, fullscreen behavior, or browser-facing guard code; exercise
current macOS Safari plus a physical iPhone or iPad Safari. Use a test/staging deployment with real
TLS and genuinely distinct app/artifact hostnames; an iOS device cannot validate the production
origin boundary through the desktop-only `localhost`/`127.0.0.1` fixture.

Use non-sensitive test content and record the Safari/iOS versions and results:

1. Load both saved and draft malicious fixtures. Confirm the artifact request goes only to the
   artifact hostname, carries no app session cookie, and receives the complete header CSP,
   `no-store`, and `nosniff` headers.
2. Mutate one byte, newline style, Unicode normalization, and trailing whitespace after capability
   issuance. Each changed draft must receive the same not-found response; the exact original may
   replay only during its short TTL.
3. Attempt static and dynamic `iframe`/`frame`/`fencedframe`/`portal`, legacy
   `document.execCommand('insertHTML')`, `<object>`, `<embed>`, SVG `foreignObject`, worker, popup,
   download, form, and external-network paths. Include SVG/MathML `plaintext`, SVG `script`, and
   scripting-enabled HTML `noscript` parser breakouts inside both open and closed declarative
   shadow roots. Insert a benign `<link>` first and then mutate
   `rel`/`href` through properties, `setAttribute`, `setAttributeNS`, and `relList` to
   `dns-prefetch`, `preconnect`, `prefetch`, and `prerender`; repeat the ordering check with
   `<meta http-equiv="refresh">`. Also load static `rel="&#112reconnect"` and
   `http-equiv="&#114efresh"` payloads without trailing semicolons, and confirm the delivered
   response no longer contains their targets before checking that neither a TCP connection nor
   frame navigation occurs. Repeat the string arguments with stateful `toString()` objects
   that return a safe value during guard inspection and a dangerous value on a second coercion,
   including `document.execCommand` command names. The elements must be neutralized synchronously,
   the response must carry `X-DNS-Prefetch-Control: off`, and no nested browsing context,
   popup/download, form submission, worker, DNS lookup, or outbound connection should succeed.
4. Attempt `requestFullscreen()` and `requestPointerLock()` from artifact code. They must be denied
   or unavailable; on iOS, absence of pointer-lock support is expected. Then use ArtifactFlow's
   Fullscreen control and confirm it only CSS-maximizes the existing sandboxed iframe and exits
   cleanly without navigating or replacing the application document.
5. Open or paste a saved signed URL as a top-level document and attempt the equivalent draft POST.
   Modern Safari should receive the refusal notice rather than rendered artifact code. Also verify
   that a parent-page `<meta>` CSP cannot relax the artifact response's header policy.
6. Revoke access, archive the page, and move it between workspaces while a preview is open. Reloading
   the old URL must fail and renewal must require live access. Archive is a lifecycle state rather
   than access revocation, so a viewer who still has live page access may receive a new revision-bound
   URL; a revoked viewer may not. Already-rendered bytes remaining on screen are the documented
   non-revocable browser-delivery residual.
7. Open one-time and expiring external-share links both signed out and while an ArtifactFlow login
   session exists. Confirm the fragment is removed immediately and its secret never appears in a
   request URL, referrer, cookie, page markup, or artifact-preview URL. A one-time link must remain
   unconsumed at the confirmation screen, enter one window-lived viewer session only after the
   explicit open action, survive a reload in that window, and show the same unavailable state in a
   second window or browser.
8. Exercise external Markdown, HTML, and image shares. Confirm private wiki links are inert text,
   HTML remains in an opaque `sandbox="allow-scripts"` artifact-origin frame, and images remain in
   a scriptless `sandbox=""` frame. Revoke the share, disable installation-wide external sharing,
   archive the page, and move or access-invalidate it; each subsequent viewer reload and preview-URL
   renewal must fail without disclosing the reason.
9. Upload non-sensitive XLSX and DOCX fixtures. Confirm XLSX receives only the
   application-owned typed grid in an opaque `allow-scripts` sandbox with no
   popup capability. Clicking an external cell link must open a destination-visible
   app-origin confirmation first; only the second click may open a tab, with no
   opener/referrer or parent-navigation authority. Confirm formulas are not evaluated,
   and no original workbook bytes reach the frame. Confirm DOCX receives only a
   derived `application/pdf` response on the artifact origin, not ZIP/DOCX or
   converted HTML, and that external hyperlinks in the original are inert visible
   text in the preview while bounded internal links may remain. Exercise exact-original downloads only while authenticated,
   then repeat revocation, expiry, feature-disablement, and external-share checks
   for both formats. Record whether Safari/iOS displays the native PDF inline or
   chooses an explicit download; either path must remain on the artifact origin
   with no app cookie.

Any divergence is a release blocker until it is reproduced, added to the automated corpus where
possible, and reflected in [the threat model](../../THREAT-MODEL.md).
