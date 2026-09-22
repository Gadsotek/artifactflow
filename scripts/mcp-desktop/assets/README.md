# Extension icons

The 512 × 512 transparent PNGs use ArtifactFlow's existing logo geometry and
palette. `icon.png` is for light backgrounds; `icon-dark.png` is for dark ones.
The manifest supplies both themes and a default icon for older clients.

Canonical artwork:

- `public/brand/artifactflow-mark.svg` → `icon.svg` → `icon.png`
- `site/assets/artifactflow-mark-light.svg` → `icon-dark.svg` → `icon-dark.png`

The SVG copies are included as editable source. PNGs were rendered directly
from them at 512 pixels wide with `@resvg/resvg-js` 2.6.2 and no background fill.
The renderer is an authoring tool only; it is not a connector dependency.
Keep the PNGs in Git so building or installing the extension needs no image
renderer. Update both themes together when the canonical brand assets change.

The artwork is distributed under the repository's AGPL-3.0-or-later license.
