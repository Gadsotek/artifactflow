# Browser test matrix

Run browser tests only through `make e2e`; the wrapper supplies an isolated database, application
stack, and artifact origin.

- A second Laravel server inside the e2e app container uses Cloudflare's published always-pass
  credentials. The browser loads the real login and password-recovery pages and verifies their
  emitted CSP, nonce, action, challenge frame, and generated token. The normal e2e server receives
  blank Turnstile credentials. The runner therefore needs outbound HTTPS access to
  `https://challenges.cloudflare.com`. Server-side action and hostname validation stay in
  deterministic feature tests because Cloudflare's dummy Siteverify response hardcodes
  `action: test`; production rejects every published test credential. The auxiliary server defaults
  to host port `18182`; `E2E_TURNSTILE_APP_PORT` overrides the Compose mapping, Laravel `APP_URL`,
  and Playwright target together. Its container healthcheck requires both app servers to answer
  `/up` before the suite starts.
- Every Playwright test runs on Chromium.
- A test whose title includes `@artifact-security` also runs on Firefox and WebKit.
- Playwright WebKit is not released Safari or iOS. Keep the manual Safari/iOS pass in
  `docs/OPERATIONS.md`.

Add `@artifact-security` when the result depends on browser enforcement or engine-specific behavior
at the artifact boundary, including:

- CSP or iframe sandbox restrictions;
- application/artifact origin separation and cookie isolation;
- nested browsing contexts, document parsing, or browser networking APIs;
- Mermaid sanitization on the trusted application origin.

Ordinary editor, layout, and application-flow tests remain Chromium-only unless the feature has a
specific cross-engine compatibility requirement. Use explicit DOM or application readiness
assertions; do not use `networkidle`, which is unreliable in WebKit.
