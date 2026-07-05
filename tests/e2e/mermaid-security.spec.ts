import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

type ManifestEntry = {
  file: string;
};

const manifest = JSON.parse(
  readFileSync(new URL('../../public/build/manifest.json', import.meta.url), 'utf8'),
) as Record<string, ManifestEntry>;

const baseUrl = (process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:18180').replace(/\/$/u, '');
const appAsset = `${baseUrl}/build/${manifest['resources/js/app.js'].file}`;

// Behavioral proof that hostile Mermaid source cannot execute or exfiltrate on
// the main origin: the diagrams below go through the REAL renderer pipeline
// (app.js -> mermaid strict mode -> safeSvg sanitizer), not a string-matched
// copy of its configuration.
const hostileDiagrams = [
  {
    label: 'click callback and javascript href',
    source: [
      'graph TD',
      '  A[Click me]',
      '  B[Nav]',
      '  click A callback "window.__mermaidPwned = true"',
      `  click B href "javascript:window.__mermaidPwned = true"`,
    ].join('\n'),
  },
  {
    label: 'html label with onerror payload',
    source: [
      'graph TD',
      `  A["<img src=x onerror=window.__mermaidPwned=true>"]`,
      `  B["<script>window.__mermaidPwned = true</script>"]`,
    ].join('\n'),
  },
  {
    label: 'init directive downgrade attempt',
    source: [
      `%%{init: {"securityLevel": "loose", "htmlLabels": true, "flowchart": {"htmlLabels": true}}}%%`,
      'graph TD',
      `  A["<img src=x onerror=window.__mermaidPwned=true>"]`,
      `  click A href "${baseUrl}/mermaid-canary?via=click"`,
    ].join('\n'),
  },
  {
    label: 'external image and network fetch attempt',
    source: [
      'graph TD',
      '  A[Fetch]',
      `  click A call fetch("${baseUrl}/mermaid-canary?via=call")`,
    ].join('\n'),
  },
];

// A benign diagram that MUST render successfully. Without it the hostile cases
// above pass vacuously: a renderer that fell over (asset missing, mermaid throw)
// would settle every diagram to 'error', emit no nodes, and satisfy every "no
// payload executed / no dangerous node" assertion while proving nothing. This
// control forces the pipeline to prove it actually renders sanitized SVG.
const controlDiagram = {
  label: 'benign control diagram',
  source: ['graph TD', '  Start[Start] --> Middle[ProcessStep]', '  Middle --> End[Finished]'].join('\n'),
};

function diagramBlock(source: string, attrs = ''): string {
  const escaped = source
    .replaceAll('&', '&amp;')
    .replaceAll('"', '&quot;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;');

  return `
    <div data-mermaid-diagram data-mermaid-source="${escaped}"${attrs === '' ? '' : ` ${attrs}`}>
      <div data-mermaid-canvas></div>
    </div>
  `;
}

test('hostile Mermaid source neither executes nor escapes the strict renderer', async ({ page }) => {
  const dialogs: string[] = [];
  const consoleErrors: string[] = [];
  let canaryRequests = 0;

  page.on('dialog', (dialog) => {
    dialogs.push(dialog.message());
    void dialog.dismiss();
  });
  page.on('console', (message) => {
    if (message.text().includes('mermaidPwned')) {
      consoleErrors.push(message.text());
    }
  });
  await page.route('**/mermaid-canary**', async (route) => {
    canaryRequests += 1;
    await route.abort();
  });

  await page.goto(`${baseUrl}/up`, { waitUntil: 'networkidle' });
  await page.setContent(`
    <!doctype html>
    <html>
      <body>
        ${hostileDiagrams.map((diagram) => diagramBlock(diagram.source)).join('\n')}
        ${diagramBlock(controlDiagram.source, 'data-mermaid-control')}
        <script type="module" src="${appAsset}"></script>
      </body>
    </html>
  `);

  // Every hostile diagram must settle: rendered sanitized, or refused outright.
  const diagrams = page.locator('[data-mermaid-diagram]:not([data-mermaid-control])');
  await expect(diagrams).toHaveCount(hostileDiagrams.length);

  for (let index = 0; index < hostileDiagrams.length; index += 1) {
    await expect(diagrams.nth(index), hostileDiagrams[index].label).toHaveAttribute(
      'data-mermaid-rendered',
      /^(true|error)$/u,
      { timeout: 20_000 },
    );
  }

  // The control proves the renderer is genuinely alive: it must render (never
  // 'error') and emit real SVG carrying its node labels. If this fails, the
  // hostile assertions above are meaningless and the test fails loudly instead
  // of passing green against a dead pipeline.
  const control = page.locator('[data-mermaid-control]');
  await expect(control, controlDiagram.label).toHaveAttribute('data-mermaid-rendered', 'true', {
    timeout: 20_000,
  });
  await expect(control.locator('[data-mermaid-canvas] svg')).toHaveCount(1);
  await expect(control.locator('[data-mermaid-canvas]')).toContainText('ProcessStep');

  // Give any delayed payload (onerror, callbacks, timers) a moment to fire.
  await page.waitForTimeout(500);

  const verdict = await page.evaluate(() => {
    const dangerous: string[] = [];

    for (const canvas of document.querySelectorAll('[data-mermaid-canvas]')) {
      for (const node of canvas.querySelectorAll('script, foreignObject, iframe, object, embed, image, img')) {
        dangerous.push(`node:${node.tagName.toLowerCase()}`);
      }

      for (const element of canvas.querySelectorAll('*')) {
        for (const attribute of element.attributes) {
          const name = attribute.name.toLowerCase();
          const value = attribute.value.trim().toLowerCase();

          if (name.startsWith('on')) {
            dangerous.push(`attr:${name}`);
          }

          if ((name === 'href' || name === 'xlink:href' || name === 'src') && value !== '' && !value.startsWith('#')) {
            dangerous.push(`ref:${name}=${value}`);
          }
        }
      }
    }

    return {
      dangerous,
      pwned: '__mermaidPwned' in window,
    };
  });

  expect(verdict.dangerous, 'sanitized SVG must contain no executable or external-reference nodes').toEqual([]);
  expect(verdict.pwned, 'no hostile payload may reach the main-origin window').toBe(false);
  expect(dialogs).toEqual([]);
  expect(consoleErrors).toEqual([]);
  expect(canaryRequests).toBe(0);
});
