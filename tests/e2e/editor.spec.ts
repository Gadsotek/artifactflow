import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { createSocket } from 'node:dgram';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

type ManifestEntry = {
  file: string;
};

type AuthenticatedDraftPreviewFixture = {
  cspNonce: string;
  csrfToken: string;
  workspaceUid: string;
};

const manifest = JSON.parse(
  readFileSync(new URL('../../public/build/manifest.json', import.meta.url), 'utf8'),
) as Record<string, ManifestEntry>;

const baseUrl = (process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:18180').replace(/\/$/u, '');
const cssAsset = `${baseUrl}/build/${manifest['resources/css/app.css'].file}`;
const appAsset = `${baseUrl}/build/${manifest['resources/js/app.js'].file}`;
const editorAsset = `${baseUrl}/build/${manifest['resources/js/content-editor.js'].file}`;
const htmlDraftPreviewAsset = `${baseUrl}/build/${manifest['resources/js/html-draft-preview.js'].file}`;
const artifactBaseUrl = (process.env.E2E_ARTIFACT_URL ?? 'http://127.0.0.1:18181').replace(
  /\/$/u,
  '',
);
const draftPreviewEndpoint = `${artifactBaseUrl}/artifact-previews/draft`;
const draftPreviewFrameName = 'artifactflow-html-draft-preview';
const draftPreviewCapabilityEndpoint = `${baseUrl}/pages/draft-preview-capabilities`;
const repoRoot = fileURLToPath(new URL('../..', import.meta.url));
const appCommandTarget = process.env.E2E_APP_COMMAND_TARGET ?? 'run-e2e-app-cmd';

function escapeHtmlAttribute(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('"', '&quot;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;');
}

function runAppCommand(appCommand: string, failureMessage: string): void {
  if (!['run-e2e-app-cmd', 'run-app-cmd'].includes(appCommandTarget)) {
    throw new Error('Unsupported e2e app command target.');
  }

  try {
    execFileSync('make', [appCommandTarget, `APP_CMD=${appCommand}`], {
      cwd: repoRoot,
      stdio: 'ignore',
    });
  } catch {
    throw new Error(failureMessage);
  }
}

async function prepareAuthenticatedDraftPreviewFixture(
  page: Page,
): Promise<AuthenticatedDraftPreviewFixture> {
  const runSuffix = randomUUID().replaceAll('-', '').slice(0, 12);
  const email = `draft-preview-e2e-${runSuffix}@example.test`;
  const password = `af${randomUUID().replaceAll('-', '')}`;

  runAppCommand(
    `php artisan artifactflow:create-user --name=DraftPreviewE2E --email=${email} --password=${password}`,
    'Failed to prepare the draft preview e2e account.',
  );

  await page.goto(`${baseUrl}/login`, { waitUntil: 'networkidle' });
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/dashboard$/u);

  await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'networkidle' });
  const createForm = page.locator('[data-html-draft-preview-form]');
  const csrfToken = await createForm.locator('input[name="_token"]').inputValue();
  const workspaceUid = await createForm.locator('select[name="workspace_uid"]').inputValue();

  // Start the synthetic fixture in a fresh document. The real create page has
  // already imported the preview module; ES modules execute once per document,
  // so setContent() in that same document would not initialise the replacement form.
  const fixtureResponse = await page.goto(`${baseUrl}/up`, { waitUntil: 'networkidle' });
  const csp = fixtureResponse?.headers()['content-security-policy'] ?? '';
  const cspNonce = /'nonce-([^']+)'/u.exec(csp)?.[1] ?? '';

  expect(cspNonce).not.toBe('');
  expect(csrfToken).not.toBe('');
  expect(workspaceUid).not.toBe('');

  return { cspNonce, csrfToken, workspaceUid };
}

function authenticatedDraftPreviewDocument(
  fixture: AuthenticatedDraftPreviewFixture,
  artifactHtml: string,
): string {
  return `
    <!doctype html>
    <html>
      <body>
        <form data-content-editor data-editor-language="html" data-html-draft-preview-form data-html-draft-preview-capability-endpoint="${draftPreviewCapabilityEndpoint}" data-html-draft-preview-endpoint="${draftPreviewEndpoint}">
          <input name="_token" type="hidden" value="${fixture.csrfToken}">
          <select name="workspace_uid"><option value="${fixture.workspaceUid}" selected>Workspace</option></select>
          <select name="type"><option value="html_artifact" selected>HTML artifact</option></select>
          <select name="mode"><option value="html_paste" selected>Paste HTML</option></select>
          <div data-source-editor-mount></div>
          <textarea data-editor-textarea>${escapeHtmlAttribute(artifactHtml)}</textarea>
          <span data-editor-status></span>
          <span data-editor-count></span>
          <section data-html-draft-preview>
            <button data-html-draft-preview-button type="button">Preview HTML before saving</button>
            <span data-html-draft-preview-status aria-live="polite"></span>
            <iframe data-html-draft-preview-frame name="${draftPreviewFrameName}" sandbox="allow-scripts" allow="" referrerpolicy="no-referrer"></iframe>
          </section>
        </form>
        <script nonce="${fixture.cspNonce}" type="module" src="${appAsset}"></script>
      </body>
    </html>
  `;
}

async function openAuthenticatedDraftPreview(page: Page): Promise<void> {
  await expect(page.locator('[data-html-draft-preview-form]')).toHaveAttribute(
    'data-html-draft-preview-ready',
    'true',
  );

  const capabilityResponsePromise = page.waitForResponse(
    (response) => response.url() === draftPreviewCapabilityEndpoint,
  );
  const draftResponsePromise = page.waitForResponse(
    (response) => response.url() === draftPreviewEndpoint,
  );

  await page.getByRole('button', { name: 'Preview HTML before saving' }).click();

  const [capabilityResponse, draftResponse] = await Promise.all([
    capabilityResponsePromise,
    draftResponsePromise,
  ]);
  expect(capabilityResponse.status()).toBe(200);
  expect(draftResponse.status()).toBe(200);
}

async function loadAppOriginCspNonce(page: Page): Promise<string> {
  const response = await page.goto(`${baseUrl}/up`, { waitUntil: 'networkidle' });
  const csp = response?.headers()['content-security-policy'] ?? '';
  const nonce = /'nonce-([^']+)'/u.exec(csp)?.[1] ?? '';

  expect(nonce).not.toBe('');

  return nonce;
}

async function loadEditorFixture(
  page: Page,
  language: 'html' | 'markdown',
  markdownHtml = '<p>Bold words</p>',
  markdownSource = 'Bold words',
) {
  const richEditor =
    language === 'markdown'
      ? `<div class="artifactflow-markdown artifactflow-rich-editor" contenteditable="true" data-rich-markdown-editor aria-label="Page content">${markdownHtml}</div>`
      : '';
  const toolbar =
    language === 'markdown'
      ? `
      <div data-editor-view-switch>
        <button data-editor-view-button data-editor-view="rich" type="button">Rich editor</button>
        <button data-editor-view-button data-editor-view="source" type="button">Markdown source</button>
      </div>
      <div class="artifactflow-markdown-toolbar" data-markdown-toolbar role="toolbar" aria-label="Markdown formatting">
        <label class="artifactflow-editor-control">
          <span>Block</span>
          <select data-editor-block-style aria-label="Block style">
            <option value="p">Paragraph</option>
            <option value="h1">Heading 1</option>
            <option value="h2">Heading 2</option>
            <option value="h3">Heading 3</option>
            <option value="h4">Heading 4</option>
            <option value="h5">Heading 5</option>
            <option value="h6">Heading 6</option>
          </select>
        </label>
        <div class="artifactflow-editor-tool-group" aria-label="Text formatting">
          <button class="artifactflow-editor-tool" data-editor-action="bold" type="button">Bold</button>
          <button class="artifactflow-editor-tool" data-editor-action="italic" type="button">Italic</button>
          <button class="artifactflow-editor-tool" data-editor-action="link" type="button">Link</button>
        </div>
        <div class="artifactflow-editor-tool-group" aria-label="Block formatting">
          <button class="artifactflow-editor-tool" data-editor-action="unordered-list" type="button">Bulleted list</button>
          <button class="artifactflow-editor-tool" data-editor-action="ordered-list" type="button">Numbered list</button>
          <button class="artifactflow-editor-tool" data-editor-action="blockquote" type="button">Quote</button>
          <button class="artifactflow-editor-tool" data-editor-action="horizontal-rule" type="button">Divider</button>
        </div>
        <div class="artifactflow-editor-tool-group" aria-label="Insert blocks">
          <button class="artifactflow-editor-tool" data-editor-action="code-block" type="button">Code block</button>
          <button class="artifactflow-editor-tool" data-editor-action="mermaid" type="button">Mermaid diagram</button>
        </div>
      </div>
    `
      : '';

  await page.goto(`${baseUrl}/up`, { waitUntil: 'networkidle' });
  await page.setContent(`
    <!doctype html>
    <html class="dark" data-theme="dark">
      <head>
        <link rel="stylesheet" href="${cssAsset}">
      </head>
      <body>
        <form data-content-editor data-editor-language="${language}">
          ${toolbar}
          ${richEditor}
          <div class="artifactflow-editor-shell" data-source-editor-mount></div>
          <textarea data-editor-textarea>${language === 'markdown' ? markdownSource : '<h1>Artifact</h1>'}</textarea>
          <span data-editor-status>Editor ready</span>
          <span data-editor-count></span>
        </form>
        <script type="module" src="${editorAsset}"></script>
      </body>
    </html>
  `);

  await expect(page.locator('[data-content-editor]')).toHaveAttribute('data-editor-ready', 'true');
}

test('dark variants follow the resolved theme class instead of the operating system alone', async ({
  page,
}) => {
  await page.emulateMedia({ colorScheme: 'dark' });
  await page.setContent(`
    <!doctype html>
    <html data-theme="light">
      <head>
        <link rel="stylesheet" href="${cssAsset}">
      </head>
      <body class="bg-white dark:bg-zinc-950">
        <div data-theme-probe class="bg-white dark:bg-zinc-900"></div>
      </body>
    </html>
  `);

  const probe = page.locator('[data-theme-probe]');

  await expect
    .poll(() => probe.evaluate((element) => getComputedStyle(element).backgroundColor))
    .toBe('rgb(255, 255, 255)');

  await page.evaluate(() => document.documentElement.classList.add('dark'));

  await expect
    .poll(() => probe.evaluate((element) => getComputedStyle(element).backgroundColor))
    .not.toBe('rgb(255, 255, 255)');
});

test('Markdown toolbar applies heading and block formatting while preserving Markdown source', async ({
  page,
}) => {
  await loadEditorFixture(page, 'markdown');

  await expect
    .poll(() =>
      page.getByLabel('Block style').evaluate((select) => {
        const selectStyle = getComputedStyle(select);
        const control = select.closest('.artifactflow-editor-control');
        const controlStyle = control === null ? null : getComputedStyle(control);
        const selectBox = select.getBoundingClientRect();
        const controlBox = control?.getBoundingClientRect();

        return {
          controlBorder: controlStyle?.borderTopWidth ?? '',
          controlHeight: Math.round(controlBox?.height ?? 0),
          selectBackground: selectStyle.backgroundColor,
          selectBorder: selectStyle.borderTopWidth,
          selectHeight: Math.round(selectBox.height),
        };
      }),
    )
    .toEqual({
      controlBorder: '0px',
      controlHeight: 34,
      selectBackground: 'rgb(39, 39, 42)',
      selectBorder: '1px',
      selectHeight: 34,
    });

  const editor = page.getByRole('textbox', { name: 'Page content' });
  await editor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.getByLabel('Block style').selectOption('h3');

  await expect(editor.getByRole('heading', { level: 3, name: 'Bold words' })).toBeVisible();
  await expect(page.locator('[data-editor-textarea]')).toHaveValue('### Bold words');

  await page.keyboard.press('ControlOrMeta+A');
  await page.getByRole('button', { name: 'Quote' }).click();
  await expect(editor.locator('blockquote')).toContainText('Bold words');
  await expect(page.locator('[data-editor-textarea]')).toHaveValue('> Bold words');

  await page.keyboard.press('ControlOrMeta+A');
  await page.getByRole('button', { name: 'Bulleted list' }).click();
  await expect(editor.locator('ul li')).toContainText('Bold words');
  await expect(page.locator('[data-editor-textarea]')).toHaveValue('- Bold words');
});

test('Markdown inline toolbar formats text without deprecated browser editing commands', async ({
  page,
}) => {
  await loadEditorFixture(page, 'markdown');

  const editor = page.getByRole('textbox', { name: 'Page content' });
  const textarea = page.locator('[data-editor-textarea]');

  await editor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.getByRole('button', { name: 'Bold' }).click();
  await expect(editor.locator('strong')).toHaveText('Bold words');
  await expect(textarea).toHaveValue('**Bold words**');

  await editor.locator('strong').evaluate((element) => {
    const selection = window.getSelection();
    const range = document.createRange();
    range.selectNodeContents(element);
    selection?.removeAllRanges();
    selection?.addRange(range);
  });
  await page.getByRole('button', { name: 'Bold' }).click();
  await expect(editor.locator('strong')).toHaveCount(0);
  await expect(textarea).toHaveValue('Bold words');

  await loadEditorFixture(page, 'markdown');
  await editor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.getByRole('button', { name: 'Italic' }).click();
  await expect(editor.locator('em')).toHaveText('Bold words');
  await expect(textarea).toHaveValue('_Bold words_');

  await editor.locator('em').evaluate((element) => {
    const selection = window.getSelection();
    const range = document.createRange();
    range.selectNodeContents(element);
    selection?.removeAllRanges();
    selection?.addRange(range);
  });
  await page.getByRole('button', { name: 'Italic' }).click();
  await expect(editor.locator('em')).toHaveCount(0);
  await expect(editor.locator('strong')).toHaveCount(0);
  await expect(textarea).toHaveValue('Bold words');

  await loadEditorFixture(page, 'markdown');
  page.once('dialog', (dialog) => dialog.accept('https://example.test/reference'));
  await editor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.getByRole('button', { name: 'Link' }).click();
  await expect(editor.locator('a')).toHaveAttribute('href', 'https://example.test/reference');
  await expect(textarea).toHaveValue('[Bold words](https://example.test/reference)');
});

test('Markdown inline formatting removes only the selected part of an existing format', async ({
  page,
}) => {
  await loadEditorFixture(
    page,
    'markdown',
    '<p><strong>hello world</strong></p>',
    '**hello world**',
  );

  const editor = page.getByRole('textbox', { name: 'Page content' });
  const textarea = page.locator('[data-editor-textarea]');

  await editor.locator('strong').evaluate((element) => {
    const text = element.firstChild;

    if (!(text instanceof Text)) {
      throw new TypeError('Expected bold fixture text.');
    }

    const selection = window.getSelection();
    const range = document.createRange();
    range.setStart(text, 0);
    range.setEnd(text, 'hello'.length);
    selection?.removeAllRanges();
    selection?.addRange(range);
  });
  await page.getByRole('button', { name: 'Bold' }).click();

  await expect(editor.locator('strong')).toHaveCount(1);
  await expect
    .poll(() => editor.locator('strong').evaluate((element) => element.textContent))
    .toBe(' world');
  await expect(textarea).toHaveValue('hello **world**');
});

test('Markdown inline formatting applies independently across selected blocks', async ({
  page,
}) => {
  await loadEditorFixture(page, 'markdown', '<p>Alpha</p><p>Beta</p>', 'Alpha\n\nBeta');

  const editor = page.getByRole('textbox', { name: 'Page content' });
  const textarea = page.locator('[data-editor-textarea]');

  await editor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.getByRole('button', { name: 'Bold' }).click();

  await expect(editor.locator(':scope > p > strong')).toHaveCount(2);
  await expect(editor.locator('strong > p')).toHaveCount(0);
  await expect(textarea).toHaveValue('**Alpha**\n\n**Beta**');

  await page.getByRole('button', { name: 'Bold' }).click();
  await expect(editor.locator('strong')).toHaveCount(0);
  await expect(textarea).toHaveValue('Alpha\n\nBeta');
});

test('Markdown links apply independently across selected blocks', async ({ page }) => {
  await loadEditorFixture(page, 'markdown', '<p>Alpha</p><p>Beta</p>', 'Alpha\n\nBeta');

  const editor = page.getByRole('textbox', { name: 'Page content' });
  const textarea = page.locator('[data-editor-textarea]');

  page.once('dialog', (dialog) => dialog.accept('https://example.test/reference'));
  await editor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.getByRole('button', { name: 'Link' }).click();

  await expect(editor.locator(':scope > p > a')).toHaveCount(2);
  await expect(editor.locator('a > p')).toHaveCount(0);
  await expect(textarea).toHaveValue(
    '[Alpha](https://example.test/reference)\n\n[Beta](https://example.test/reference)',
  );
});

test('Markdown rich editor inserts editable fenced code blocks', async ({ page }) => {
  await loadEditorFixture(page, 'markdown', '<p>{"ok": true}</p>', '{"ok": true}');

  const editor = page.getByRole('textbox', { name: 'Page content' });
  await editor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.getByRole('button', { name: 'Code block' }).click();

  const block = editor.locator('[data-editor-code-block]');
  const code = block.locator('[data-editor-code-content]');

  await expect(block).toBeVisible();
  await expect(code).toHaveText('{"ok": true}');
  await expect(page.locator('[data-editor-textarea]')).toHaveValue('```\n{"ok": true}\n```');

  await code.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.press('Backspace');
  await page.keyboard.insertText('<?php echo "ok";');

  await expect(page.locator('[data-editor-textarea]')).toHaveValue('```\n<?php echo "ok";\n```');
});

test('Markdown source fences render back into rich editable code blocks', async ({ page }) => {
  await loadEditorFixture(page, 'markdown');

  const richEditor = page.getByRole('textbox', { name: 'Page content' });
  const sourceEditor = page.locator('[data-source-editor-mount] .cm-content');

  await page.getByRole('button', { name: 'Markdown source' }).click();
  await expect(sourceEditor).toBeVisible();
  await sourceEditor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.type('```html\n<section>Artifact</section>\n```');
  await page.getByRole('button', { name: 'Rich editor' }).click();

  const block = richEditor.locator('[data-editor-code-block]');

  await expect(block).toBeVisible();
  await expect(block.locator('[data-editor-code-content]')).toHaveText(
    '<section>Artifact</section>',
  );
  await expect(page.locator('[data-editor-textarea]')).toHaveValue(
    '```html\n<section>Artifact</section>\n```',
  );
});

test('Markdown rich editor inserts visual Mermaid blocks with live editable source', async ({
  page,
}) => {
  await loadEditorFixture(page, 'markdown', '<p>Diagram</p>', 'Diagram');

  const editor = page.getByRole('textbox', { name: 'Page content' });
  await editor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.getByRole('button', { name: 'Mermaid diagram' }).click();

  const diagram = editor.locator('[data-mermaid-diagram]');
  const template = diagram.getByLabel('Mermaid diagram type');
  const source = diagram.locator('[data-editor-mermaid-source]');
  const canvas = diagram.locator('[data-mermaid-canvas]');
  const svg = canvas.locator('svg');

  await expect(canvas).toBeVisible();
  await expect(svg).toBeVisible();
  await expect
    .poll(() =>
      canvas.evaluate((element) => {
        const svgElement = element.querySelector('svg');
        const canvasBox = element.getBoundingClientRect();
        const svgBox = svgElement?.getBoundingClientRect();
        const sourcePanel = element
          .closest('[data-mermaid-diagram]')
          ?.querySelector('.artifactflow-mermaid-source');
        const nodeGroups = [...(svgElement?.querySelectorAll('g[id*="flowchart-"]') ?? [])].filter(
          (group) =>
            group.querySelector(':scope > rect') !== null && group.textContent?.trim() !== '',
        );
        const visibleShapeFills = [
          ...(svgElement?.querySelectorAll('rect, polygon, circle, ellipse') ?? []),
        ]
          .map((shape) => getComputedStyle(shape).fill)
          .filter((fill) => fill !== 'none' && fill !== 'rgba(0, 0, 0, 0)');
        const visibleTextFills = [...(svgElement?.querySelectorAll('text, tspan') ?? [])]
          .map((shape) => getComputedStyle(shape).fill)
          .filter((fill) => fill !== 'none' && fill !== 'rgba(0, 0, 0, 0)');
        const labelsFitNodes = nodeGroups.every((group) => {
          const rect = group.querySelector(':scope > rect');
          const text = group.querySelector('text');
          const rectBox = rect?.getBoundingClientRect();
          const textBox = text?.getBoundingClientRect();

          return (
            rectBox !== undefined &&
            textBox !== undefined &&
            textBox.left >= rectBox.left - 1 &&
            textBox.right <= rectBox.right + 1
          );
        });

        return {
          background: getComputedStyle(element).backgroundColor,
          hasBlackShape: visibleShapeFills.includes('rgb(0, 0, 0)'),
          hasDarkShape: visibleShapeFills.includes('rgb(39, 39, 42)'),
          hasLightText: visibleTextFills.includes('rgb(244, 244, 245)'),
          labelsFitNodes,
          sourceBackground:
            sourcePanel instanceof HTMLElement ? getComputedStyle(sourcePanel).backgroundColor : '',
          svgFitsCanvas: svgBox !== undefined && svgBox.width <= canvasBox.width + 1,
        };
      }),
    )
    .toEqual({
      background: 'rgb(24, 24, 27)',
      hasBlackShape: false,
      hasDarkShape: true,
      hasLightText: true,
      labelsFitNodes: true,
      sourceBackground: 'rgb(24, 24, 27)',
      svgFitsCanvas: true,
    });
  await expect(template).toHaveValue('flowchart');
  await expect(source).toContainText('graph TD');
  await expect(page.locator('[data-editor-textarea]')).toHaveValue(/```mermaid\n/);

  await template.selectOption('sequence');
  await expect(source).toContainText('sequenceDiagram');
  await expect(page.locator('[data-editor-textarea]')).toHaveValue(/sequenceDiagram/);

  await source.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.press('Backspace');
  await page.keyboard.insertText('graph TD');
  await page.keyboard.press('Enter');
  await page.keyboard.insertText('  User --> ArtifactFlow');

  await expect(page.locator('[data-editor-textarea]')).toHaveValue(
    '```mermaid\ngraph TD\n  User --> ArtifactFlow\n```',
  );
});

test('Enter on an empty trailing code line escapes the code block into a paragraph', async ({
  page,
}) => {
  await loadEditorFixture(page, 'markdown', '<p>alpha</p>', 'alpha');

  const editor = page.getByRole('textbox', { name: 'Page content' });
  const textarea = page.locator('[data-editor-textarea]');

  await editor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.getByRole('button', { name: 'Code block' }).click();

  const code = editor.locator('[data-editor-code-block] [data-editor-code-content]');
  await code.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.press('Backspace');
  await page.keyboard.insertText('line1');
  await page.keyboard.press('Enter');
  await page.keyboard.insertText('line2');

  // A single mid-block Enter inserts a hard line break and keeps the caret in the code.
  await expect(textarea).toHaveValue('```\nline1\nline2\n```');

  // One more Enter moves onto an empty trailing line; the next Enter escapes.
  await page.keyboard.press('Enter');
  await page.keyboard.press('Enter');
  await page.keyboard.insertText('after paragraph');

  await expect(textarea).toHaveValue('```\nline1\nline2\n```\n\nafter paragraph');
  await expect(editor.locator('[data-editor-code-block] + p')).toHaveText('after paragraph');
  // The leftover empty trailing line is removed: only the line1->line2 break remains.
  await expect(
    editor.locator('[data-editor-code-block] [data-editor-code-content] br'),
  ).toHaveCount(1);
});

test('Enter never escapes a Mermaid source block', async ({ page }) => {
  await loadEditorFixture(page, 'markdown', '<p>Diagram</p>', 'Diagram');

  const editor = page.getByRole('textbox', { name: 'Page content' });
  const textarea = page.locator('[data-editor-textarea]');

  await editor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.getByRole('button', { name: 'Mermaid diagram' }).click();

  const source = editor.locator('[data-editor-mermaid-source]');
  await source.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.press('Backspace');
  await page.keyboard.insertText('graph TD');
  await page.keyboard.press('Enter');
  await page.keyboard.press('Enter');
  await page.keyboard.insertText('A --> B');

  // Two Enters stay trapped inside the diagram source: the typed text lands in
  // the Mermaid source, and the escape never runs, so the caret-boundary
  // paragraph after the block stays empty instead of receiving the typed text.
  await expect(source).toContainText('A --> B');
  await expect(textarea).toHaveValue('```mermaid\ngraph TD\n\nA --> B\n```');
  await expect(editor.locator('[data-mermaid-diagram] + p')).toHaveText('');
});

test('code block editor pre does not inherit the preview pre gradient, border, or padding', async ({
  page,
}) => {
  await loadEditorFixture(page, 'markdown', '<p>{"ok": true}</p>', '{"ok": true}');

  const editor = page.getByRole('textbox', { name: 'Page content' });
  await editor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.getByRole('button', { name: 'Code block' }).click();

  const pre = editor.locator('.artifactflow-code-block-editor');
  await expect(pre).toBeVisible();

  // The dark ruled surface, padding and border must live only on the inner <code>,
  // never on the <pre> (which would otherwise double them via .artifactflow-markdown pre).
  const preStyles = await pre.evaluate((element) => {
    const cs = getComputedStyle(element);
    return {
      backgroundImage: cs.backgroundImage,
      paddingTop: cs.paddingTop,
      borderTopWidth: cs.borderTopWidth,
    };
  });
  expect(preStyles.backgroundImage).toBe('none');
  expect(preStyles.paddingTop).toBe('0px');
  expect(preStyles.borderTopWidth).toBe('0px');

  // The inner <code> keeps exactly one aligned, 1rem-padded ruled background.
  const code = pre.locator('[data-editor-code-content]');
  const codeStyles = await code.evaluate((element) => {
    const cs = getComputedStyle(element);
    return { backgroundImage: cs.backgroundImage, paddingTop: cs.paddingTop };
  });
  expect(codeStyles.backgroundImage).not.toBe('none');
  expect(codeStyles.paddingTop).toBe('16px');
});

test('Markdown toolbar applies rich formatting while preserving Markdown source', async ({
  page,
}) => {
  await loadEditorFixture(page, 'markdown');

  const editor = page.getByRole('textbox', { name: 'Page content' });
  await expect(editor).toBeVisible();
  await editor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.getByRole('button', { name: 'Bold' }).click();

  await expect(editor.locator('b, strong')).toHaveText('Bold words');
  await expect(editor).not.toContainText('**Bold words**');
  await expect(page.locator('[data-editor-textarea]')).toHaveValue('**Bold words**');

  await expect
    .poll(() =>
      editor.evaluate((element) => ({
        background: getComputedStyle(element).backgroundColor,
        color: getComputedStyle(element).color,
      })),
    )
    .toEqual({
      background: 'rgb(24, 24, 27)',
      color: 'rgb(244, 244, 245)',
    });
});

test('HTML source editor keeps readable dark-theme foreground and background', async ({ page }) => {
  await loadEditorFixture(page, 'html');

  const source = page.locator('[data-source-editor-mount] .cm-content');
  await expect(source).toBeVisible();
  await source.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.type('Changed artifact');

  await expect(page.locator('[data-editor-textarea]')).toHaveValue('Changed artifact');

  await expect
    .poll(() =>
      source.evaluate((element) => ({
        background: getComputedStyle(element).backgroundColor,
        color: getComputedStyle(element).color,
      })),
    )
    .toEqual({
      background: 'rgb(24, 24, 27)',
      color: 'rgb(244, 244, 245)',
    });
});

test('Markdown editor can switch between rich and source views without losing content', async ({
  page,
}) => {
  await loadEditorFixture(page, 'markdown');

  const richEditor = page.getByRole('textbox', { name: 'Page content' });
  const sourceEditor = page.locator('[data-source-editor-mount] .cm-content');
  const sourceButton = page.getByRole('button', { name: 'Markdown source' });
  const richButton = page.getByRole('button', { name: 'Rich editor' });

  await expect(richEditor).toBeVisible();
  await sourceButton.click();
  await expect(richEditor).toBeHidden();
  await expect(sourceEditor).toBeVisible();
  await sourceEditor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.type('# Source view');
  await richButton.click();

  await expect(richEditor).toBeVisible();
  await expect(richEditor.getByRole('heading', { name: 'Source view' })).toBeVisible();
  await expect(page.locator('[data-editor-textarea]')).toHaveValue('# Source view');
});

test('Markdown source round-trips inline emphasis into the rich editor without escaping it', async ({
  page,
}) => {
  await loadEditorFixture(page, 'markdown');

  const richEditor = page.getByRole('textbox', { name: 'Page content' });
  const sourceEditor = page.locator('[data-source-editor-mount] .cm-content');
  const sourceButton = page.getByRole('button', { name: 'Markdown source' });
  const richButton = page.getByRole('button', { name: 'Rich editor' });
  const textarea = page.locator('[data-editor-textarea]');

  await sourceButton.click();
  await expect(sourceEditor).toBeVisible();
  await sourceEditor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.type('Text with **bold** and _em_ words.');

  // Loading the source into the rich editor must produce real formatting
  // elements, not literal text carrying the Markdown markers.
  await richButton.click();
  await expect(richEditor).toBeVisible();
  await expect(richEditor.locator('strong')).toHaveText('bold');
  await expect(richEditor.locator('em')).toHaveText('em');

  // Serialising the rich DOM back to source must preserve the markers instead
  // of escaping them into \*\*bold\*\* and \_em\_.
  await sourceButton.click();
  await expect(textarea).toHaveValue('Text with **bold** and _em_ words.');
});

// Replace the source-editor content and load it into the rich view. Dispatching
// one text/plain paste preserves leading ASCII spaces in every engine; WebKit
// and Firefox turn the first leading space into U+00A0 when Playwright sends a
// multi-line insertText() call to a contenteditable CodeMirror surface.
async function loadSourceIntoRichView(page: Page, source: string) {
  const sourceEditor = page.locator('[data-source-editor-mount] .cm-content');
  const richEditor = page.getByRole('textbox', { name: 'Page content' });

  await page.getByRole('button', { name: 'Markdown source' }).click();
  await expect(sourceEditor).toBeVisible();
  await sourceEditor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await sourceEditor.evaluate((element, markdown) => {
    const transfer = new DataTransfer();
    transfer.setData('text/plain', markdown);
    const paste = new ClipboardEvent('paste', {
      bubbles: true,
      cancelable: true,
      clipboardData: transfer,
    });

    // Firefox ignores ClipboardEventInit.clipboardData for constructed events.
    Object.defineProperty(paste, 'clipboardData', { value: transfer });
    element.dispatchEvent(paste);
  }, source);
  await expect(page.locator('[data-editor-textarea]')).toHaveValue(source);
  await page.getByRole('button', { name: 'Rich editor' }).click();
  await expect(richEditor).toBeVisible();

  return richEditor;
}

// Regression for the lossy source→rich direction: lists and blockquotes used to
// load as literal paragraph text, which mashed the items onto one line in the
// contenteditable and backslash-escaped stray markers into \*\*\_ junk lines on
// the next save. Loading source must build real block elements, and serialising
// straight back must reproduce the source byte for byte.
test('Markdown source round-trips lists, quotes, and dividers into real rich blocks', async ({
  page,
}) => {
  await loadEditorFixture(page, 'markdown');

  const source = [
    'Intro with ~~gone~~ and **_bolditalic_** text',
    '',
    '- List item',
    '- List item2 with **bold**',
    '',
    '1. number',
    '2. number2',
    '',
    '> > quote',
    '',
    '---',
    '',
    'After the quote',
  ].join('\n');
  const richEditor = await loadSourceIntoRichView(page, source);

  await expect(richEditor.locator('del')).toHaveText('gone');
  await expect(richEditor.locator('strong em')).toHaveText('bolditalic');
  await expect(richEditor.locator('ul > li')).toHaveCount(2);
  await expect(richEditor.locator('ul > li').first()).toHaveText('List item');
  await expect(richEditor.locator('ul > li strong')).toHaveText('bold');
  await expect(richEditor.locator('ol > li')).toHaveCount(2);
  await expect(richEditor.locator('blockquote blockquote')).toHaveText('quote');
  await expect(richEditor.locator('hr')).toHaveCount(1);

  // The content after the quote stays its own paragraph — not swallowed into
  // the quote or the lists, and not escaped into junk.
  await expect(richEditor.locator('p').last()).toHaveText('After the quote');

  await page.getByRole('button', { name: 'Markdown source' }).click();
  await expect(page.locator('[data-editor-textarea]')).toHaveValue(source);
});

test('Markdown source round-trips nested and non-default ordered lists', async ({ page }) => {
  await loadEditorFixture(page, 'markdown');

  const source = ['3. Third', '4. Fourth', '', '- Parent', '  - Child'].join('\n');
  const richEditor = await loadSourceIntoRichView(page, source);

  await expect(richEditor.locator('ol')).toHaveAttribute('start', '3');
  await expect(richEditor.locator('ol > li')).toHaveCount(2);
  await expect(richEditor.locator('ul > li > ul > li')).toHaveText('Child');

  await page.getByRole('button', { name: 'Markdown source' }).click();
  await expect(page.locator('[data-editor-textarea]')).toHaveValue(source);
});

test('Markdown source round-trips loose list items and task lists', async ({ page }) => {
  await loadEditorFixture(page, 'markdown');

  const source = [
    '- First para',
    '',
    '  Second para',
    '',
    'Between',
    '',
    '- [x] done',
    '- [ ] todo',
  ].join('\n');
  const richEditor = await loadSourceIntoRichView(page, source);

  // The loose item keeps its two paragraphs inside one <li>.
  await expect(richEditor.locator('ul').first().locator('li > p')).toHaveCount(2);
  await expect(richEditor.locator('input[type="checkbox"]')).toHaveCount(2);
  await expect(richEditor.locator('input[type="checkbox"]').first()).toBeChecked();
  await expect(richEditor.locator('input[type="checkbox"]').last()).not.toBeChecked();

  await page.getByRole('button', { name: 'Markdown source' }).click();
  await expect(page.locator('[data-editor-textarea]')).toHaveValue(source);
});

// Regression: toolbar block insertion at a caret used to insert the block INSIDE
// the paragraph. The serialiser then flattened the paragraph inline, gluing
// `- List item` straight onto the preceding text — the first item silently fell
// out of the list in the saved Markdown.
test('Bulleted list inserted at a caret becomes a sibling of the paragraph, not its child', async ({
  page,
}) => {
  await loadEditorFixture(page, 'markdown', '<p>Intro text</p>', 'Intro text');

  const editor = page.getByRole('textbox', { name: 'Page content' });

  await editor.click();
  await page.keyboard.press('End');
  await page.getByRole('button', { name: 'Bulleted list' }).click();

  await expect(editor.locator('p ul')).toHaveCount(0);
  await expect(editor.locator('ul > li')).toHaveText('List item');
  await expect(page.locator('[data-editor-textarea]')).toHaveValue('Intro text\n\n- List item');
});

test('toolbar formatting before a list cannot wrap the remaining document in bold', async ({
  page,
}) => {
  await loadEditorFixture(page, 'markdown', '<p><br></p>', '');

  const editor = page.getByRole('textbox', { name: 'Page content' });
  const textarea = page.locator('[data-editor-textarea]');

  await editor.click();
  await page.getByLabel('Block style').selectOption('h2');
  await page.keyboard.insertText('Heading');
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');

  await page.getByRole('button', { name: 'Bold' }).click();
  await page.keyboard.insertText('bold text');
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await page.keyboard.press('Enter');

  await page.getByRole('button', { name: 'Italic' }).click();
  await page.keyboard.insertText('italic text');
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await page.keyboard.press('Enter');

  page.once('dialog', (dialog) => dialog.accept('https://example.test/reference'));
  await page.getByRole('button', { name: 'Link' }).click();
  await page.keyboard.insertText('link text');
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await page.keyboard.press('Enter');

  await page.getByRole('button', { name: 'Bulleted list' }).click();
  await page.keyboard.insertText('First item');
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await page.keyboard.insertText('Second item');
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await page.keyboard.press('Enter');

  await page.getByRole('button', { name: 'Code block' }).click();
  await page.keyboard.insertText('dasdsaasdds');
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await page.keyboard.press('Enter');
  await page.getByRole('button', { name: 'Divider' }).click();

  await expect(textarea).toHaveValue(
    [
      '## Heading',
      '',
      '**bold text**',
      '',
      '_italic text_',
      '',
      '[link text](https://example.test/reference)',
      '',
      '- First item',
      '- Second item',
      '',
      '```',
      'dasdsaasdds',
      '```',
      '',
      '---',
    ].join('\n'),
  );
});

test('block insertion splits the paragraph around a partial selection', async ({ page }) => {
  await loadEditorFixture(page, 'markdown', '<p>Alpha beta</p>', 'Alpha beta');

  const editor = page.getByRole('textbox', { name: 'Page content' });

  await editor.click();
  await editor.evaluate((element) => {
    const text = element.querySelector('p')?.firstChild;
    const range = document.createRange();

    if (text === null || text === undefined) {
      return;
    }

    range.setStart(text, 6);
    range.setEnd(text, 10);
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);
  });
  await page.getByRole('button', { name: 'Quote' }).click();

  await expect(editor.locator('p blockquote')).toHaveCount(0);
  await expect(editor.locator('blockquote')).toHaveText('beta');
  await expect(page.locator('[data-editor-textarea]')).toHaveValue('Alpha\n\n> beta');
});

test('Mermaid inserted at a caret becomes its own block after the paragraph', async ({ page }) => {
  await loadEditorFixture(page, 'markdown', '<p>Diagram intro</p>', 'Diagram intro');

  const editor = page.getByRole('textbox', { name: 'Page content' });

  await editor.click();
  await page.keyboard.press('End');
  await page.getByRole('button', { name: 'Mermaid diagram' }).click();

  await expect(editor.locator('p [data-mermaid-diagram]')).toHaveCount(0);
  await expect(page.locator('[data-editor-textarea]')).toHaveValue(
    /^Diagram intro\n\n```mermaid\n/u,
  );
});

// Serialiser hardening for the same class of damage: even if a block element
// still ends up inside a paragraph (legacy DOM, unforeseen editing path), it
// must serialise as its own block instead of being glued onto the inline text.
test('a list trapped inside a paragraph still serialises as its own block', async ({ page }) => {
  await loadEditorFixture(page, 'markdown', '<p>Intro</p>', 'Intro');

  const editor = page.getByRole('textbox', { name: 'Page content' });

  await editor.evaluate((element) => {
    const paragraph = element.querySelector('p');
    const list = document.createElement('ul');
    const item = document.createElement('li');

    item.textContent = 'Trapped';
    list.append(item);
    paragraph?.append(list);
  });
  await page.getByRole('button', { name: 'Markdown source' }).click();

  await expect(page.locator('[data-editor-textarea]')).toHaveValue('Intro\n\n- Trapped');
});

// Regression for the silent-content-loss blocker: an existing Markdown page renders
// into the default rich view as real DOM nodes (<img>, <table>, <del>, task lists).
// Saving from rich view serialises that DOM back to Markdown, so any node the
// serialiser does not handle is silently dropped or mangled from the new version.
// Each case seeds the rich editor with the server-rendered HTML and asserts the
// serialised Markdown round-trips losslessly.
for (const { name, html, source, expected } of [
  {
    name: 'a linked image',
    html: '<p><img src="https://example.test/diagram.png" alt="diagram"></p>',
    source: '![diagram](https://example.test/diagram.png)',
    expected: '![diagram](https://example.test/diagram.png)',
  },
  {
    name: 'a base64 raster data image',
    html: '<p><img src="data:image/png;base64,iVBORw0KGgo=" alt="pixel"></p>',
    source: '![pixel](data:image/png;base64,iVBORw0KGgo=)',
    expected: '![pixel](data:image/png;base64,iVBORw0KGgo=)',
  },
  {
    name: 'a GFM table',
    html: '<table><thead><tr><th>Name</th><th>Role</th></tr></thead><tbody><tr><td>Ada</td><td>Editor</td></tr></tbody></table>',
    source: '| Name | Role |\n| --- | --- |\n| Ada | Editor |',
    expected: '| Name | Role |\n| --- | --- |\n| Ada | Editor |',
  },
  {
    name: 'a GFM table with column alignment',
    // CommonMark renders `:---`/`:---:`/`---:` alignment onto each cell's align
    // attribute; serialising must regenerate the aligned delimiter row, not plain ---.
    html: '<table><thead><tr><th align="left">L</th><th align="center">C</th><th align="right">R</th></tr></thead><tbody><tr><td align="left">a</td><td align="center">b</td><td align="right">c</td></tr></tbody></table>',
    source: '| L | C | R |\n| :--- | :---: | ---: |\n| a | b | c |',
    expected: '| L | C | R |\n| :--- | :---: | ---: |\n| a | b | c |',
  },
  {
    name: 'strikethrough text',
    html: '<p>Keep <del>drop this</del> text</p>',
    source: 'Keep ~~drop this~~ text',
    expected: 'Keep ~~drop this~~ text',
  },
  {
    name: 'a task list',
    html: '<ul><li><input checked="" disabled="" type="checkbox"> done</li><li><input disabled="" type="checkbox"> todo</li></ul>',
    source: '- [x] done\n- [ ] todo',
    expected: '- [x] done\n- [ ] todo',
  },
  {
    // CommonMark renders a list starting above 1 as <ol start="N">; the serialiser
    // must continue from N instead of renumbering every item from 1.
    name: 'an ordered list that does not start at one',
    html: '<ol start="3"><li>Third</li><li>Fourth</li></ol>',
    source: '3. Third\n4. Fourth',
    expected: '3. Third\n4. Fourth',
  },
  {
    // A loose <li> holds several block children; the two paragraphs must survive
    // as two paragraphs (blank line + content-column indent), not fuse onto one line.
    name: 'a list item with two paragraphs',
    html: '<ul><li><p>First para</p><p>Second para</p></li></ul>',
    source: '- First para\n\n  Second para',
    expected: '- First para\n\n  Second para',
  },
  {
    // Link titles are document content and must round-trip, like image titles do.
    name: 'a link with a title',
    html: '<p><a href="https://example.test" title="Architecture">Docs</a></p>',
    source: '[Docs](https://example.test "Architecture")',
    expected: '[Docs](https://example.test "Architecture")',
  },
  {
    // A code span containing a backtick needs a longer fence with space padding;
    // backslash escaping is invalid inside a code span and corrupts the content.
    name: 'inline code containing a backtick',
    html: '<p>Use <code>`</code> here.</p>',
    source: 'Use `` ` `` here.',
    expected: 'Use `` ` `` here.',
  },
  {
    // Significant leading/trailing spaces in a code span survive: CommonMark strips
    // one space from each side, so the serialiser pads an extra one.
    name: 'a code span with significant surrounding spaces',
    html: '<p><code> foo </code></p>',
    source: '`  foo  `',
    expected: '`  foo  `',
  },
  {
    // A sublist under an ordered item must indent to the marker's content column
    // (four spaces under `10. `), or CommonMark parses it outside the item.
    name: 'a nested list under a wide ordered marker',
    html: '<ol start="10"><li>Parent<ul><li>Child</li></ul></li></ol>',
    source: '10. Parent\n    - Child',
    expected: '10. Parent\n    - Child',
  },
  {
    // A title ending in a backslash must escape the backslash before the quote,
    // or the closing quote is escaped and the link no longer parses.
    name: 'a link title ending in a backslash',
    html: '<p><a href="https://example.test" title="path\\">Docs</a></p>',
    source: '[Docs](https://example.test "path\\\\")',
    expected: '[Docs](https://example.test "path\\\\")',
  },
  {
    name: 'an image title ending in a backslash',
    html: '<p><img src="/diagram.png" alt="d" title="path\\"></p>',
    source: '![d](/diagram.png "path\\\\")',
    expected: '![d](/diagram.png "path\\\\")',
  },
  {
    // Brackets in link text must be escaped so the text does not close early — but
    // this must NOT leak into prose, or it would corrupt `[[wiki]]` links.
    name: 'link text containing a bracket',
    html: '<p><a href="https://example.test">a]b</a></p>',
    source: '[a\\]b](https://example.test)',
    expected: '[a\\]b](https://example.test)',
  },
  {
    // Boundary guard: an unresolved [[Page]] renders as literal prose text, so its
    // brackets must stay unescaped to render as a wiki link again on save.
    name: 'unresolved wiki-link brackets in prose stay unescaped',
    html: '<p>See [[Page]] now</p>',
    source: 'See [[Page]] now',
    expected: 'See [[Page]] now',
  },
  {
    // A nested list BETWEEN paragraphs must serialise in document order, not be
    // hoisted after later content (which would reorder Before/list/After).
    name: 'a nested list interleaved between paragraphs',
    html: '<ul><li><p>Before</p><ol><li>Child</li></ol><p>After</p></li></ul>',
    source: '- Before\n\n  1. Child\n\n  After',
    expected: '- Before\n\n  1. Child\n\n  After',
  },
  {
    // A fenced code block between paragraphs in a list item must survive as a fenced
    // block indented to the content column — not be flattened onto one ``` … ``` line,
    // which silently destroys the block and its language.
    name: 'a fenced code block inside a list item',
    html: '<ul><li><p>Before</p><pre><code class="language-php">echo 1;</code></pre><p>After</p></li></ul>',
    source: '- Before\n\n  ```php\n  echo 1;\n  ```\n\n  After',
    expected: '- Before\n\n  ```php\n  echo 1;\n  ```\n\n  After',
  },
  {
    // A Mermaid block widget inside a list item must serialise as a fenced mermaid
    // block in place, the same block-in-item guard as a code block.
    name: 'a Mermaid block inside a list item',
    html: '<ul><li><p>Before</p><div class="artifactflow-mermaid" data-mermaid-diagram data-mermaid-source="graph TD"><div class="artifactflow-mermaid-canvas" data-mermaid-canvas></div><details class="artifactflow-mermaid-source"><summary>Diagram source</summary><pre class="artifactflow-mermaid-source-code"><code class="language-mermaid">graph TD</code></pre></details></div><p>After</p></li></ul>',
    source: '- Before\n\n  ```mermaid\n  graph TD\n  ```\n\n  After',
    expected: '- Before\n\n  ```mermaid\n  graph TD\n  ```\n\n  After',
  },
]) {
  test(`Markdown rich editor serialises ${name} back to Markdown without losing it`, async ({
    page,
  }) => {
    await loadEditorFixture(page, 'markdown', html, source);

    const richEditor = page.getByRole('textbox', { name: 'Page content' });
    const sourceButton = page.getByRole('button', { name: 'Markdown source' });
    const textarea = page.locator('[data-editor-textarea]');

    await expect(richEditor).toBeVisible();
    await sourceButton.click();
    await expect(textarea).toHaveValue(expected);
  });
}

test('Markdown source round-trips an image into the rich editor as a real <img>', async ({
  page,
}) => {
  await loadEditorFixture(page, 'markdown');

  const richEditor = page.getByRole('textbox', { name: 'Page content' });
  const sourceEditor = page.locator('[data-source-editor-mount] .cm-content');
  const sourceButton = page.getByRole('button', { name: 'Markdown source' });
  const richButton = page.getByRole('button', { name: 'Rich editor' });
  const textarea = page.locator('[data-editor-textarea]');

  await sourceButton.click();
  await expect(sourceEditor).toBeVisible();
  await sourceEditor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.type('![diagram](https://example.test/diagram.png)');

  // Loading the source into the rich editor must build a real <img>, not a literal
  // "!" followed by a link (the pre-fix behaviour), and not drop it.
  await richButton.click();
  await expect(richEditor).toBeVisible();
  await expect(richEditor.locator('img')).toHaveAttribute(
    'src',
    'https://example.test/diagram.png',
  );

  // Serialising back to source must retain the image markup.
  await sourceButton.click();
  await expect(textarea).toHaveValue('![diagram](https://example.test/diagram.png)');
});

test('upload creation mode swaps content for the file input but keeps the organize metadata and derives a title from the file', async ({
  page,
}) => {
  await page.goto(`${baseUrl}/up`, { waitUntil: 'networkidle' });
  await page.setContent(`
    <!doctype html>
    <html>
      <body>
        <form data-create-page-form>
          <select name="type">
            <option value="markdown" selected>Markdown</option>
            <option value="html_artifact">HTML artifact</option>
          </select>
          <select name="mode">
            <option value="markdown" selected>Markdown</option>
            <option value="html_paste">Paste HTML</option>
            <option value="html_upload">Upload HTML</option>
          </select>
          <section data-create-page-essential-fields>Essential</section>
          <section data-create-page-optional-fields>Optional metadata</section>
          <section data-create-page-content-fields>Content editor</section>
          <section data-create-page-upload-fields hidden>
            <input name="html_file" type="file">
          </section>
          <input name="title" type="text">
        </form>
        <script type="module" src="${appAsset}"></script>
      </body>
    </html>
  `);

  await page.locator('select[name="type"]').selectOption('html_artifact');
  await page.locator('select[name="mode"]').selectOption('html_upload');

  await expect(page.locator('[data-create-page-essential-fields]')).toBeVisible();
  // The organize metadata (tags, category, status, ...) stays available for uploads.
  await expect(page.locator('[data-create-page-optional-fields]')).toBeVisible();
  await expect(page.locator('[data-create-page-content-fields]')).toBeHidden();
  await expect(page.locator('[data-create-page-upload-fields]')).toBeVisible();

  await page.locator('input[name="html_file"]').setInputFiles({
    name: 'release-dashboard.html',
    mimeType: 'text/html',
    buffer: Buffer.from('<h1>Release dashboard</h1>'),
  });

  await expect(page.locator('input[name="title"]')).toHaveValue('Release dashboard');
});

test('HTML paste mode does not redispatch an unchanged type selection', async ({ page }) => {
  const cspNonce = await loadAppOriginCspNonce(page);

  await page.setContent(`
    <!doctype html>
    <html>
      <body>
        <form data-html-draft-preview-form>
          <select name="type">
            <option value="markdown" selected>Markdown</option>
            <option value="html_artifact">HTML artifact</option>
          </select>
          <select name="mode">
            <option value="markdown" selected>Write Markdown</option>
            <option value="html_paste">Paste HTML</option>
            <option value="html_upload">Upload HTML</option>
          </select>
          <textarea data-editor-textarea></textarea>
          <section data-html-draft-preview hidden>
            <button data-html-draft-preview-button type="button">Preview HTML before saving</button>
            <span data-html-draft-preview-status aria-live="polite"></span>
            <iframe data-html-draft-preview-frame name="${draftPreviewFrameName}" sandbox="allow-scripts" allow="" referrerpolicy="no-referrer"></iframe>
          </section>
        </form>
        <script nonce="${cspNonce}" type="module">
          import('${htmlDraftPreviewAsset}').then(() => {
            window.__artifactflowHtmlDraftPreviewLoaded = true;
          });
        </script>
      </body>
    </html>
  `);

  await page.waitForFunction(
    () =>
      (window as typeof window & { __artifactflowHtmlDraftPreviewLoaded?: boolean })
        .__artifactflowHtmlDraftPreviewLoaded === true,
  );

  await page.locator('select[name="type"]').evaluate((select) => {
    const probeWindow = window as typeof window & { __artifactflowTypeChangeCount?: number };

    probeWindow.__artifactflowTypeChangeCount = 0;
    select.addEventListener('change', () => {
      probeWindow.__artifactflowTypeChangeCount =
        (probeWindow.__artifactflowTypeChangeCount ?? 0) + 1;
    });
  });

  await page.locator('select[name="type"]').selectOption('html_artifact');
  await expect
    .poll(() =>
      page.evaluate(
        () =>
          (window as typeof window & { __artifactflowTypeChangeCount?: number })
            .__artifactflowTypeChangeCount ?? 0,
      ),
    )
    .toBe(1);

  await page.locator('select[name="mode"]').selectOption('html_paste');

  await expect(page.locator('select[name="type"]')).toHaveValue('html_artifact');
  await expect
    .poll(() =>
      page.evaluate(
        () =>
          (window as typeof window & { __artifactflowTypeChangeCount?: number })
            .__artifactflowTypeChangeCount ?? 0,
      ),
    )
    .toBe(1);
});

test('workspace tabs and dashboard dialogs progressively disclose administration', async ({
  page,
}) => {
  await page.goto(`${baseUrl}/up`, { waitUntil: 'networkidle' });
  await page.setContent(`
    <!doctype html>
    <html>
      <body>
        <div data-workspace-tabs>
          <div role="tablist">
            <button class="is-active" role="tab" aria-controls="overview-panel" aria-selected="true" data-workspace-tab="overview">Overview</button>
            <button role="tab" aria-controls="members-panel" aria-selected="false" data-workspace-tab="members">Members</button>
          </div>
          <section id="overview-panel" data-workspace-panel="overview">
            <button data-open-editor-dialog="workspace-create-dialog" type="button" aria-label="Create workspace"></button>
            <button data-open-editor-dialog="category-create-dialog" type="button" aria-label="Create category"></button>
            Workspace overview
          </section>
          <section id="members-panel" data-workspace-panel="members" hidden>
            <button data-open-editor-dialog="workspace-invite-dialog" type="button">Invite teammate</button>
          </section>
        </div>
        <dialog data-editor-dialog id="workspace-create-dialog">
          <button data-close-editor-dialog type="button" aria-label="Close workspace form">Close</button>
          <p>Create shared workspace</p>
        </dialog>
        <dialog data-editor-dialog id="category-create-dialog">
          <button data-close-editor-dialog type="button" aria-label="Close category form">Close</button>
          <p>Create category for Platform Team</p>
        </dialog>
        <dialog data-editor-dialog id="workspace-invite-dialog">
          <button data-close-editor-dialog type="button">Close</button>
          <p>Invitation form</p>
        </dialog>
        <script type="module" src="${appAsset}"></script>
      </body>
    </html>
  `);

  await expect(
    page.getByRole('button', { name: 'Create workspace' }),
  ).toHaveAttribute('data-editor-dialog-trigger-ready', '');
  await page.getByRole('button', { name: 'Create workspace' }).click();
  await expect(page.getByText('Create shared workspace')).toBeVisible();
  await page.getByRole('button', { name: 'Close workspace form' }).click();
  await expect(page.getByText('Create shared workspace')).toBeHidden();

  await page.getByRole('button', { name: 'Create category' }).click();
  await expect(page.getByText('Create category for Platform Team')).toBeVisible();
  await page.getByRole('button', { name: 'Close category form' }).click();
  await expect(page.getByText('Create category for Platform Team')).toBeHidden();

  await page.getByRole('tab', { name: 'Members' }).click();
  await expect(page.getByRole('tab', { name: 'Overview' })).not.toHaveClass(/is-active/);
  await expect(page.getByRole('tab', { name: 'Members' })).toHaveClass(/is-active/);
  await expect(page.locator('[data-workspace-panel="overview"]')).toBeHidden();
  await expect(page.locator('[data-workspace-panel="members"]')).toBeVisible();
  await page.getByRole('button', { name: 'Invite teammate' }).click();
  await expect(page.getByText('Invitation form')).toBeVisible();
  await page.getByRole('button', { name: 'Close' }).click();
  await expect(page.getByText('Invitation form')).toBeHidden();
});

test('create-page workspace filters categories and parent pages without leaving the form', async ({
  page,
}) => {
  await page.goto(`${baseUrl}/up`, { waitUntil: 'networkidle' });
  await page.setContent(`
    <!doctype html>
    <html>
      <head>
        <link rel="stylesheet" href="${cssAsset}">
      </head>
      <body>
        <form data-create-page-category>
          <select data-create-page-workspace-select name="workspace_uid">
            <option value="alpha" selected>Alpha workspace</option>
            <option value="beta">Beta workspace</option>
          </select>
          <label for="page-category">Category</label>
          <button class="af-icon-button af-inline-field-action" data-open-editor-dialog="page-category-create-dialog" type="button" aria-label="Create category">+</button>
          <select data-create-page-category-select id="page-category" name="category_uid">
            <option value="">None</option>
            <option data-create-page-category-workspace-uid="alpha" value="alpha-category">Alpha category</option>
            <option data-create-page-category-workspace-uid="beta" value="beta-category">Beta category</option>
          </select>
          <input data-create-page-category-name name="category_name" type="hidden">
          <select data-create-page-parent-select name="parent_page_uid">
            <option value="">None</option>
            <option data-create-page-parent-workspace-uid="alpha" value="alpha-parent">Alpha parent</option>
            <option data-create-page-parent-workspace-uid="beta" value="beta-parent">Beta parent</option>
          </select>
        </form>
        <dialog data-editor-dialog id="page-category-create-dialog">
          <button data-close-editor-dialog type="button">Close</button>
          <p>Create a new category for <span data-create-page-category-workspace-name></span></p>
          <form data-create-page-category-form>
            <input data-create-page-category-input name="category_draft_name" required>
            <button type="submit">Use category</button>
          </form>
        </dialog>
        <script type="module" src="${appAsset}"></script>
      </body>
    </html>
  `);

  await expect
    .poll(async () => {
      const box = await page.getByRole('button', { name: 'Create category' }).boundingBox();

      return box?.width ?? 100;
    })
    .toBeLessThanOrEqual(24);
  await expect(page.locator('option[value="alpha-category"]')).toBeEnabled();
  await expect(page.locator('option[value="beta-category"]')).toBeDisabled();
  await expect(page.locator('option[value="alpha-parent"]')).toBeEnabled();
  await expect(page.locator('option[value="beta-parent"]')).toBeDisabled();

  await page.getByRole('button', { name: 'Create category' }).click();
  await expect(page.getByText('Create a new category for Alpha workspace')).toBeVisible();
  await page.locator('[data-create-page-category-input]').fill('Release Runbooks');
  await page.getByRole('button', { name: 'Use category' }).click();

  await expect(page.getByText('Create a new category for Alpha workspace')).toBeHidden();
  await expect(page.locator('[data-create-page-category-select]')).toHaveValue('');
  await expect(page.locator('[data-create-page-category-select] option:checked')).toHaveText(
    'Release Runbooks (new)',
  );
  await expect(page.locator('[data-create-page-category-name]')).toHaveValue('Release Runbooks');

  await page.locator('[data-create-page-category-select]').selectOption('alpha-category');
  await expect(page.locator('[data-create-page-category-name]')).toHaveValue('');
  await expect(page.locator('[data-create-page-category-option]')).toHaveCount(0);

  await page.getByRole('button', { name: 'Create category' }).click();
  await page.locator('[data-create-page-category-input]').fill('Alpha-only draft');
  await page.getByRole('button', { name: 'Use category' }).click();
  await expect(page.locator('[data-create-page-category-name]')).toHaveValue('Alpha-only draft');

  await page.locator('[data-create-page-parent-select]').selectOption('alpha-parent');

  await page.locator('[data-create-page-workspace-select]').selectOption('beta');
  await expect(page.locator('option[value="alpha-category"]')).toBeDisabled();
  await expect(page.locator('option[value="beta-category"]')).toBeEnabled();
  await expect(page.locator('[data-create-page-category-select]')).toHaveValue('');
  await expect(page.locator('[data-create-page-category-name]')).toHaveValue('');
  await expect(page.locator('[data-create-page-category-option]')).toHaveCount(0);
  await expect(page.locator('option[value="alpha-parent"]')).toBeDisabled();
  await expect(page.locator('option[value="beta-parent"]')).toBeEnabled();
  await expect(page.locator('[data-create-page-parent-select]')).toHaveValue('');
});

test('content editor initialization keeps focus on the create-page workspace controls', async ({
  page,
}) => {
  await page.goto(`${baseUrl}/up`, { waitUntil: 'networkidle' });
  await page.setContent(`
    <!doctype html>
    <html>
      <body>
        <select autofocus data-start-workspace>
          <option selected>Selected workspace</option>
        </select>
        <div style="height: 1200px"></div>
        <form data-content-editor data-editor-language="markdown" data-editor-layout="rich">
          <div contenteditable="true" data-rich-markdown-editor aria-label="Page content"><p><br></p></div>
          <div data-source-editor-mount></div>
          <textarea data-editor-textarea></textarea>
          <span data-editor-status></span>
          <span data-editor-count></span>
        </form>
        <script type="module" src="${editorAsset}"></script>
      </body>
    </html>
  `);

  await page.waitForFunction(
    () =>
      document.querySelector('[data-content-editor]')?.getAttribute('data-editor-ready') === 'true',
  );

  await expect(page.locator('[data-start-workspace]')).toBeFocused();
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);
});

test('page workspace move keeps owner choices scoped to the selected target workspace', async ({
  page,
}) => {
  await page.goto(`${baseUrl}/up`, { waitUntil: 'networkidle' });
  await page.setContent(`
    <!doctype html>
    <html>
      <body>
        <form data-page-workspace-move-form>
          <select name="target_workspace_uid">
            <option data-move-target-workspace-option value="alpha">Alpha workspace</option>
            <option data-move-target-workspace-option value="beta">Beta workspace</option>
          </select>
          <select name="target_owner_user_uid">
            <option data-move-target-owner-option data-move-target-owner-workspace-uid="alpha" value="alpha-owner">Alpha Owner</option>
            <option data-move-target-owner-option data-move-target-owner-workspace-uid="beta" value="beta-owner">Beta Owner</option>
          </select>
        </form>
        <script type="module" src="${appAsset}"></script>
      </body>
    </html>
  `);

  await expect(page.locator('select[name="target_owner_user_uid"]')).toHaveValue('alpha-owner');
  await expect(page.locator('option[value="alpha-owner"]')).toBeEnabled();
  await expect(page.locator('option[value="beta-owner"]')).toBeDisabled();

  await page.locator('select[name="target_workspace_uid"]').selectOption('beta');

  await expect(page.locator('select[name="target_owner_user_uid"]')).toHaveValue('beta-owner');
  await expect(page.locator('option[value="alpha-owner"]')).toBeDisabled();
  await expect(page.locator('option[value="beta-owner"]')).toBeEnabled();
});

test('Mermaid previews keep an editable caret before and after the diagram', async ({ page }) => {
  await loadEditorFixture(
    page,
    'markdown',
    `
      <div class="artifactflow-mermaid" data-mermaid-diagram data-mermaid-source="graph TD&#10;  App --&gt; Database">
        <div class="artifactflow-mermaid-canvas" data-mermaid-canvas role="img" aria-label="Mermaid diagram"></div>
        <details class="artifactflow-mermaid-source">
          <summary>Diagram source</summary>
          <pre class="artifactflow-mermaid-source-code"><code class="language-mermaid">graph TD
  App --&gt; Database</code></pre>
        </details>
      </div>
    `,
  );

  const editor = page.getByRole('textbox', { name: 'Page content' });
  const diagram = editor.locator(':scope > [data-mermaid-diagram]');
  const leadingCaret = diagram.locator('xpath=preceding-sibling::*[1][@data-editor-caret]');
  const trailingCaret = diagram.locator('xpath=following-sibling::*[1][@data-editor-caret]');

  await expect(leadingCaret).toHaveCount(1);
  await expect(trailingCaret).toHaveCount(1);
  await expect(diagram.locator('details')).toHaveAttribute('open', '');

  await editor.click({ position: { x: 20, y: 500 } });
  await page.keyboard.type('After diagram');

  await expect(trailingCaret).toContainText('After diagram');
  await expect(page.locator('[data-editor-textarea]')).toHaveValue(/After diagram$/);
});

test('copy page link writes the stable page URL and announces success', async ({ page }) => {
  const pageUrl = `${baseUrl}/pages/01K00000000000000000000000`;
  const cspNonce = await loadAppOriginCspNonce(page);

  await page.setContent(`
    <!doctype html>
    <html>
      <body>
        <div data-copy-page-link-control>
          <button
            data-copy-page-link
            data-copy-page-link-url="${pageUrl}"
            type="button"
          >
            Copy page link
          </button>
          <span data-copy-page-link-status aria-live="polite"></span>
        </div>
        <script nonce="${cspNonce}" type="module" src="${appAsset}"></script>
      </body>
    </html>
  `);
  await page.evaluate(() => {
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: {
        writeText(value: string) {
          (
            window as typeof window & { __artifactflowCopiedPageLink?: string }
          ).__artifactflowCopiedPageLink = value;

          return Promise.resolve();
        },
      },
    });
  });

  const copyButton = page.getByRole('button', { name: 'Copy page link' });

  await expect(copyButton).toHaveAttribute('data-copy-page-link-ready', '');
  await copyButton.click();

  await expect
    .poll(() =>
      page.evaluate(
        () =>
          (window as typeof window & { __artifactflowCopiedPageLink?: string })
            .__artifactflowCopiedPageLink,
      ),
    )
    .toBe(pageUrl);
  await expect(page.locator('[data-copy-page-link-status]')).toHaveText('Page link copied.');
  await expect(copyButton).toBeEnabled();
});

test('HTML draft preview executes only inside an opaque no-network sandbox', async ({ page }) => {
  test.setTimeout(120_000);

  let outboundRequests = 0;
  const leakedConsoleMessages: string[] = [];
  const fixture = await prepareAuthenticatedDraftPreviewFixture(page);

  page.on('console', (message) => {
    if (message.text().includes('artifactflow-console-leak')) {
      leakedConsoleMessages.push(message.text());
    }
  });

  await page.route('**/draft-preview-network-check', async (route) => {
    outboundRequests += 1;
    await route.abort();
  });
  await page.setContent(
    authenticatedDraftPreviewDocument(
      fixture,
      `<!doctype html>
      <p id="result">starting</p>
      <script>
        console.log('artifactflow-console-leak');

        let parentState = 'parent-accessible';
        try {
          window.parent.document.body.dataset.artifactflowPreviewOwned = 'yes';
        } catch {
          parentState = 'parent-blocked';
        }

        let cookieState = 'cookies-accessible';
        try {
          document.cookie = 'artifactflow_preview_cookie=yes';
          cookieState = document.cookie === '' ? 'cookies-blocked' : 'cookies-accessible';
        } catch {
          cookieState = 'cookies-blocked';
        }

        let storageState = 'storage-accessible';
        try {
          localStorage.setItem('artifactflow_preview_storage', 'yes');
          storageState = localStorage.getItem('artifactflow_preview_storage') === null
            ? 'storage-blocked'
            : 'storage-accessible';
        } catch {
          storageState = 'storage-blocked';
        }

        let rtcState = 'rtc-accessible';
        try {
          new RTCPeerConnection();
        } catch {
          rtcState = 'rtc-blocked';
        }

        fetch('${baseUrl}/draft-preview-network-check')
          .then(() => {
            document.getElementById('result').textContent = [
              parentState,
              'network-accessible',
              cookieState,
              storageState,
              rtcState,
            ].join(' ');
          })
          .catch(() => {
            document.getElementById('result').textContent = [
              parentState,
              'network-blocked',
              cookieState,
              storageState,
              rtcState,
            ].join(' ');
          });
      </script>`,
    ),
  );

  const frame = page.locator('[data-html-draft-preview-frame]');
  await expect(frame).toHaveAttribute('sandbox', 'allow-scripts');
  await expect(frame).toHaveAttribute('allow', '');
  await expect(frame).not.toHaveAttribute('sandbox', /allow-same-origin/u);
  await openAuthenticatedDraftPreview(page);

  // The draft renders from the artifact host origin (form POST into the iframe),
  // never inline via srcdoc/blob/data on the app origin.
  await expect(frame).not.toHaveAttribute('srcdoc', /.*/u);
  await expect(frame).not.toHaveAttribute('src', /^(?:blob|data):/u);
  await expect(page.frameLocator('[data-html-draft-preview-frame]').locator('#result')).toHaveText(
    'parent-blocked network-blocked cookies-blocked storage-blocked rtc-blocked',
    { timeout: 20_000 },
  );
  await expect(page.locator('body')).not.toHaveAttribute('data-artifactflow-preview-owned', 'yes');
  await expect(page.locator('[data-html-draft-preview-status]')).toHaveText(
    'Draft preview running in the isolated sandbox.',
  );
  expect(outboundRequests).toBe(0);
  expect(leakedConsoleMessages).toEqual([]);
});

test('HTML draft preview blocks recursively nested browsing contexts before WebRTC can escape', async ({
  page,
}) => {
  test.setTimeout(120_000);

  let udpPacketCount = 0;
  const udpProbe = createSocket('udp4');
  udpProbe.on('message', () => {
    udpPacketCount += 1;
  });
  await new Promise<void>((resolve, reject) => {
    udpProbe.once('error', reject);
    udpProbe.bind(0, '127.0.0.1', () => {
      udpProbe.off('error', reject);
      resolve();
    });
  });

  try {
    const address = udpProbe.address();
    expect(typeof address).toBe('object');

    if (typeof address === 'string') {
      throw new Error('Expected an IPv4 UDP probe address.');
    }

    const rtcLeaf = `<!doctype html><script>
      const peer = new RTCPeerConnection({
        iceServers: [{ urls: 'stun:127.0.0.1:${address.port}' }],
      });
      peer.createDataChannel('artifactflow-probe');
      peer.createOffer().then((offer) => peer.setLocalDescription(offer));
    </script>`;
    const nestingDepth = 15;
    let recursivelyNestedRtc = rtcLeaf;

    for (let depth = 0; depth < nestingDepth; depth += 1) {
      recursivelyNestedRtc = `<!doctype html><iframe data-depth="${depth + 1}" srcdoc="${escapeHtmlAttribute(recursivelyNestedRtc)}"></iframe>`;
    }

    const staticNestedFrame = recursivelyNestedRtc;
    const rawTextBreakoutFrame =
      '<iframe></template>' +
      `<iframe data-breakout-context="raw-text-template-close" srcdoc="${escapeHtmlAttribute(rtcLeaf)}"></iframe>` +
      '</iframe>';
    const alternateCommentEndBreakoutFrames =
      '<!-- --!>' +
      `<iframe data-breakout-context="comment-end-bang" srcdoc="${escapeHtmlAttribute(rtcLeaf)}"></iframe>` +
      '<!-->' +
      `<iframe data-breakout-context="abrupt-empty-comment" srcdoc="${escapeHtmlAttribute(rtcLeaf)}"></iframe>`;
    const declarationBreakoutFrames =
      '<!x=">' +
      `<iframe data-breakout-context="bogus-comment" srcdoc="${escapeHtmlAttribute(rtcLeaf)}">"></iframe>` +
      '<?xml x=">' +
      `<iframe data-breakout-context="processing-instruction" srcdoc="${escapeHtmlAttribute(rtcLeaf)}">"></iframe>` +
      '<!DOCTYPE html PUBLIC ">' +
      `<iframe data-breakout-context="abrupt-doctype" srcdoc="${escapeHtmlAttribute(rtcLeaf)}">"></iframe>` +
      '<![CDATA[">' +
      `<iframe data-breakout-context="html-cdata" srcdoc="${escapeHtmlAttribute(rtcLeaf)}">"></iframe>`;
    const malformedAttributeNameQuoteBreakoutFrames =
      `<div '><iframe data-breakout-context="single-quote-attribute-name" srcdoc="${escapeHtmlAttribute(rtcLeaf)}"></iframe>'></div>` +
      `<div "><iframe data-breakout-context="double-quote-attribute-name" srcdoc="${escapeHtmlAttribute(rtcLeaf)}"></iframe>"></div>` +
      `<div ='><iframe data-breakout-context="leading-equals-single-quote-attribute-name" srcdoc="${escapeHtmlAttribute(rtcLeaf)}"></iframe>'></div>` +
      `<div ="><iframe data-breakout-context="leading-equals-double-quote-attribute-name" srcdoc="${escapeHtmlAttribute(rtcLeaf)}"></iframe>"></div>`;
    const unmatchedForeignEndBreakoutFrame = `<svg></math><title><iframe data-breakout-context="unmatched-foreign-end" srcdoc="${escapeHtmlAttribute(rtcLeaf)}"></iframe></title></svg>`;
    const dynamicNestedFrameBase64 = Buffer.from(recursivelyNestedRtc, 'utf8').toString('base64');
    const declarativeShadowOpenBase64 = Buffer.from(
      `<div data-declarative-shadow-host="open"><template shadowrootmode="open"><iframe srcdoc="${escapeHtmlAttribute(rtcLeaf)}"></iframe></template></div>`,
      'utf8',
    ).toString('base64');
    const declarativeShadowClosedBase64 = Buffer.from(
      `<div data-declarative-shadow-host="closed"><template shadowrootmode="closed"><iframe srcdoc="${escapeHtmlAttribute(rtcLeaf)}"></iframe></template></div>`,
      'utf8',
    ).toString('base64');
    const fixture = await prepareAuthenticatedDraftPreviewFixture(page);

    await page.setContent(
      authenticatedDraftPreviewDocument(
        fixture,
        `<!doctype html>
              <p id="nested-result">starting</p>
              ${staticNestedFrame}
              ${rawTextBreakoutFrame}
              ${alternateCommentEndBreakoutFrames}
              ${declarationBreakoutFrames}
              ${malformedAttributeNameQuoteBreakoutFrames}
              ${unmatchedForeignEndBreakoutFrame}
              <script>
                const nestedMarkup = atob('${dynamicNestedFrameBase64}');
                const declarativeShadowOpenMarkup = atob('${declarativeShadowOpenBase64}');
                const declarativeShadowClosedMarkup = atob('${declarativeShadowClosedBase64}');
                const dynamicOuter = document.createElement('iframe');
                dynamicOuter.id = 'dynamic-create-element';
                dynamicOuter.srcdoc = nestedMarkup;
                document.body.append(dynamicOuter);

                const namespacedOuter = document.createElementNS(
                  'http://www.w3.org/1999/xhtml',
                  'iframe',
                );
                namespacedOuter.id = 'dynamic-create-element-ns';
                namespacedOuter.srcdoc = nestedMarkup;
                document.body.append(namespacedOuter);

                const prefixedNamespacedOuter = document.createElementNS(
                  'http://www.w3.org/1999/xhtml',
                  'x:iframe',
                );
                const prefixedNamespaceBlocked =
                  !(prefixedNamespacedOuter instanceof HTMLIFrameElement);
                prefixedNamespacedOuter.setAttribute('data-bypass-context', 'namespace-prefix');
                prefixedNamespacedOuter.setAttribute('srcdoc', nestedMarkup);
                document.body.append(prefixedNamespacedOuter);

                const innerHtmlHost = document.createElement('div');
                innerHtmlHost.innerHTML = nestedMarkup;
                document.body.append(innerHtmlHost);

                const coercionHost = document.createElement('div');
                coercionHost.innerHTML = {
                  toString() {
                    return nestedMarkup;
                  },
                };
                const objectCoercionBlocked =
                  coercionHost.querySelector('iframe, frame, fencedframe, portal') === null;
                document.body.append(coercionHost);
                document.body.insertAdjacentHTML('beforeend', nestedMarkup);

                const outerHtmlHost = document.createElement('div');
                document.body.append(outerHtmlHost);
                outerHtmlHost.outerHTML = nestedMarkup;

                const range = document.createRange();
                range.selectNodeContents(document.body);
                document.body.append(range.createContextualFragment(nestedMarkup));

                const parsed = new DOMParser().parseFromString(nestedMarkup, 'text/html');
                const parsedContext = parsed.body.firstElementChild;
                if (parsedContext !== null) {
                  document.body.append(document.importNode(parsedContext, true));
                }

                const xhtmlParsed = new DOMParser().parseFromString(
                  '<html xmlns="http://www.w3.org/1999/xhtml"><body><iframe srcdoc=""></iframe></body></html>',
                  'application/xhtml+xml',
                );
                const xhtmlFrame = xhtmlParsed.getElementsByTagName('iframe')[0];
                let surroundContentsBlocked = false;
                if (xhtmlFrame !== undefined) {
                  const importedFrame = document.importNode(xhtmlFrame, true);
                  importedFrame.setAttribute('srcdoc', nestedMarkup);
                  const surroundHolder = document.createElement('div');
                  surroundHolder.append('surround-range');
                  document.body.append(surroundHolder);
                  const surroundRange = document.createRange();
                  surroundRange.selectNodeContents(surroundHolder);
                  surroundRange.surroundContents(importedFrame);
                  // Check synchronously: a MutationObserver cleanup on the next
                  // microtask is too late to prevent a newly connected srcdoc realm.
                  surroundContentsBlocked = !importedFrame.isConnected;
                }

                const scratch = document.implementation.createHTMLDocument('scratch');
                scratch.write(nestedMarkup);
                let detachedNestedContextCount = scratch.querySelectorAll(
                  'iframe, frame, fencedframe, portal',
                ).length;
                const writtenContext = scratch.body?.firstElementChild ?? null;
                if (writtenContext !== null) {
                  document.body.append(document.importNode(writtenContext, true));
                }

                const splitWriteScratch = document.implementation.createHTMLDocument('split-write');
                const splitAt = nestedMarkup.indexOf('>', nestedMarkup.indexOf('<iframe'));
                splitWriteScratch.write(nestedMarkup.slice(0, splitAt));
                splitWriteScratch.write(nestedMarkup.slice(splitAt));
                detachedNestedContextCount += splitWriteScratch.querySelectorAll(
                  'iframe, frame, fencedframe, portal',
                ).length;

                let xsltState = 'xslt-unavailable';
                if (typeof XSLTProcessor === 'function') {
                  try {
                    const stylesheet = new DOMParser().parseFromString(
                      '<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"><xsl:template match="/"><xsl:element name="iframe"><xsl:attribute name="srcdoc"><xsl:value-of select="/payload"/></xsl:attribute></xsl:element></xsl:template></xsl:stylesheet>',
                      'application/xml',
                    );
                    const input = document.implementation.createDocument('', 'payload');
                    input.documentElement.textContent = nestedMarkup;
                    const processor = new XSLTProcessor();
                    processor.importStylesheet(stylesheet);
                    document.body.append(processor.transformToFragment(input, document));
                    xsltState = 'xslt-enabled';
                  } catch {
                    xsltState = 'xslt-blocked';
                  }
                }

                const shadowHost = document.createElement('div');
                document.body.append(shadowHost);
                const shadowRoot = shadowHost.attachShadow({ mode: 'open' });
                shadowRoot.innerHTML = nestedMarkup;

                const elementUnsafeSetterIsNoop = (markup, sentinel) => {
                  const target = document.createElement('div');
                  target.textContent = sentinel;
                  document.body.append(target);

                  if (typeof target.setHTMLUnsafe !== 'function') {
                    return false;
                  }

                  try {
                    target.setHTMLUnsafe(markup);

                    return target.textContent === sentinel && target.childElementCount === 0;
                  } catch {
                    return false;
                  }
                };
                const shadowUnsafeSetterIsNoop = (markup, sentinel) => {
                  const host = document.createElement('div');
                  document.body.append(host);
                  const target = host.attachShadow({ mode: 'open' });
                  target.textContent = sentinel;

                  if (typeof target.setHTMLUnsafe !== 'function') {
                    return false;
                  }

                  try {
                    target.setHTMLUnsafe(markup);

                    return target.textContent === sentinel && target.childElementCount === 0;
                  } catch {
                    return false;
                  }
                };
                const unsafeElementSettersBlocked =
                  elementUnsafeSetterIsNoop(
                    declarativeShadowOpenMarkup,
                    'element-open-sentinel',
                  ) &&
                  elementUnsafeSetterIsNoop(
                    declarativeShadowClosedMarkup,
                    'element-closed-sentinel',
                  );
                const unsafeShadowSettersBlocked =
                  shadowUnsafeSetterIsNoop(
                    declarativeShadowOpenMarkup,
                    'shadow-open-sentinel',
                  ) &&
                  shadowUnsafeSetterIsNoop(
                    declarativeShadowClosedMarkup,
                    'shadow-closed-sentinel',
                  );

                let unsafeDocumentParserBlocked = true;
                let closedShadowParserBlocked = true;
                if (typeof Document.parseHTMLUnsafe === 'function') {
                  try {
                    const unsafeParsed = Document.parseHTMLUnsafe(
                      '<!doctype html><div id="declarative-shadow-host"><template shadowrootmode="open"><iframe data-declarative-shadow-context></iframe></template></div>',
                    );
                    const declarativeShadowHost = unsafeParsed.getElementById(
                      'declarative-shadow-host',
                    );

                    if (declarativeShadowHost !== null) {
                      document.body.append(document.adoptNode(declarativeShadowHost));
                      const declarativeShadowFrame = declarativeShadowHost.shadowRoot?.querySelector(
                        '[data-declarative-shadow-context]',
                      );
                      unsafeDocumentParserBlocked =
                        declarativeShadowFrame === null ||
                        declarativeShadowFrame === undefined ||
                        declarativeShadowFrame.contentWindow === null;
                    }
                  } catch {
                    unsafeDocumentParserBlocked = true;
                  }

                  try {
                    const closedShadowParsed = Document.parseHTMLUnsafe(
                      '<!doctype html><div><template shadowrootmode="closed"><iframe></iframe></template></div>',
                    );
                    // A closed shadow root cannot be inspected after parsing. The unsafe
                    // document parser must therefore be disabled rather than cleaned up later.
                    closedShadowParserBlocked = closedShadowParsed === undefined;
                  } catch {
                    closedShadowParserBlocked = true;
                  }
                }

                const execCommandHost = document.createElement('div');
                execCommandHost.contentEditable = 'true';
                execCommandHost.textContent = 'selection';
                document.body.append(execCommandHost);
                const execCommandSelection = getSelection();
                const execCommandRange = document.createRange();
                execCommandRange.selectNodeContents(execCommandHost);
                execCommandSelection.removeAllRanges();
                execCommandSelection.addRange(execCommandRange);
                document.execCommand('insertHTML', false, nestedMarkup);
                const execCommandNestedContextBlocked =
                  execCommandHost.querySelector('iframe, frame, fencedframe, portal') === null;

                setTimeout(() => {
                  const nestedContextCount =
                    detachedNestedContextCount +
                    document.querySelectorAll('iframe, frame, fencedframe, portal').length +
                    shadowRoot.querySelectorAll('iframe, frame, fencedframe, portal').length;
                  document.getElementById('nested-result').textContent =
                    nestedContextCount === 0 &&
                    prefixedNamespaceBlocked &&
                    objectCoercionBlocked &&
                    surroundContentsBlocked &&
                    unsafeElementSettersBlocked &&
                    unsafeShadowSettersBlocked &&
                    unsafeDocumentParserBlocked &&
                    closedShadowParserBlocked &&
                    execCommandNestedContextBlocked &&
                    xsltState !== 'xslt-enabled'
                      ? 'nested-contexts-blocked'
                      : 'nested-contexts-present';
                }, 250);
              </script>`,
      ),
    );

    await openAuthenticatedDraftPreview(page);
    const preview = page.frameLocator('[data-html-draft-preview-frame]');
    await expect(preview.locator('#nested-result')).toHaveText('nested-contexts-blocked', {
      timeout: 20_000,
    });
    await expect(preview.locator('iframe, frame, fencedframe, portal')).toHaveCount(0);
    await page.waitForTimeout(1_500);
    expect(udpPacketCount).toBe(0);
  } finally {
    await new Promise<void>((resolve) => udpProbe.close(() => resolve()));
  }
});

test('HTML draft preview refuses external script sources while inline scripts run', async ({
  page,
}) => {
  test.setTimeout(120_000);

  let externalScriptRequests = 0;
  const fixture = await prepareAuthenticatedDraftPreviewFixture(page);

  await page.route('**/draft-preview-external-script.js', async (route) => {
    externalScriptRequests += 1;
    await route.fulfill({
      contentType: 'text/javascript',
      body: "document.getElementById('result').textContent = 'external-ran';",
    });
  });
  await page.setContent(
    authenticatedDraftPreviewDocument(
      fixture,
      `<!doctype html>
            <p id="result">starting</p>
            <script>
              document.getElementById('result').textContent = 'inline-ran';
            </script>
            <script src="${baseUrl}/draft-preview-external-script.js" nonce="${fixture.cspNonce}"></script>`,
    ),
  );

  await openAuthenticatedDraftPreview(page);

  await expect(page.frameLocator('[data-html-draft-preview-frame]').locator('#result')).toHaveText(
    'inline-ran',
    { timeout: 20_000 },
  );
  // The artifact-host CSP (script-src 'unsafe-inline', no external hosts) blocks
  // the external script before any request leaves the browser.
  expect(externalScriptRequests).toBe(0);
});

test('HTML draft preview renders inline styles like the saved artifact', async ({ page }) => {
  test.setTimeout(120_000);

  // Regression: the draft used to render via `srcdoc` on the app origin, which
  // inherits the app CSP (`style-src 'self' 'nonce-…'`, no unsafe-inline) and
  // silently dropped the artifact's inline styles. Rendering from the artifact
  // host origin gives the draft the same permissive sandbox CSP as a saved
  // artifact, so inline <style> and style="" attributes apply.
  const fixture = await prepareAuthenticatedDraftPreviewFixture(page);

  await page.setContent(
    authenticatedDraftPreviewDocument(
      fixture,
      `<!doctype html>
            <html>
              <head><style>#styled { background-color: rgb(9, 8, 7); }</style></head>
              <body>
                <p id="styled">styled by inline stylesheet</p>
                <p id="attr" style="color: rgb(1, 2, 3);">styled by attribute</p>
              </body>
            </html>`,
    ),
  );

  await openAuthenticatedDraftPreview(page);

  const styledByStylesheet = page
    .frameLocator('[data-html-draft-preview-frame]')
    .locator('#styled');
  const styledByAttribute = page.frameLocator('[data-html-draft-preview-frame]').locator('#attr');

  await expect(styledByStylesheet).toBeVisible({ timeout: 20_000 });
  await expect
    .poll(() => styledByStylesheet.evaluate((element) => getComputedStyle(element).backgroundColor))
    .toBe('rgb(9, 8, 7)');
  await expect
    .poll(() => styledByAttribute.evaluate((element) => getComputedStyle(element).color))
    .toBe('rgb(1, 2, 3)');
});
