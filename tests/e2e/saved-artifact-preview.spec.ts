import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { createServer } from 'node:net';
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
  await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/dashboard$/u);
}

test('saved image is normalized into a scriptless isolated preview @artifact-security', async ({
  page,
}) => {
  test.setTimeout(90_000);
  await page.clock.install({ time: new Date() });

  const runSuffix = randomUUID().replaceAll('-', '').slice(0, 12);
  const email = `image-preview-e2e-${runSuffix}@example.test`;
  const password = `af${randomUUID().replaceAll('-', '')}`;
  const title = `Image preview sandbox ${runSuffix}`;
  const payloadMarker = 'artifactflow-image-payload-must-disappear';
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAD0lEQVQImWNUSHhwQcIBAAkOAopyJglZAAAAAElFTkSuQmCC',
    'base64',
  );
  const hostileUpload = Buffer.concat([
    png,
    Buffer.from(`<script>parent.document.body.dataset.imagePayload="${payloadMarker}"</script>`),
  ]);
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

  assertSavedPreviewSchemaReady();
  runAppCommand(
    `php artisan artifactflow:create-user --name=ImagePreviewE2E --email=${email} --password=${password}`,
    'Failed to prepare the image preview e2e account.',
  );

  await login(page, email, password);
  await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'domcontentloaded' });

  await expect(page.locator('[data-create-page-form]')).toHaveAttribute(
    'data-create-page-mode-ready',
    'true',
  );
  await page.locator('select[name="mode"]').selectOption('image_upload');
  await expect(page.locator('select[name="type"]')).toHaveValue('image');
  await expect(page.locator('select[name="mode"]')).toHaveValue('image_upload');
  await page.locator('input[name="title"]').fill(title);
  await page.locator('textarea[name="description"]').fill('A searchable deployment screenshot.');
  await page.locator('input[name="image_file"]').setInputFiles({
    name: 'deployment.png',
    mimeType: 'image/png',
    buffer: hostileUpload,
  });

  const previewResponsePromise = page.waitForResponse(
    (response) =>
      response.request().resourceType() === 'document' &&
      new URL(response.url()).pathname.startsWith('/artifact-previews/'),
  );

  await page.getByRole('button', { name: 'Save page' }).click();
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u, { timeout: 20_000 });
  await expect(page.getByRole('heading', { name: title })).toBeVisible({ timeout: 20_000 });

  const frame = page.locator('iframe[title="Image preview"]');
  await expect(frame).toHaveAttribute('sandbox', '');
  await expect(frame).toHaveAttribute('allow', '');
  await expect(frame).toHaveAttribute('referrerpolicy', 'no-referrer');

  const previewResponse = await previewResponsePromise;
  const csp = await previewResponse.headerValue('content-security-policy');
  expect(csp).toContain("default-src 'none'");
  expect(csp).toContain("script-src 'none'");
  expect(csp).toContain("connect-src 'none'");
  expect(csp).toContain('sandbox');
  expect(csp).not.toContain('sandbox allow-scripts');
  expect(new URL(previewResponse.url()).origin).not.toBe(new URL(baseUrl).origin);

  const previewImage = page
    .frameLocator('iframe[title="Image preview"]')
    .locator('[data-artifactflow-image-preview]');
  await expect(previewImage).toBeVisible({ timeout: 20_000 });
  await expect
    .poll(() =>
      previewImage.evaluate((image) =>
        image instanceof HTMLImageElement
          ? [image.complete, image.naturalWidth, image.naturalHeight]
          : [false, 0, 0],
      ),
    )
    .toEqual([true, 2, 1]);
  await expect(
    page.frameLocator('iframe[title="Image preview"]').locator('html'),
  ).not.toContainText(payloadMarker);
  await expect(page.locator('body')).not.toHaveAttribute('data-image-payload', payloadMarker);

  // Expiry closes future loads; it must not turn an already-rendered image into
  // a perpetual full-body download. Advancing beyond half of the 60-second TTL
  // catches the former timer without adding a real-time delay to the suite.
  expect(renewalRequests).toBe(0);
  await page.clock.fastForward(31_000);
  await page.waitForTimeout(500);
  expect(renewalRequests).toBe(0);
});

test('saved HTML artifact executes only inside the controller-served sandbox @artifact-security', async ({
  page,
}) => {
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
  let speculativeConnectionCount = 0;
  const speculativeProbe = createServer((socket) => {
    speculativeConnectionCount += 1;
    socket.destroy();
  });
  await new Promise<void>((resolve, reject) => {
    speculativeProbe.once('error', reject);
    speculativeProbe.listen(0, '127.0.0.1', () => {
      speculativeProbe.off('error', reject);
      resolve();
    });
  });
  speculativeProbe.unref();
  const speculativeProbeAddress = speculativeProbe.address();

  if (speculativeProbeAddress === null || typeof speculativeProbeAddress === 'string') {
    throw new Error('Expected an IPv4 TCP probe address.');
  }

  const speculativeProbeUrl = `http://127.0.0.1:${speculativeProbeAddress.port}`;

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
  await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'domcontentloaded' });

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
    <meta http-equiv="&#114efresh" content="0; url=${baseUrl}/saved-preview-navigation-check?via=meta">
    <link rel="&#112reconnect" href="${speculativeProbeUrl}/static-numeric-preconnect">
    <link rel="&#112refetch" href="${speculativeProbeUrl}/static-numeric-prefetch">
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

      const dynamicHint = document.createElement('link');
      dynamicHint.rel = 'preconnect';
      dynamicHint.href = 'https://dynamic-hint.invalid';
      document.head.append(dynamicHint);
      const dynamicRefresh = document.createElement('meta');
      dynamicRefresh.httpEquiv = 'refresh';
      dynamicRefresh.content = '3600;url=https://dynamic-refresh.invalid';
      document.head.append(dynamicRefresh);
      const dangerousSubtree = document.createElement('div');
      dangerousSubtree.innerHTML =
        '<link rel="dns-prefetch" href="//subtree-hint.invalid">' +
        '<meta http-equiv="refresh" content="3600;url=https://subtree-refresh.invalid">';
      document.body.append(dangerousSubtree);
      const postInsertionHint = document.createElement('link');
      document.head.append(postInsertionHint);
      const postInsertionHintWasConnected = postInsertionHint.isConnected;
      postInsertionHint.rel = 'preconnect';
      postInsertionHint.href = '${speculativeProbeUrl}/post-insertion-hint';
      const postInsertionAttributeHint = document.createElement('link');
      postInsertionAttributeHint.href = '${speculativeProbeUrl}/post-insertion-attribute-hint';
      document.head.append(postInsertionAttributeHint);
      postInsertionAttributeHint.setAttribute('rel', 'dns-prefetch');
      const postInsertionTokenHint = document.createElement('link');
      postInsertionTokenHint.href = '${speculativeProbeUrl}/post-insertion-token-hint';
      document.head.append(postInsertionTokenHint);
      postInsertionTokenHint.relList.add('preconnect');
      const postInsertionToggleHint = document.createElement('link');
      postInsertionToggleHint.href = '${speculativeProbeUrl}/post-insertion-toggle-hint';
      document.head.append(postInsertionToggleHint);
      postInsertionToggleHint.relList.toggle('prefetch', true);
      const safeToggleRemovalLink = document.createElement('link');
      safeToggleRemovalLink.rel = 'stylesheet';
      document.head.append(safeToggleRemovalLink);
      const safeToggleRemovalResult = safeToggleRemovalLink.relList.toggle('prefetch', false);
      const safeToggleRemovalState =
        safeToggleRemovalLink.isConnected &&
        safeToggleRemovalLink.rel === 'stylesheet' &&
        safeToggleRemovalResult === false
          ? 'rel-toggle-removal-preserved'
          : 'rel-toggle-removal-destroyed';
      const postInsertionReplaceHint = document.createElement('link');
      postInsertionReplaceHint.rel = 'alternate';
      postInsertionReplaceHint.href = '${speculativeProbeUrl}/post-insertion-replace-hint';
      document.head.append(postInsertionReplaceHint);
      postInsertionReplaceHint.relList.replace('alternate', 'prerender');
      const postInsertionNamespaceHint = document.createElement('link');
      postInsertionNamespaceHint.href = '${speculativeProbeUrl}/post-insertion-namespace-hint';
      document.head.append(postInsertionNamespaceHint);
      postInsertionNamespaceHint.setAttributeNS(null, 'rel', 'prefetch');
      const postInsertionRefresh = document.createElement('meta');
      postInsertionRefresh.content = '3600;url=https://post-insertion-refresh.invalid';
      document.head.append(postInsertionRefresh);
      postInsertionRefresh.httpEquiv = 'refresh';
      const dynamicNavigationState =
        !dynamicHint.isConnected &&
        !dynamicRefresh.isConnected &&
        dangerousSubtree.querySelector('link[rel="dns-prefetch"], meta[http-equiv="refresh"]') ===
          null &&
        postInsertionHintWasConnected &&
        !postInsertionHint.isConnected &&
        !postInsertionAttributeHint.isConnected &&
        !postInsertionTokenHint.isConnected &&
        !postInsertionToggleHint.isConnected &&
        !postInsertionReplaceHint.isConnected &&
        !postInsertionNamespaceHint.isConnected &&
        !postInsertionRefresh.isConnected
          ? 'dynamic-navigation-markup-blocked'
          : 'dynamic-navigation-markup-delayed';

      const statefulString = (first, second) => {
        let coercions = 0;

        return {
          toString() {
            coercions += 1;

            return coercions === 1 ? first : second;
          },
        };
      };
      const statefulRelLink = document.createElement('link');
      statefulRelLink.href = '${speculativeProbeUrl}/stateful-property-rel';
      document.head.append(statefulRelLink);
      statefulRelLink.rel = statefulString('stylesheet', 'preconnect');
      const statefulRelFrozen =
        statefulRelLink.isConnected && statefulRelLink.rel === 'stylesheet';

      const statefulAttributeNameLink = document.createElement('link');
      document.head.append(statefulAttributeNameLink);
      statefulAttributeNameLink.rel = statefulString('stylesheet', 'preconnect');
      statefulAttributeNameLink.setAttribute(
        statefulString('class', 'href'),
        '${speculativeProbeUrl}/stateful-attribute-name',
      );
      const statefulAttributeNameFrozen =
        statefulAttributeNameLink.isConnected &&
        statefulAttributeNameLink.rel === 'stylesheet' &&
        statefulAttributeNameLink.getAttribute('href') === null &&
        statefulAttributeNameLink.className ===
          '${speculativeProbeUrl}/stateful-attribute-name';

      const statefulAttributeValueLink = document.createElement('link');
      statefulAttributeValueLink.href = '${speculativeProbeUrl}/stateful-attribute-value';
      document.head.append(statefulAttributeValueLink);
      statefulAttributeValueLink.setAttribute(
        'rel',
        statefulString('stylesheet', 'preconnect'),
      );
      const statefulAttributeValueFrozen =
        statefulAttributeValueLink.isConnected &&
        statefulAttributeValueLink.rel === 'stylesheet';

      const statefulNamespaceLink = document.createElement('link');
      statefulNamespaceLink.href = '${speculativeProbeUrl}/stateful-namespace-value';
      document.head.append(statefulNamespaceLink);
      statefulNamespaceLink.setAttributeNS(
        null,
        'rel',
        statefulString('stylesheet', 'preconnect'),
      );
      const statefulNamespaceFrozen =
        statefulNamespaceLink.isConnected && statefulNamespaceLink.rel === 'stylesheet';

      const statefulAttributeNodeLink = document.createElement('link');
      statefulAttributeNodeLink.href = '${speculativeProbeUrl}/stateful-attribute-node';
      document.head.append(statefulAttributeNodeLink);
      const statefulRelAttribute = document.createAttribute('rel');
      statefulRelAttribute.value = 'alternate';
      statefulAttributeNodeLink.setAttributeNode(statefulRelAttribute);
      statefulRelAttribute.value = statefulString('stylesheet', 'preconnect');
      const statefulAttributeNodeFrozen =
        statefulAttributeNodeLink.isConnected &&
        statefulAttributeNodeLink.rel === 'stylesheet';

      const statefulRelListAddLink = document.createElement('link');
      statefulRelListAddLink.href = '${speculativeProbeUrl}/stateful-rel-list-add';
      document.head.append(statefulRelListAddLink);
      statefulRelListAddLink.relList.add(statefulString('stylesheet', 'preconnect'));
      const statefulRelListAddFrozen =
        statefulRelListAddLink.isConnected &&
        statefulRelListAddLink.rel === 'stylesheet';

      const statefulRelListToggleLink = document.createElement('link');
      statefulRelListToggleLink.href = '${speculativeProbeUrl}/stateful-rel-list-toggle';
      document.head.append(statefulRelListToggleLink);
      statefulRelListToggleLink.relList.toggle(
        statefulString('stylesheet', 'preconnect'),
        true,
      );
      const statefulRelListToggleFrozen =
        statefulRelListToggleLink.isConnected &&
        statefulRelListToggleLink.rel === 'stylesheet';

      const statefulRelListReplaceLink = document.createElement('link');
      statefulRelListReplaceLink.rel = 'alternate';
      statefulRelListReplaceLink.href =
        '${speculativeProbeUrl}/stateful-rel-list-replace';
      document.head.append(statefulRelListReplaceLink);
      statefulRelListReplaceLink.relList.replace(
        'alternate',
        statefulString('stylesheet', 'preconnect'),
      );
      const statefulRelListReplaceFrozen =
        statefulRelListReplaceLink.isConnected &&
        statefulRelListReplaceLink.rel === 'stylesheet';

      const statefulRelListValueLink = document.createElement('link');
      statefulRelListValueLink.href = '${speculativeProbeUrl}/stateful-rel-list-value';
      document.head.append(statefulRelListValueLink);
      statefulRelListValueLink.relList.value = statefulString('stylesheet', 'preconnect');
      const statefulRelListValueFrozen =
        statefulRelListValueLink.isConnected &&
        statefulRelListValueLink.rel === 'stylesheet';

      const statefulExecCommandHost = document.createElement('div');
      statefulExecCommandHost.contentEditable = 'true';
      statefulExecCommandHost.textContent = 'selection';
      document.body.append(statefulExecCommandHost);
      const statefulExecCommandSelection = getSelection();
      const statefulExecCommandRange = document.createRange();
      statefulExecCommandRange.selectNodeContents(statefulExecCommandHost);
      statefulExecCommandSelection.removeAllRanges();
      statefulExecCommandSelection.addRange(statefulExecCommandRange);
      document.execCommand(
        statefulString('copy', 'insertHTML'),
        false,
        '<iframe data-stateful-exec-command></iframe>',
      );
      const statefulExecCommandFrozen =
        statefulExecCommandHost.querySelector('[data-stateful-exec-command]') === null;
      const statefulCoercionState =
        statefulRelFrozen &&
        statefulAttributeNameFrozen &&
        statefulAttributeValueFrozen &&
        statefulNamespaceFrozen &&
        statefulAttributeNodeFrozen &&
        statefulRelListAddFrozen &&
        statefulRelListToggleFrozen &&
        statefulRelListReplaceFrozen &&
        statefulRelListValueFrozen &&
        statefulExecCommandFrozen
          ? 'stateful-coercion-frozen'
          : 'stateful-coercion-bypassed';

      fetch('${baseUrl}/saved-preview-network-check')
        .then(() => {
          document.getElementById('result').textContent = [
            parentState,
            'network-accessible',
            cookieState,
            storageState,
            unsafeParserState,
            unsafeSetterState,
            dynamicNavigationState,
            statefulCoercionState,
            safeToggleRemovalState,
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
            dynamicNavigationState,
            statefulCoercionState,
            safeToggleRemovalState,
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

  const previewResponsePromise = page.waitForResponse(
    (response) =>
      response.request().resourceType() === 'document' &&
      new URL(response.url()).pathname.startsWith('/artifact-previews/'),
  );

  await page.getByRole('button', { name: 'Save page' }).click();

  // The save posts via fetch and rebuilds the document; the preview then loads
  // a signed URL cross-origin and runs the artifact. Both steps are far slower
  // on CI than the 5s default expect timeout, so wait for them explicitly.
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u, { timeout: 20_000 });
  await expect(page.getByRole('heading', { name: title })).toBeVisible({ timeout: 20_000 });

  const previewResponse = await previewResponsePromise;
  const hardenedPreviewBody = await previewResponse.text();
  expect(await previewResponse.headerValue('x-dns-prefetch-control')).toBe('off');
  expect(hardenedPreviewBody).not.toContain('static-numeric-preconnect');
  expect(hardenedPreviewBody).not.toContain('static-numeric-prefetch');
  expect(hardenedPreviewBody).not.toContain('saved-preview-navigation-check?via=meta');

  const frame = page.locator('iframe[title="Artifact preview"]');
  await expect(frame).toHaveAttribute('sandbox', 'allow-scripts');
  await expect(frame).toHaveAttribute('allow', '');
  await expect(frame).not.toHaveAttribute('sandbox', /allow-same-origin/u);

  const previewResult = page.frameLocator('iframe[title="Artifact preview"]').locator('#result');
  await expect(previewResult).toBeAttached({ timeout: 20_000 });
  await expect(previewResult).toHaveText(
    'parent-blocked network-blocked cookies-blocked storage-blocked unsafe-parser-blocked unsafe-setters-blocked dynamic-navigation-markup-blocked stateful-coercion-frozen rel-toggle-removal-preserved',
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

  // A signed preview opened as a real top-level document receives only the
  // fixed server-rendered 403 notice. Its bare CSP sandbox must remain present,
  // while a deliberate click can still navigate that top-level tab back to the
  // trusted app origin without granting artifact documents a top-navigation
  // sandbox token.
  const noticePage = await page.context().newPage();
  const noticeResponse = await noticePage.goto(initialPreviewUrl ?? '', {
    waitUntil: 'domcontentloaded',
  });
  expect(noticeResponse?.status()).toBe(403);
  const noticeCsp = await noticeResponse?.headerValue('content-security-policy');
  expect(noticeCsp).toMatch(/(?:^|;)\s*sandbox\s*(?:;|$)/u);
  expect(noticeCsp).not.toContain('allow-top-navigation');
  await noticePage.getByRole('link', { name: 'Open it inside ArtifactFlow' }).click();
  await expect(noticePage).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u);
  await noticePage.close();

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
  const editButton = page.getByRole('button', { name: 'Edit HTML source' });
  await expect(editButton).toHaveAttribute('data-editor-dialog-trigger-ready', '');
  await editButton.click();
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
  expect(speculativeConnectionCount).toBe(0);
  expect(page.url()).toBe(parentPageUrl);
  await new Promise<void>((resolve, reject) => {
    speculativeProbe.close((error) => {
      if (error) {
        reject(error);
        return;
      }

      resolve();
    });
  });
});

test('historical HTML versions stay inside the artifact-origin sandbox @artifact-security', async ({
  page,
}) => {
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
  await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'domcontentloaded' });

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
  const editButton = page.getByRole('button', { name: 'Edit HTML source' });
  await expect(editButton).toHaveAttribute('data-editor-dialog-trigger-ready', '');
  await editButton.click();
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
  await expect(
    page.frameLocator('iframe[title="Artifact preview"]').getByRole('heading', {
      name: 'Current artifact',
    }),
  ).toBeVisible({ timeout: 20_000 });
  const versionsButton = page.getByRole('button', { name: 'Versions' });
  await expect(versionsButton).toHaveAttribute('data-editor-dialog-trigger-ready', '');
  await versionsButton.click();
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

test('preview URL renewal is capped so a self-navigating artifact cannot drain the viewer rate limit @artifact-security', async ({
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
  await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'domcontentloaded' });

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
