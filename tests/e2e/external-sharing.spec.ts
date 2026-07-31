import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
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

function enableExternalSharing(): void {
  runAppCommand(
    'php artisan tinker --execute="Illuminate\\\\Support\\\\Facades\\\\DB::table(\\"installation_settings\\")->upsert([array_merge(app(App\\\\Application\\\\Administration\\\\InstallationLimitSettings::class)->current()->toPersistenceArray(), [\\"uid\\" => (string) Illuminate\\\\Support\\\\Str::ulid(), \\"scope\\" => App\\\\Models\\\\InstallationSettings::SCOPE_INSTALLATION, \\"external_sharing_enabled\\" => true, \\"external_share_acknowledgement_required\\" => true, \\"external_share_max_expiry_hours\\" => 168, \\"created_at\\" => now(), \\"updated_at\\" => now()])], [\\"scope\\"], [\\"external_sharing_enabled\\", \\"external_share_acknowledgement_required\\", \\"external_share_max_expiry_hours\\", \\"updated_at\\"]);"',
    'Failed to enable external sharing in the isolated e2e database.',
  );
}

async function createUserAndLogin(page: Page, prefix: string): Promise<void> {
  const suffix = randomUUID().replaceAll('-', '').slice(0, 12);
  const email = `${prefix}-${suffix}@example.test`;
  const password = `af${randomUUID().replaceAll('-', '')}`;

  runAppCommand(
    `php artisan artifactflow:create-user --name=ExternalShareE2E --email=${email} --password=${password}`,
    'Failed to prepare the external-share e2e account.',
  );
  enableExternalSharing();

  await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/dashboard$/u);
}

async function createMarkdownPage(page: Page, title: string): Promise<void> {
  await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'domcontentloaded' });
  const editorForm = page.locator('[data-content-editor]');
  await expect(editorForm).toHaveAttribute('data-editor-ready', 'true');
  await page.locator('input[name="title"]').fill(title);
  await page
    .getByRole('textbox', { name: 'Page content' })
    .fill(`# ${title}\n\nRecipient content.`);
  await page.getByRole('button', { name: 'Save page' }).click();
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u);
}

async function createHtmlPage(page: Page, title: string): Promise<void> {
  await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'domcontentloaded' });
  const editorForm = page.locator('[data-content-editor]');
  await expect(editorForm).toHaveAttribute('data-editor-ready', 'true');
  await page.locator('select[name="type"]').selectOption('html_artifact');
  await page.locator('select[name="mode"]').selectOption('html_paste');
  await page.locator('input[name="title"]').fill(title);
  await expect(editorForm).toHaveAttribute('data-editor-language', 'html');
  const source = page.locator('[data-source-editor-mount] .cm-content');
  await source.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.insertText(
    '<!doctype html><html><body><p id="external-html-ready">External HTML ready</p><script>document.body.dataset.executed="yes";</script></body></html>',
  );
  await page.getByRole('button', { name: 'Save page' }).click();
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u, { timeout: 20_000 });
}

async function createImagePage(page: Page, title: string): Promise<void> {
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAD0lEQVQImWNUSHhwQcIBAAkOAopyJglZAAAAAElFTkSuQmCC',
    'base64',
  );

  await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-create-page-form]')).toHaveAttribute(
    'data-create-page-mode-ready',
    'true',
  );
  await page.locator('select[name="mode"]').selectOption('image_upload');
  await page.locator('input[name="title"]').fill(title);
  await page.locator('input[name="image_file"]').setInputFiles({
    name: 'external-share.png',
    mimeType: 'image/png',
    buffer: png,
  });
  await page.getByRole('button', { name: 'Save page' }).click();
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u, { timeout: 20_000 });
}

async function createOneTimeLink(page: Page): Promise<string> {
  const shareButton = page.getByRole('button', { name: /Share externally/u });
  await expect(shareButton).toHaveAttribute('data-editor-dialog-trigger-ready', '');
  await shareButton.click();
  const dialog = page.locator('#page-external-share-dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog).toHaveAttribute('data-external-share-management-ready', '');
  await dialog.getByLabel(/One time/u).check();
  await dialog.getByRole('button', { name: 'Create external link' }).click();
  const secretInput = dialog.locator('[data-external-share-secret-url]');
  await expect(secretInput).toBeVisible();
  await expect(secretInput).not.toHaveValue('');
  const rawUrl = await secretInput.inputValue();
  await dialog.getByRole('button', { name: 'Close external sharing' }).click();
  await expect(dialog).toBeHidden();
  await expect(secretInput).toHaveValue('');

  return rawUrl;
}

test('expiring link picker enforces the installation maximum and renders local viewer time', async ({
  browser,
  page,
}) => {
  const suffix = randomUUID().replaceAll('-', '').slice(0, 10);
  await createUserAndLogin(page, 'external-expiry-picker');
  await createMarkdownPage(page, `External expiry ${suffix}`);

  const shareButton = page.getByRole('button', { name: /Share externally/u });
  await expect(shareButton).toHaveAttribute('data-editor-dialog-trigger-ready', '');
  await shareButton.click();
  const dialog = page.locator('#page-external-share-dialog');
  await expect(dialog).toHaveAttribute('data-external-share-management-ready', '');
  await dialog.getByLabel(/Expires at/u).check();
  const expiry = dialog.locator('[data-external-share-expiry]');
  await expect(expiry).toHaveAttribute('max', /.+/u);
  const maximum = await expiry.getAttribute('max');
  expect(maximum).not.toBeNull();

  const maximumDate = new Date(maximum ?? '');
  expect(Number.isNaN(maximumDate.getTime())).toBe(false);
  expect(maximumDate.getTime()).toBeGreaterThan(Date.now() + 167 * 60 * 60 * 1000);
  expect(maximumDate.getTime()).toBeLessThanOrEqual(Date.now() + 168 * 60 * 60 * 1000);

  const beyondMaximum = await expiry.evaluate((input: HTMLInputElement) => {
    const date = new Date(input.max);
    date.setMinutes(date.getMinutes() + 1);
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);

    return local.toISOString().slice(0, 16);
  });
  await expiry.fill(beyondMaximum);
  expect(await expiry.evaluate((input: HTMLInputElement) => input.validity.rangeOverflow)).toBe(
    true,
  );

  const validExpiry = await expiry.evaluate((input: HTMLInputElement) => {
    const date = new Date(input.max);
    date.setHours(date.getHours() - 1);
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);

    return local.toISOString().slice(0, 16);
  });
  await expiry.fill(validExpiry);
  await dialog.getByRole('button', { name: 'Create external link' }).click();
  const secretInput = dialog.locator('[data-external-share-secret-url]');
  await expect(secretInput).toBeVisible();
  const rawUrl = await secretInput.inputValue();
  const recipientContext = await browser.newContext();

  try {
    const opened = await openConfirmation(recipientContext, rawUrl, `External expiry ${suffix}`);
    await opened.page.getByRole('button', { name: /open artifact/iu }).click();
    const localTime = opened.page.locator('[data-external-share-local-time="date-time"]');
    await expect(localTime).toBeVisible();
    const expectedLocalTime = await localTime.evaluate((time: HTMLTimeElement) =>
      new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
      }).format(new Date(time.dateTime)),
    );
    await expect(localTime).toHaveText(expectedLocalTime);
  } finally {
    await recipientContext.close();
  }
});

async function openConfirmation(
  context: BrowserContext,
  rawUrl: string,
  expectedTitle: string,
): Promise<{ page: Page; secret: string }> {
  const url = new URL(rawUrl);
  const secret = url.hash.replace(/^#secret=/u, '');
  const recipient = await context.newPage();
  const requestedUrls: string[] = [];
  recipient.on('request', (request) => requestedUrls.push(request.url()));

  await recipient.goto(rawUrl, { waitUntil: 'domcontentloaded' });
  await expect.poll(() => new URL(recipient.url()).hash).toBe('');
  await expect(recipient.getByRole('heading', { name: expectedTitle })).toBeVisible();
  await expect(recipient.getByRole('button', { name: /open artifact/iu })).toBeVisible();
  expect(await recipient.content()).not.toContain(secret);
  expect(requestedUrls.every((requestedUrl) => !requestedUrl.includes(secret))).toBe(true);

  return { page: recipient, secret };
}

async function unavailableOnSecondUse(browser: Browser, rawUrl: string): Promise<void> {
  const secondContext = await browser.newContext();

  try {
    const second = await secondContext.newPage();
    await second.goto(rawUrl, { waitUntil: 'domcontentloaded' });
    await expect(second.getByText('This external artifact is unavailable.')).toBeVisible();
  } finally {
    await secondContext.close();
  }
}

test('one-time fragment sharing is explicit, single-use, and independent of login state @artifact-security', async ({
  browser,
  page,
}) => {
  test.setTimeout(90_000);
  const suffix = randomUUID().replaceAll('-', '').slice(0, 10);
  const title = `External Markdown ${suffix}`;
  await createUserAndLogin(page, 'external-markdown');
  await createMarkdownPage(page, title);

  const rawUrl = await createOneTimeLink(page);
  const parsedRawUrl = new URL(rawUrl);
  expect(parsedRawUrl.origin).toBe(new URL(baseUrl).origin);
  expect(parsedRawUrl.pathname).toMatch(/^\/external-shares\/[0-9A-HJKMNP-TV-Z]{26}$/iu);
  expect(parsedRawUrl.hash).toMatch(/^#secret=[A-Za-z0-9_-]{43}$/u);
  const anonymousContext = await browser.newContext();

  try {
    const opened = await openConfirmation(anonymousContext, rawUrl, title);
    await opened.page.getByRole('button', { name: /open artifact/iu }).click();
    await expect(opened.page).toHaveURL(/\/external-shares\/[0-9a-hjkmnp-tv-z]{26}\/viewer$/u);
    await expect(opened.page.getByText('Recipient content.')).toBeVisible();
    const viewerContent = opened.page.locator('.af-external-viewer-content');
    await expect(viewerContent).toBeVisible();
    await expect
      .poll(() =>
        viewerContent.evaluate(
          (element) =>
            element.getBoundingClientRect().width / document.documentElement.clientWidth,
        ),
      )
      .toBeGreaterThan(0.9);
    await expect(opened.page.getByText('Live · latest version')).toBeVisible();
    await expect(opened.page.getByText('Version history')).toHaveCount(0);
    await expect(opened.page.getByLabel('ArtifactFlow')).toBeVisible();
    const externalBrandName = opened.page.locator('.af-external-brand .af-brand-name');
    await expect(externalBrandName).toHaveText('artifactflow');
    expect(
      await externalBrandName.evaluate((element) => {
        const lineHeight = Number.parseFloat(getComputedStyle(element).lineHeight);

        return element.getBoundingClientRect().height / lineHeight;
      }),
    ).toBeLessThan(1.25);
    await expect(opened.page.getByText('Viewing session ends')).toHaveCount(0);

    await opened.page.getByRole('button', { name: 'Dark theme' }).click();
    await expect(opened.page.locator('html')).toHaveClass(/dark/u);
    await expect(opened.page.getByRole('button', { name: 'Dark theme' })).toHaveAttribute(
      'aria-pressed',
      'true',
    );
    await opened.page.reload({ waitUntil: 'domcontentloaded' });
    await expect(opened.page.getByText('Recipient content.')).toBeVisible();
    await expect(opened.page.locator('html')).toHaveClass(/dark/u);
    expect(await opened.page.content()).not.toContain(opened.secret);

    let transientContentFailure = true;
    await opened.page.route('**/viewer/content', async (route) => {
      if (!transientContentFailure) {
        await route.fallback();

        return;
      }

      transientContentFailure = false;
      await route.fulfill({
        status: 503,
        contentType: 'text/html',
        body: '<!doctype html><title>Transient failure</title>',
      });
    });
    await opened.page.reload({ waitUntil: 'domcontentloaded' });
    await expect(
      opened.page.getByText('This external artifact is unavailable.'),
    ).toBeVisible();
    await opened.page.unroute('**/viewer/content');
    await opened.page.reload({ waitUntil: 'domcontentloaded' });
    await expect(opened.page.getByText('Recipient content.')).toBeVisible();

    const viewerUrl = opened.page.url();
    const secondWindow = await anonymousContext.newPage();
    await secondWindow.goto(viewerUrl, { waitUntil: 'domcontentloaded' });
    await expect(secondWindow.getByText('This external artifact is unavailable.')).toBeVisible();
    await expect(secondWindow.getByText('Recipient content.')).toHaveCount(0);
    await secondWindow.close();

    const replay = await anonymousContext.newPage();
    await replay.goto(rawUrl, { waitUntil: 'domcontentloaded' });
    await expect(replay.getByText('This external artifact is unavailable.')).toBeVisible();
    await replay.close();
  } finally {
    await anonymousContext.close();
  }

  await unavailableOnSecondUse(browser, rawUrl);

  const authenticatedRawUrl = await createOneTimeLink(page);
  const authenticatedSecret = new URL(authenticatedRawUrl).hash.replace(/^#secret=/u, '');
  const requestedUrls: string[] = [];
  page.on('request', (request) => requestedUrls.push(request.url()));
  await page.goto(authenticatedRawUrl, { waitUntil: 'domcontentloaded' });
  await expect.poll(() => new URL(page.url()).hash).toBe('');
  await expect(page.getByRole('heading', { name: title })).toBeVisible();
  expect(await page.content()).not.toContain(authenticatedSecret);
  expect(requestedUrls.every((requestedUrl) => !requestedUrl.includes(authenticatedSecret))).toBe(
    true,
  );
  await page.getByRole('button', { name: /open artifact/iu }).click();
  await expect(page.getByText('Recipient content.')).toBeVisible();
});

test('external HTML and image viewers preserve their isolated sandbox policies @artifact-security', async ({
  browser,
  page,
}) => {
  test.setTimeout(120_000);
  const suffix = randomUUID().replaceAll('-', '').slice(0, 10);
  await createUserAndLogin(page, 'external-presenters');

  const htmlTitle = `External HTML ${suffix}`;
  await createHtmlPage(page, htmlTitle);
  const htmlUrl = await createOneTimeLink(page);
  const htmlContext = await browser.newContext();

  try {
    const opened = await openConfirmation(htmlContext, htmlUrl, htmlTitle);
    const responsePromise = opened.page.waitForResponse((response) =>
      new URL(response.url()).pathname.startsWith('/external-artifact-previews/'),
    );
    await opened.page.getByRole('button', { name: /open artifact/iu }).click();
    const frame = opened.page.locator('iframe[title="Artifact preview"]');
    await expect(frame).toHaveAttribute('sandbox', 'allow-scripts');
    await expect(frame).not.toHaveAttribute('sandbox', /allow-same-origin/u);
    await expect(
      opened.page.frameLocator('iframe[title="Artifact preview"]').locator('#external-html-ready'),
    ).toHaveText('External HTML ready');
    await expect(
      opened.page.frameLocator('iframe[title="Artifact preview"]').locator('body'),
    ).toHaveAttribute('data-executed', 'yes');
    const response = await responsePromise;
    const previewUrl = response.url();
    const csp = await response.headerValue('content-security-policy');
    expect(previewUrl).not.toContain(opened.secret);
    expect(new URL(previewUrl).origin).not.toBe(new URL(baseUrl).origin);
    expect(csp).toContain('sandbox allow-scripts');
    expect(csp).not.toContain('allow-same-origin');
    expect(csp).toContain("connect-src 'none'");
  } finally {
    await htmlContext.close();
  }

  const imageTitle = `External image ${suffix}`;
  await createImagePage(page, imageTitle);
  const imageUrl = await createOneTimeLink(page);
  const imageContext = await browser.newContext();

  try {
    const opened = await openConfirmation(imageContext, imageUrl, imageTitle);
    let renewalRequests = 0;
    opened.page.on('request', (request) => {
      if (new URL(request.url()).pathname.endsWith('/artifact-preview-url')) {
        renewalRequests += 1;
      }
    });
    await opened.page.route('**/external-artifact-previews/**', async (route) => {
      await new Promise((resolve) => setTimeout(resolve, 500));
      await route.continue();
    });
    const responsePromise = opened.page.waitForResponse((response) =>
      new URL(response.url()).pathname.startsWith('/external-artifact-previews/'),
    );
    await opened.page.getByRole('button', { name: /open artifact/iu }).click();
    const frame = opened.page.locator('iframe[title="Image preview"]');
    await expect(frame).toHaveAttribute('sandbox', '');
    const image = opened.page
      .frameLocator('iframe[title="Image preview"]')
      .locator('[data-artifactflow-image-preview]');
    await expect(image).toBeVisible({ timeout: 20_000 });
    const response = await responsePromise;
    const csp = await response.headerValue('content-security-policy');
    expect(response.url()).not.toContain(opened.secret);
    expect(csp).toContain('sandbox');
    expect(csp).not.toContain('sandbox allow-scripts');
    expect(csp).toContain("script-src 'none'");
    await opened.page.waitForTimeout(500);
    expect(renewalRequests).toBe(0);
  } finally {
    await imageContext.close();
  }
});
