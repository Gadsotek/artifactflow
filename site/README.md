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

`Staticfile` is an intentional zero-byte marker consumed by staticfile
buildpacks (Cloud Foundry / Heroku-style) so this directory can be deployed
as-is as a static site. It is not used by the application.
