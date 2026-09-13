# site/

Static marketing/landing page for ArtifactFlow, an open-source, self-hosted workspace for
AI-generated artifacts shared by teams and authorized AI clients. It is published separately from the application and deliberately
keeps the homepage focused on the product. The artifact-workflow, security, MCP, self-hosting,
roadmap, and engineering-harness subpages hold the deeper explanations. Three first-party
guides answer common discovery questions with links to the repository evidence. Each route is
an `index.html`, shared presentation lives in `assets/site.css`, the homepage composition
uses `assets/home.css`, the visual journey uses `assets/workflow.css`, and every page
loads the small `assets/theme.js` color-mode control before rendering. The homepage gallery and MCP reference load the deferred `assets/site.js` script. All content remains
usable without JavaScript, with the color mode falling back to the visitor's
system preference, and the site loads no remote runtime resources. Network
access begins only when a visitor follows an external link.

Typography uses system sans-serif fonts. Illustrations are native HTML/CSS/SVG;
motion respects reduced-motion preferences. Screenshot previews show the real product,
while the floating artifact cards are illustrative. No font downloads or animation library is required.

Canonical routes:

- `/`
- `/workflow/`
- `/security/`
- `/mcp/`
- `/self-hosting/`
- `/engineering-harness/`
- `/roadmap/`
- `/guides/ai-artifact-vault/`
- `/guides/safe-ai-generated-html/`
- `/guides/ai-artifact-storage/`

`robots.txt`, `sitemap.xml`, and `llms.txt` describe only this public marketing
origin. The authenticated application keeps its separate, intentionally
non-indexable `public/robots.txt` policy. `assets/` holds shared marks, the social
card, and responsive AVIF/JPEG screenshot variants (also used by the repository
README, so keep this directory tracked).

## Refresh product screenshots

Product screenshots use the fictional **Northstar Labs** team: Alex Morgan,
Jamie Chen, and Sam Rivera. The sample workspaces include Engineering, nested
Runbooks, Product design, and Operations. Pages show favorites, recent history,
versioned Markdown with Mermaid, and a working HTML sprint-capacity planner.
No real account, token, or production content is needed.

Capture through the isolated browser-test wrapper:

```sh
make build-assets
capture_run=$(date +%Y%m%d-%H%M%S)
E2E_MARKETING_CAPTURE=automatic \
E2E_GREP='marketing screenshots show' \
PLAYWRIGHT_OUTPUT_DIR="storage/framework/testing/marketing-results-$capture_run" \
PLAYWRIGHT_HTML_OUTPUT_DIR="storage/framework/testing/marketing-report-$capture_run" \
PLAYWRIGHT_HTML_OPEN=never make e2e
```

The fixture refuses to seed outside the uniquely named E2E database on `db-test`.
The wrapper drops that database and stops the dedicated services on exit.
Screenshots are written to `storage/framework/testing/marketing-capture/` at
1600 × 1000. Review them before replacing the published JPEG/AVIF assets; update
every responsive variant together so the site never mixes old and new UI.
The current set includes Home (`app-dashboard`), Library (`app-library`),
quick page search (`app-quick-navigation`), the interactive HTML planner
(`app-artifact-live`), and the Markdown incident guide (`app-markdown`). Each
has JPEG and AVIF versions at widths of 800, 1200, and 1600 pixels; the full-size
JPEG uses the base filename without a width suffix.
Home appears in the homepage hero. The Library, quick page search, HTML planner,
and Markdown guide appear in a two-column gallery that stacks on small screens;
every thumbnail opens the full-size image. The repository README shows Home and
offers the other four in an expandable walkthrough.

For a supervised capture with the provided in-app browser, use
`E2E_MARKETING_CAPTURE=manual E2E_GREP='prepare isolated marketing demo'` with the
same wrapper. It writes temporary demo login details to the ignored capture
directory and keeps the isolated services alive for up to 14 minutes. Create the
`storage/framework/testing/marketing-capture/complete` marker after capturing;
remove only that marker before the next session. This prepares a demo environment
and does not replace the automated browser/security checks.

`Staticfile` is an intentional zero-byte marker consumed by staticfile
buildpacks (Cloud Foundry / Heroku-style) so this directory can be deployed
as-is as a static site. It is not used by the application.
