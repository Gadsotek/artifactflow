# site/

Static marketing/landing page for ArtifactFlow, a self-hosted, versioned artifact vault for tools
and documents created with AI. It is published separately from the application and deliberately
keeps the homepage focused on the product. The artifact-workflow, security, MCP, self-hosting,
roadmap, and engineering-harness subpages hold the deeper explanations. Each route is an
`index.html`, shared presentation lives in `assets/site.css`, and every page
loads the small `assets/theme.js` color-mode control before rendering. Only the
homepage gallery loads the deferred `assets/site.js` script. All content remains
usable without JavaScript, with the color mode falling back to the visitor's
system preference, and the site loads no remote runtime resources. Network
access begins only when a visitor follows an external link.

Canonical routes:

- `/`
- `/workflow/`
- `/security/`
- `/mcp/`
- `/self-hosting/`
- `/engineering-harness/`
- `/roadmap/`

`robots.txt`, `sitemap.xml`, and `llms.txt` describe only this public marketing
origin. The authenticated application keeps its separate, intentionally
non-indexable `public/robots.txt` policy. `assets/` holds shared marks, the social
card, and responsive AVIF/JPEG screenshot variants (also used by the repository
README, so keep this directory tracked).

`Staticfile` is an intentional zero-byte marker consumed by staticfile
buildpacks (Cloud Foundry / Heroku-style) so this directory can be deployed
as-is as a static site. It is not used by the application.
