import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const baseUrl = (process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:18180').replace(/\/$/u, '');
const repoRoot = fileURLToPath(new URL('../..', import.meta.url));
const appCommandTarget = process.env.E2E_APP_COMMAND_TARGET ?? 'run-e2e-app-cmd';

test.use({ screenshot: 'off', trace: 'off', video: 'off' });

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

function assertSavedPreviewSchemaReady(): void {
  runAppCommand(
    'php artisan tinker --execute="if (! Illuminate\\\\Support\\\\Facades\\\\Schema::hasColumn(\\"pages\\", \\"search_vector\\") || ! Illuminate\\\\Support\\\\Facades\\\\Schema::hasTable(\\"installation_settings\\")) { throw new RuntimeException(\\"Missing saved-preview e2e schema\\"); }"',
    'Saved artifact preview e2e requires pages.search_vector and installation_settings in the isolated e2e database. Refresh the e2e database schema before running this browser test.',
  );
}

async function login(page: Page, email: string, password: string): Promise<void> {
  await page.goto(`${baseUrl}/login`, { waitUntil: 'networkidle' });
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/dashboard$/u);
}

test('saved HTML artifact executes only inside the controller-served sandbox', async ({ page }) => {
  // This flow is heavy: a fetch-based save that rebuilds the document, then a
  // cross-origin signed-URL iframe that must load and execute the artifact. On
  // CI that comfortably exceeds the default per-test timeout.
  test.setTimeout(90_000);

  const runSuffix = randomUUID().replaceAll('-', '').slice(0, 12);
  const email = `artifact-preview-e2e-${runSuffix}@example.test`;
  const password = `af${randomUUID().replaceAll('-', '')}`;
  const title = `Saved preview sandbox ${runSuffix}`;
  const leakedConsoleMessages: string[] = [];
  let outboundRequests = 0;
  let navigationRequests = 0;

  assertSavedPreviewSchemaReady();
  runAppCommand(
    `php artisan artifactflow:create-user --name=SavedPreviewE2E --email=${email} --password=${password}`,
    'Failed to prepare the saved artifact preview e2e account.',
  );

  page.on('console', (message) => {
    if (message.text().includes('artifactflow-saved-console-leak')) {
      leakedConsoleMessages.push(message.text());
    }
  });

  await page.route('**/saved-preview-network-check', async (route) => {
    outboundRequests += 1;
    await route.abort();
  });
  await page.route('**/saved-preview-navigation-check**', async (route) => {
    navigationRequests += 1;
    await route.abort();
  });

  await login(page, email, password);
  await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'networkidle' });

  const editorForm = page.locator('[data-content-editor]');
  await expect(editorForm).toHaveAttribute('data-editor-ready', 'true');

  await page.locator('select[name="type"]').selectOption('html_artifact');
  await page.locator('select[name="mode"]').selectOption('html_paste');
  await page.locator('input[name="title"]').fill(title);

  // Wait for the async CodeMirror language switch to fully settle before typing,
  // otherwise the trailing setValue() can clobber the inserted content on slow runs.
  await expect(editorForm).toHaveAttribute('data-editor-language', 'html');
  await expect(editorForm).toHaveAttribute('data-editor-ready', 'true');

  const sourceEditor = page.locator('[data-source-editor-mount] .cm-content');
  await expect(sourceEditor).toBeVisible();
  await sourceEditor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.insertText(`<!doctype html>
<html>
  <head>
    <title>Saved preview sandbox</title>
    <meta http-equiv="refresh" content="0; url=${baseUrl}/saved-preview-navigation-check?via=meta">
  </head>
  <body>
    <p id="result">starting</p>
    <script>
      console.log('artifactflow-saved-console-leak');

      let parentState = 'parent-accessible';
      try {
        window.parent.document.body.dataset.artifactflowSavedPreviewOwned = 'yes';
      } catch {
        parentState = 'parent-blocked';
      }

      let cookieState = 'cookies-accessible';
      try {
        document.cookie = 'artifactflow_saved_preview_cookie=yes';
        cookieState = document.cookie === '' ? 'cookies-blocked' : 'cookies-accessible';
      } catch {
        cookieState = 'cookies-blocked';
      }

      let storageState = 'storage-accessible';
      try {
        localStorage.setItem('artifactflow_saved_preview_storage', 'yes');
        storageState = localStorage.getItem('artifactflow_saved_preview_storage') === null
          ? 'storage-blocked'
          : 'storage-accessible';
      } catch {
        storageState = 'storage-blocked';
      }

      let unsafeParserState = 'unsafe-parser-blocked';
      if (typeof Document.parseHTMLUnsafe === 'function') {
        let openShadowParserBlocked = true;
        let closedShadowParserBlocked = true;

        try {
          const unsafeParsed = Document.parseHTMLUnsafe(
            '<!doctype html><div id="declarative-shadow-host"><template shadowrootmode="open"><iframe data-declarative-shadow-context></iframe></template></div>',
          );
          const declarativeShadowHost = unsafeParsed.getElementById('declarative-shadow-host');

          if (declarativeShadowHost !== null) {
            document.body.append(document.adoptNode(declarativeShadowHost));
            const declarativeShadowFrame = declarativeShadowHost.shadowRoot?.querySelector(
              '[data-declarative-shadow-context]',
            );
            openShadowParserBlocked =
              declarativeShadowFrame === null ||
              declarativeShadowFrame === undefined ||
              declarativeShadowFrame.contentWindow === null;
          }
        } catch {
          openShadowParserBlocked = true;
        }

        try {
          const closedShadowParsed = Document.parseHTMLUnsafe(
            '<!doctype html><div><template shadowrootmode="closed"><iframe></iframe></template></div>',
          );
          closedShadowParserBlocked = closedShadowParsed === undefined;
        } catch {
          closedShadowParserBlocked = true;
        }

        unsafeParserState =
          openShadowParserBlocked && closedShadowParserBlocked
            ? 'unsafe-parser-blocked'
            : 'unsafe-parser-escaped';
      }

      const declarativeShadowOpenMarkup =
        '<div data-declarative-shadow-host="open"><template shadowrootmode="open"><iframe></iframe></template></div>';
      const declarativeShadowClosedMarkup =
        '<div data-declarative-shadow-host="closed"><template shadowrootmode="closed"><iframe></iframe></template></div>';
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
      const unsafeSetterState =
        elementUnsafeSetterIsNoop(declarativeShadowOpenMarkup, 'element-open-sentinel') &&
        elementUnsafeSetterIsNoop(declarativeShadowClosedMarkup, 'element-closed-sentinel') &&
        shadowUnsafeSetterIsNoop(declarativeShadowOpenMarkup, 'shadow-open-sentinel') &&
        shadowUnsafeSetterIsNoop(declarativeShadowClosedMarkup, 'shadow-closed-sentinel')
          ? 'unsafe-setters-blocked'
          : 'unsafe-setters-escaped';

      fetch('${baseUrl}/saved-preview-network-check')
        .then(() => {
          document.getElementById('result').textContent = [
            parentState,
            'network-accessible',
            cookieState,
            storageState,
            unsafeParserState,
            unsafeSetterState,
          ].join(' ');
        })
        .catch(() => {
          document.getElementById('result').textContent = [
            parentState,
            'network-blocked',
            cookieState,
            storageState,
            unsafeParserState,
            unsafeSetterState,
          ].join(' ');
        });

      window.attemptSavedPreviewNavigation = () => {
        window.location.href = '${baseUrl}/saved-preview-navigation-check?via=script&leak='
          + encodeURIComponent(document.body.innerText);
      };
    </script>
  </body>
</html>`);

  // Guard against a lost editor sync: the content must be present before saving,
  // otherwise the redirect assertion below fails opaquely on an empty-content error.
  await expect(sourceEditor).toContainText('saved-preview-navigation-check');
  await expect(page.locator('[data-editor-textarea]')).toHaveValue(
    /saved-preview-navigation-check/u,
  );

  await page.getByRole('button', { name: 'Save page' }).click();

  // The save posts via fetch and rebuilds the document; the preview then loads
  // a signed URL cross-origin and runs the artifact. Both steps are far slower
  // on CI than the 5s default expect timeout, so wait for them explicitly.
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u, { timeout: 20_000 });
  await expect(page.getByRole('heading', { name: title })).toBeVisible({ timeout: 20_000 });

  const frame = page.locator('iframe[title="Artifact preview"]');
  await expect(frame).toHaveAttribute('sandbox', 'allow-scripts');
  await expect(frame).toHaveAttribute('allow', '');
  await expect(frame).not.toHaveAttribute('sandbox', /allow-same-origin/u);

  const previewResult = page.frameLocator('iframe[title="Artifact preview"]').locator('#result');
  await expect(previewResult).toBeAttached({ timeout: 20_000 });
  await expect(previewResult).toHaveText(
    'parent-blocked network-blocked cookies-blocked storage-blocked unsafe-parser-blocked unsafe-setters-blocked',
    { timeout: 20_000 },
  );
  await expect(page.locator('body')).not.toHaveAttribute(
    'data-artifactflow-saved-preview-owned',
    'yes',
  );
  expect(outboundRequests).toBe(0);
  expect(navigationRequests).toBe(0);
  expect(leakedConsoleMessages).toEqual([]);

  // A prototype may deliberately call location.reload(). A valid signed URL
  // reloads the child normally and must never navigate the authenticated parent.
  const initialPreviewUrl = await frame.getAttribute('src');
  const parentPageUrl = page.url();
  const artifactFrame = await (await frame.elementHandle())?.contentFrame();
  expect(initialPreviewUrl).not.toBeNull();
  expect(artifactFrame).toBeDefined();

  // Observe navigation from the authenticated parent. Waiting on content in the
  // artifact document is racy because reload/self-navigation can replace that
  // document after Playwright has already resolved a locator against the old one.
  await frame.evaluate((iframe) => {
    if (!(iframe instanceof HTMLIFrameElement)) {
      throw new Error('Expected the artifact preview iframe.');
    }

    iframe.dataset.e2eArtifactLoadCount = '0';
    iframe.addEventListener('load', () => {
      const loadCount = Number.parseInt(iframe.dataset.e2eArtifactLoadCount ?? '0', 10);
      iframe.dataset.e2eArtifactLoadCount = String(loadCount + 1);
    });
  });
  const artifactLoadCount = async (): Promise<number> =>
    Number.parseInt((await frame.getAttribute('data-e2e-artifact-load-count')) ?? '0', 10);

  // Keep an unsaved edit open in the parent while the prototype requests its
  // refresh. Rotating the iframe must not navigate the application document or
  // discard the editor dialog's in-memory state.
  await page.getByRole('button', { name: 'Edit HTML source' }).click();
  const editDialog = page.locator('#html-source-editor');
  await expect(editDialog).toBeVisible();
  const savedSourceEditor = editDialog.locator('[data-source-editor-mount] .cm-content');
  await expect(savedSourceEditor).toBeVisible();
  await savedSourceEditor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.insertText('unsaved parent edit must survive preview refresh');
  await expect(editDialog.locator('[data-editor-textarea]')).toHaveValue(
    'unsaved parent edit must survive preview refresh',
  );

  const loadCountBeforeReload = await artifactLoadCount();
  await artifactFrame?.evaluate(() => window.location.reload());
  await expect.poll(artifactLoadCount, { timeout: 20_000 }).toBeGreaterThan(loadCountBeforeReload);
  expect(page.url()).toBe(parentPageUrl);
  await expect(editDialog).toBeVisible();
  await expect(editDialog.locator('[data-editor-textarea]')).toHaveValue(
    'unsaved parent edit must survive preview refresh',
  );

  // Simulate the same reload after its one-minute bearer URL expires without
  // making the suite sleep for the production TTL. The artifact host returns a
  // document without the ready signal; the authenticated parent then renews the
  // URL and changes only the iframe src.
  const expiredPreviewUrl = new URL(initialPreviewUrl ?? '');
  expiredPreviewUrl.searchParams.set('expires', '1');
  expiredPreviewUrl.searchParams.set('signature', 'expired-for-browser-regression');
  const loadCountBeforeRecovery = await artifactLoadCount();
  const recoveryResponse = page.waitForResponse(
    (response) =>
      response.request().method() === 'GET' &&
      /\/pages\/[0-9a-hjkmnp-tv-z]{26}\/artifact-preview-url$/u.test(
        new URL(response.url()).pathname,
      ),
    { timeout: 20_000 },
  );
  await frame.evaluate((iframe, url) => {
    if (iframe instanceof HTMLIFrameElement) {
      iframe.src = url;
    }
  }, expiredPreviewUrl.toString());
  expect((await recoveryResponse).ok()).toBe(true);
  await expect
    .poll(() => frame.getAttribute('src'), { timeout: 20_000 })
    .not.toBe(expiredPreviewUrl.toString());
  await expect
    .poll(artifactLoadCount, { timeout: 20_000 })
    .toBeGreaterThanOrEqual(loadCountBeforeRecovery + 2);
  expect(page.url()).toBe(parentPageUrl);
  await expect(editDialog).toBeVisible();
  await expect(editDialog.locator('[data-editor-textarea]')).toHaveValue(
    'unsaved parent edit must survive preview refresh',
  );

  // Finish with the existing hostile self-navigation probe. It may detach the
  // child document after CSP blocks it, so it deliberately runs after the
  // refresh and parent-state assertions above.
  await artifactFrame?.evaluate(() => {
    const attemptNavigation = Reflect.get(window, 'attemptSavedPreviewNavigation');

    if (typeof attemptNavigation === 'function') {
      attemptNavigation();
    }
  });
  await page.waitForTimeout(1750);
  expect(navigationRequests).toBe(0);
  expect(page.url()).toBe(parentPageUrl);
});

test('historical HTML versions stay inside the artifact-origin sandbox', async ({ page }) => {
  test.setTimeout(90_000);

  const runSuffix = randomUUID().replaceAll('-', '').slice(0, 12);
  const email = `artifact-history-e2e-${runSuffix}@example.test`;
  const password = `af${randomUUID().replaceAll('-', '')}`;
  const title = `Historical preview sandbox ${runSuffix}`;
  let outboundRequests = 0;

  assertSavedPreviewSchemaReady();
  runAppCommand(
    `php artisan artifactflow:create-user --name=ArtifactHistoryE2E --email=${email} --password=${password}`,
    'Failed to prepare the historical artifact e2e account.',
  );

  await page.route('**/historical-preview-network-check', async (route) => {
    outboundRequests += 1;
    await route.abort();
  });

  await login(page, email, password);
  await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'networkidle' });

  const createEditor = page.locator('[data-content-editor]');
  await expect(createEditor).toHaveAttribute('data-editor-ready', 'true');
  await page.locator('select[name="type"]').selectOption('html_artifact');
  await page.locator('select[name="mode"]').selectOption('html_paste');
  await page.locator('input[name="title"]').fill(title);
  await expect(createEditor).toHaveAttribute('data-editor-language', 'html');
  await expect(createEditor).toHaveAttribute('data-editor-ready', 'true');

  const createSource = page.locator('[data-source-editor-mount] .cm-content');
  await createSource.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.insertText(`<!doctype html>
<html>
  <body>
    <p id="history-result">starting</p>
    <script>
      let parentState = 'parent-accessible';
      try {
        window.parent.document.body.dataset.artifactflowHistoricalPreviewOwned = 'yes';
      } catch {
        parentState = 'parent-blocked';
      }

      let storageState = 'storage-accessible';
      try {
        localStorage.setItem('artifactflow_historical_preview_storage', 'yes');
        storageState = localStorage.getItem('artifactflow_historical_preview_storage') === null
          ? 'storage-blocked'
          : 'storage-accessible';
      } catch {
        storageState = 'storage-blocked';
      }

      fetch('${baseUrl}/historical-preview-network-check')
        .then(() => {
          document.getElementById('history-result').textContent = [
            parentState,
            'network-accessible',
            storageState,
          ].join(' ');
        })
        .catch(() => {
          document.getElementById('history-result').textContent = [
            parentState,
            'network-blocked',
            storageState,
          ].join(' ');
        });
    </script>
  </body>
</html>`);
  await expect(page.locator('[data-editor-textarea]')).toHaveValue(/history-result/u);
  await page.getByRole('button', { name: 'Save page' }).click();

  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u, { timeout: 20_000 });
  await expect(page.getByRole('heading', { name: title })).toBeVisible({ timeout: 20_000 });
  await page.waitForLoadState('networkidle');

  await page.getByRole('button', { name: 'Edit HTML source' }).click();
  const editDialog = page.locator('#html-source-editor');
  await expect(editDialog).toBeVisible();
  const editSource = editDialog.locator('[data-source-editor-mount] .cm-content');
  await editSource.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.insertText(
    '<!doctype html><html><body><h1>Current artifact</h1></body></html>',
  );
  await expect(editDialog.locator('[data-editor-textarea]')).toHaveValue(/Current artifact/u);
  await editDialog.getByRole('button', { name: 'Save new version' }).click();

  await expect(editDialog).toBeHidden({ timeout: 20_000 });
  await expect(page.getByRole('heading', { name: title })).toBeVisible({ timeout: 20_000 });
  await page.waitForLoadState('networkidle');
  await expect(
    page.frameLocator('iframe[title="Artifact preview"]').getByRole('heading', {
      name: 'Current artifact',
    }),
  ).toBeVisible({ timeout: 20_000 });
  await page.getByRole('button', { name: 'Versions' }).click();
  const historyDialog = page.locator('#page-versions-dialog');
  await expect(historyDialog).toBeVisible();
  const versionOne = historyDialog.locator('article').filter({ hasText: 'Version 1' });
  await versionOne.getByRole('link', { name: 'Inspect' }).click();

  await expect(page).toHaveURL(
    /\/pages\/[0-9a-hjkmnp-tv-z]{26}\/versions\/[0-9a-hjkmnp-tv-z]{26}$/iu,
  );
  await expect(page.getByText('Historical version 1')).toBeVisible();

  const frame = page.locator('iframe[title="Historical artifact preview"]');
  await expect(frame).toHaveAttribute('sandbox', 'allow-scripts');
  await expect(frame).toHaveAttribute('allow', '');
  await expect(frame).not.toHaveAttribute('sandbox', /allow-same-origin/u);
  await expect(
    page.frameLocator('iframe[title="Historical artifact preview"]').locator('#history-result'),
  ).toHaveText('parent-blocked network-blocked storage-blocked', { timeout: 20_000 });
  await expect(page.locator('body')).not.toHaveAttribute(
    'data-artifactflow-historical-preview-owned',
    'yes',
  );
  expect(outboundRequests).toBe(0);
});

test('preview URL renewal is capped so a self-navigating artifact cannot drain the viewer rate limit', async ({
  page,
}) => {
  // Same heavy save + cross-origin signed-URL load as the isolation test, plus a
  // multi-second observation window while the artifact loops.
  test.setTimeout(90_000);

  const runSuffix = randomUUID().replaceAll('-', '').slice(0, 12);
  const email = `artifact-refresh-cap-e2e-${runSuffix}@example.test`;
  const password = `af${randomUUID().replaceAll('-', '')}`;
  const title = `Refresh cap sandbox ${runSuffix}`;

  assertSavedPreviewSchemaReady();
  runAppCommand(
    `php artisan artifactflow:create-user --name=RefreshCapE2E --email=${email} --password=${password}`,
    'Failed to prepare the refresh-cap e2e account.',
  );

  // Count every renewal the parent requests from the authenticated app endpoint.
  let renewalRequests = 0;
  page.on('request', (request) => {
    if (
      /\/pages\/[0-9a-hjkmnp-tv-z]{26}\/artifact-preview-url$/u.test(
        new URL(request.url()).pathname,
      )
    ) {
      renewalRequests += 1;
    }
  });

  await login(page, email, password);
  await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'networkidle' });

  const editorForm = page.locator('[data-content-editor]');
  await expect(editorForm).toHaveAttribute('data-editor-ready', 'true');

  await page.locator('select[name="type"]').selectOption('html_artifact');
  await page.locator('select[name="mode"]').selectOption('html_paste');
  await page.locator('input[name="title"]').fill(title);

  await expect(editorForm).toHaveAttribute('data-editor-language', 'html');
  await expect(editorForm).toHaveAttribute('data-editor-ready', 'true');

  const sourceEditor = page.locator('[data-source-editor-mount] .cm-content');
  await expect(sourceEditor).toBeVisible();
  await sourceEditor.click();
  await page.keyboard.press('ControlOrMeta+A');
  // This artifact settles (emits the ready signal on load) and then self-navigates
  // ~250ms later. Each cycle is therefore a settled load followed by a fresh
  // child-initiated navigation, which drives one renewal — the sustained drain
  // loop. An unthrottled client would mint a fresh signed URL on every cycle for
  // as long as the tab stays open; the throttle bounds it to one per interval.
  await page.keyboard.insertText(`<!doctype html>
<html>
  <head><title>Refresh cap sandbox</title></head>
  <body>
    <p id="result">looping</p>
    <script>
      window.addEventListener('load', () => {
        window.setTimeout(() => { window.location.href = 'about:blank'; }, 250);
      });
    </script>
  </body>
</html>`);

  await expect(page.locator('[data-editor-textarea]')).toHaveValue(/about:blank/u);
  await page.getByRole('button', { name: 'Save page' }).click();

  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u, { timeout: 20_000 });
  await expect(page.getByRole('heading', { name: title })).toBeVisible({ timeout: 20_000 });

  // Observe several self-navigation cycles. The loop must engage (at least one
  // renewal fires) yet stay bounded by the minimum renewal interval: an
  // unthrottled client would issue a renewal on every ~250ms cycle (a dozen or
  // more across this window), while the throttle admits at most one per interval.
  await page.waitForTimeout(7_000);
  expect(renewalRequests).toBeGreaterThan(0);
  expect(renewalRequests).toBeLessThanOrEqual(3);
});
