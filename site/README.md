# site/

Static marketing/landing page for ArtifactFlow, published separately from the
application. `index.html` keeps its CSS and small gallery-preview script inline;
`assets/` holds the screenshots (also embedded in the repository README, so keep
this directory tracked). The page loads no remote runtime resources; network
access begins only when a visitor follows an external link.

`Staticfile` is an intentional zero-byte marker consumed by staticfile
buildpacks (Cloud Foundry / Heroku-style) so this directory can be deployed
as-is as a static site. It is not used by the application.
