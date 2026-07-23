import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { fileURLToPath } from 'node:url';

// CAGE invariant: no app cookies on the artifact host. Cookies are scoped by
// host and ignore the port (RFC 6265), and ports do not make requests
// cross-site for SameSite processing, so this only holds when the app and
// artifact origins use different hosts (localhost vs 127.0.0.1 in local/e2e).
// This spec drives the two real preview flows through an authenticated session
// and asserts, at the network layer, that no artifact request carries a Cookie
// header while the app requests demonstrably do.
const baseUrl = (process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:18180').replace(/\/$/u, '');
const artifactBaseUrl = (process.env.E2E_ARTIFACT_URL ?? 'http://127.0.0.1:18181').replace(
  /\/$/u,
  '',
);
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

async function login(page: Page, email: string, password: string): Promise<void> {
  await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/dashboard$/u);
}

test('artifact preview requests carry no application cookies @artifact-security', async ({
  page,
}) => {
  // Two full preview flows (saved signed-URL iframe plus draft form POST) with
  // a fetch-based save in between comfortably exceed the default timeout on CI.
  test.setTimeout(120_000);

  // The isolation below is only meaningful when the two origins use different
  // hosts; fail fast and explicitly when the environment re-merges them.
  expect(
    new URL(artifactBaseUrl).hostname,
    'App and artifact origins must use different hosts; on a shared host the browser attaches the app session cookie to artifact requests (cookies ignore the port).',
  ).not.toBe(new URL(baseUrl).hostname);

  const runSuffix = randomUUID().replaceAll('-', '').slice(0, 12);
  const email = `artifact-cookie-e2e-${runSuffix}@example.test`;
  const password = `af${randomUUID().replaceAll('-', '')}`;
  const title = `Cookie isolation ${runSuffix}`;

  runAppCommand(
    `php artisan artifactflow:create-user --name=CookieIsolationE2E --email=${email} --password=${password}`,
    'Failed to prepare the artifact cookie isolation e2e account.',
  );

  // Record the final headers (including browser-managed Cookie headers, which
  // request.headers() omits) of every request that targets the artifact origin.
  const artifactRequests: Array<{ url: string; cookie: string; headersResolved: boolean }> = [];
  const headerCaptures: Array<Promise<void>> = [];

  page.on('request', (request) => {
    if (!request.url().startsWith(artifactBaseUrl)) {
      return;
    }

    headerCaptures.push(
      request
        .allHeaders()
        .then((headers) => {
          artifactRequests.push({
            url: request.url(),
            cookie: headers['cookie'] ?? '',
            headersResolved: true,
          });
        })
        .catch(() => {
          artifactRequests.push({ url: request.url(), cookie: '', headersResolved: false });
        }),
    );
  });

  await login(page, email, password);

  // Control probe: the same header capture on an app-origin request must see
  // the session cookie, otherwise the "no cookie on artifact requests"
  // assertions below could pass vacuously because headers were not observable.
  const [createRequest] = await Promise.all([
    page.waitForRequest(
      (request) => request.url() === `${baseUrl}/pages/create` && request.isNavigationRequest(),
    ),
    page.goto(`${baseUrl}/pages/create`, { waitUntil: 'domcontentloaded' }),
  ]);
  const appRequestHeaders = await createRequest.allHeaders();
  expect(appRequestHeaders['cookie'] ?? '').toContain('artifactflow_session');
  expect(
    (await page.context().cookies(baseUrl)).some(
      (cookie) => cookie.name === 'artifactflow_session',
    ),
  ).toBe(true);

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
  <body>
    <p id="result">pending</p>
    <script>document.getElementById('result').textContent = 'artifact-executed';</script>
  </body>
</html>`);
  await expect(page.locator('[data-editor-textarea]')).toHaveValue(/artifact-executed/u);

  // Flow 1: the authenticated app first issues a content-bound short-lived
  // capability, then form-POSTs the exact HTML plus that capability into the
  // sandbox iframe on the artifact origin.
  const capabilityRequestPromise = page.waitForRequest(
    (request) =>
      request.url() === `${baseUrl}/pages/draft-preview-capabilities` &&
      request.method() === 'POST',
    { timeout: 20_000 },
  );
  const draftRequestPromise = page.waitForRequest(
    (request) =>
      request.url() === `${artifactBaseUrl}/artifact-previews/draft` && request.method() === 'POST',
    { timeout: 20_000 },
  );
  await page.getByRole('button', { name: 'Preview HTML before saving' }).click();
  const capabilityRequest = await capabilityRequestPromise;
  const draftRequest = await draftRequestPromise;
  await expect(page.frameLocator('[data-html-draft-preview-frame]').locator('#result')).toHaveText(
    'artifact-executed',
    { timeout: 20_000 },
  );

  // Flow 2: saved preview. Saving redirects to the page view, whose iframe
  // loads the signed preview URL from the artifact origin.
  await page.getByRole('button', { name: 'Save page' }).click();
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u, { timeout: 20_000 });
  await expect(page.getByRole('heading', { name: title })).toBeVisible({ timeout: 20_000 });
  const savedFrame = page.locator('iframe[title="Artifact preview"]');
  await expect(page.frameLocator('iframe[title="Artifact preview"]').locator('#result')).toHaveText(
    'artifact-executed',
    { timeout: 20_000 },
  );

  // The parser-initiated load of a cross-origin iframe can race the network
  // instrumentation of its out-of-process frame, so its request event is not
  // reliably observable. Re-issue the same signed navigation from the parent
  // document (cookie attachment is identical either way) to capture the saved
  // preview request headers deterministically.
  const savedSrc = await savedFrame.getAttribute('src');
  expect(savedSrc).not.toBeNull();
  expect(
    (savedSrc ?? '').startsWith(`${artifactBaseUrl}/artifact-previews/`),
    'The saved preview iframe must point at the artifact origin.',
  ).toBe(true);
  const savedRequestPromise = page.waitForRequest(
    (request) =>
      request.url().startsWith(`${artifactBaseUrl}/artifact-previews/`) &&
      request.url().includes('cookieprobe=1') &&
      request.method() === 'GET',
    { timeout: 20_000 },
  );
  await savedFrame.evaluate((element, url) => {
    (element as HTMLIFrameElement).src = `${url}&cookieprobe=1`;
  }, savedSrc);
  const savedRequest = await savedRequestPromise;
  await expect(page.frameLocator('iframe[title="Artifact preview"]').locator('#result')).toHaveText(
    'artifact-executed',
    { timeout: 20_000 },
  );

  // Sensitivity guard: sec-fetch-* headers exist only in the final
  // network-layer header set (never in provisional headers), so seeing them
  // proves these captures would also surface a Cookie header if one were sent.
  const draftHeaders = await draftRequest.allHeaders();
  expect(draftHeaders['sec-fetch-dest']).toBe('iframe');
  expect(
    draftHeaders['cookie'] ?? '',
    'The draft preview POST must not carry any cookies; the app session cookie must never reach the artifact host.',
  ).toBe('');
  expect(draftHeaders['content-type'] ?? '').toContain('multipart/form-data; boundary=');

  const capabilityHeaders = await capabilityRequest.allHeaders();
  expect(capabilityHeaders['cookie'] ?? '').toContain('artifactflow_session');
  expect(capabilityHeaders['x-csrf-token'] ?? '').not.toBe('');
  const capabilityClaims = capabilityRequest.postDataJSON() as Record<string, unknown>;
  expect(capabilityClaims.content_bytes).toBeGreaterThan(0);
  expect(capabilityClaims.content_sha256).toMatch(/^[a-f0-9]{64}$/u);
  expect(capabilityClaims.workspace_uid).toMatch(/^[0-9a-hjkmnp-tv-z]{26}$/u);
  expect(capabilityClaims).not.toHaveProperty('content');

  const savedHeaders = await savedRequest.allHeaders();
  expect(savedHeaders['sec-fetch-dest']).toBe('iframe');
  expect(
    savedHeaders['cookie'] ?? '',
    'The saved preview signed-URL load must not carry any cookies; the app session cookie must never reach the artifact host.',
  ).toBe('');

  // Broad sweep: whatever artifact-origin requests the instrumentation did
  // observe (draft POST, preview loads, any ancillary requests) must all be
  // cookie-free and fully observable.
  await Promise.all(headerCaptures);
  expect(artifactRequests.length).toBeGreaterThan(0);

  for (const artifactRequest of artifactRequests) {
    expect(
      artifactRequest.headersResolved,
      `Headers for ${artifactRequest.url} must be observable; an unresolved capture would hide a leaked cookie.`,
    ).toBe(true);
    expect(
      artifactRequest.cookie,
      `Artifact request ${artifactRequest.url} must not carry any cookies; the app session cookie must never reach the artifact host.`,
    ).toBe('');
  }
});
