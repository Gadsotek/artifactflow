# Browser test matrix

Run browser tests only through `make e2e`; the wrapper supplies an isolated database, application
stack, and artifact origin.

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
