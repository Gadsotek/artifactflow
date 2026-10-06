import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import type { DOMPurify } from 'dompurify';

// GHSA-p98j-92pf-mc4p: verify the installed dependency itself, alongside the
// application renderer tests below (which do not use IN_PLACE sanitization).
const dompurifySource = readFileSync(
  new URL('../../node_modules/dompurify/dist/purify.js', import.meta.url),
  'utf8',
);

for (const hook of ['afterSanitizeElements', 'afterSanitizeAttributes'] as const) {
  test(`DOMPurify neutralizes descendants detached by ${hook} @artifact-security`, async ({
    page,
  }) => {
    await page.addScriptTag({ content: dompurifySource });

    const result = await page.evaluate((hookName) => {
      const purifier = (window as Window & { DOMPurify: DOMPurify }).DOMPurify;
      const root = document.createElement('div');
      const wrapper = document.createElement('section');
      const image = document.createElement('img');
      image.setAttribute('onerror', 'window.__dompurifyDetachedHandler = true');
      wrapper.appendChild(image);
      const safe = document.createElement('p');
      safe.textContent = 'Safe sibling';
      root.append(wrapper, safe);
      document.body.appendChild(root);

      purifier.addHook(hookName, (node) => {
        if (node === wrapper) {
          wrapper.remove();
        }
      });

      try {
        const sanitized = purifier.sanitize(root, { IN_PLACE: true });
        // Dispatch on the retained detached descendant: inspecting only the
        // returned tree would miss the armed handler described by the advisory.
        image.dispatchEvent(new Event('error'));

        return {
          sameRoot: sanitized === root,
          wrapperDetached: !wrapper.isConnected,
          handler: image.getAttribute('onerror'),
          executed: '__dompurifyDetachedHandler' in window,
          safeText: root.textContent,
        };
      } finally {
        purifier.removeAllHooks();
        root.remove();
      }
    }, hook);

    expect(result).toEqual({
      sameRoot: true,
      wrapperDetached: true,
      handler: null,
      executed: false,
      safeText: 'Safe sibling',
    });
  });
}

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
  source: ['graph TD', '  Start[Start] --> Middle[ProcessStep]', '  Middle --> End[Finished]'].join(
    '\n',
  ),
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

test('hostile Mermaid source neither executes nor escapes the strict renderer @artifact-security', async ({
  page,
}) => {
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

  await page.goto(`${baseUrl}/up`, { waitUntil: 'domcontentloaded' });
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
      for (const node of canvas.querySelectorAll(
        'script, foreignObject, iframe, object, embed, image, img',
      )) {
        dangerous.push(`node:${node.tagName.toLowerCase()}`);
      }

      for (const element of canvas.querySelectorAll('*')) {
        for (const attribute of element.attributes) {
          const name = attribute.name.toLowerCase();
          const value = attribute.value.trim().toLowerCase();

          if (name.startsWith('on')) {
            dangerous.push(`attr:${name}`);
          }

          if (
            (name === 'href' || name === 'xlink:href' || name === 'src') &&
            value !== '' &&
            !value.startsWith('#')
          ) {
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

  expect(
    verdict.dangerous,
    'sanitized SVG must contain no executable or external-reference nodes',
  ).toEqual([]);
  expect(verdict.pwned, 'no hostile payload may reach the main-origin window').toBe(false);
  expect(dialogs).toEqual([]);
  expect(consoleErrors).toEqual([]);
  expect(canaryRequests).toBe(0);
});

const supportedDiagrams = [
  { source: 'flowchart TD\n  Start --> Finished', labels: ['Start', 'Finished'] },
  { source: 'sequenceDiagram\n  Alice->>Bob: Message', labels: ['Alice', 'Message'] },
  { source: 'classDiagram\n  Animal <|-- Duck', labels: ['Animal', 'Duck'] },
  { source: 'stateDiagram-v2\n  Waiting --> Running', labels: ['Waiting', 'Running'] },
  {
    source: 'mindmap\n  root((Project))\n    Planning\n    Delivery',
    labels: ['Project', 'Delivery'],
  },
  {
    source:
      'architecture-beta\n  service api(server)[Gateway]\n  service db(database)[Storage]\n  api:R -- L:db',
    labels: ['Gateway', 'Storage'],
  },
  {
    source: '---\nconfig:\n  layout: elk\n---\nflowchart TD\n  Input --> Output',
    labels: ['Input', 'Output'],
  },
];

for (const dark of [false, true]) {
  test(
    'Mermaid 12 retains full diagram rendering in ' +
      (dark ? 'dark' : 'light') +
      ' mode @artifact-security',
    async ({ page }) => {
      const externalRequests: string[] = [];
      await page.route(
        () => true,
        async (route) => {
          if (new URL(route.request().url()).origin !== new URL(baseUrl).origin) {
            externalRequests.push(route.request().url());
            await route.abort();
            return;
          }
          await route.continue();
        },
      );
      await page.goto(baseUrl + '/up', { waitUntil: 'domcontentloaded' });
      await page.setContent(
        '<!doctype html><html class="' +
          (dark ? 'dark' : '') +
          '"><body>' +
          supportedDiagrams.map((diagram) => diagramBlock(diagram.source)).join('') +
          '<script type="module" src="' +
          appAsset +
          '"></script></body></html>',
      );

      const diagrams = page.locator('[data-mermaid-diagram]');
      await expect(diagrams).toHaveCount(supportedDiagrams.length);
      for (let index = 0; index < supportedDiagrams.length; index += 1) {
        const diagram = diagrams.nth(index);
        await expect(diagram, supportedDiagrams[index].source).toHaveAttribute(
          'data-mermaid-rendered',
          'true',
          { timeout: 20_000 },
        );
        // Architecture icons contain nested SVGs; count only the diagram root.
        await expect(diagram.locator('[data-mermaid-canvas] > svg')).toHaveCount(1);
        for (const label of supportedDiagrams[index].labels) {
          await expect(diagram.locator('[data-mermaid-canvas]')).toContainText(label);
        }
        await expect(
          diagram.locator(
            '[data-mermaid-canvas] script, [data-mermaid-canvas] foreignObject, [data-mermaid-canvas] image',
          ),
        ).toHaveCount(0);
      }
      expect(externalRequests).toEqual([]);
    },
  );
}
