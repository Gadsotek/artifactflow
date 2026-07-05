// Fails if the resolved DOMPurify version drifts from the pinned one.
//
// DOMPurify is the sanitizer that runs on the MAIN app origin over rendered
// Mermaid SVG (resources/js/mermaid-renderer.js), so its version is security
// relevant. It is not a direct dependency; mermaid pulls it in and package.json
// "overrides" pins the exact vetted version. A future `npm install` or lockfile
// regeneration could silently drop that override — this check, wired into
// `make audit` (audit-js), turns that into a loud failure.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const EXPECTED = '3.4.11';

// Read the installed manifest directly: the package restricts subpath access via
// "exports", so module resolution (require('dompurify/package.json')) is refused.
const manifestPath = fileURLToPath(new URL('../node_modules/dompurify/package.json', import.meta.url));

let version;
try {
  version = JSON.parse(readFileSync(manifestPath, 'utf8')).version;
} catch {
  console.error(`Could not read ${manifestPath}. Install dependencies (npm ci) first.`);
  process.exit(1);
}

if (version !== EXPECTED) {
  console.error(
    `DOMPurify resolved to ${version}, expected pinned ${EXPECTED}. ` +
      'Restore the package.json "overrides" pin or re-vet the new version; ' +
      'this dependency sanitizes Mermaid SVG on the app origin.',
  );
  process.exit(1);
}

console.log(`DOMPurify pinned at ${version}.`);
