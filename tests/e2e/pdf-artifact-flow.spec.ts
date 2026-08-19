import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
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

async function login(page: Page, email: string, password: string): Promise<void> {
  await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/dashboard$/u);
}

function buildPdf(text: string): Buffer {
  const content = `BT /F1 24 Tf 72 700 Td (${text}) Tj ET`;
  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    `<< /Length ${Buffer.byteLength(content, 'ascii')} >>\nstream\n${content}\nendstream`,
  ];
  let document = '%PDF-1.4\n';
  const offsets = [0];

  objects.forEach((object, index) => {
    offsets.push(Buffer.byteLength(document, 'ascii'));
    document += `${index + 1} 0 obj\n${object}\nendobj\n`;
  });

  const xrefOffset = Buffer.byteLength(document, 'ascii');
  document += `xref\n0 ${objects.length + 1}\n`;
  document += '0000000000 65535 f \n';
  offsets.slice(1).forEach((offset) => {
    document += `${offset.toString().padStart(10, '0')} 00000 n \n`;
  });
  document += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\n`;
  document += `startxref\n${xrefOffset}\n%%EOF\n`;

  return Buffer.from(document, 'ascii');
}

test('PDF upload, replacement, and restore are processed, searchable, isolated, and downloadable while renamed HTML is rejected @artifact-security', async ({
  page,
}) => {
  test.setTimeout(120_000);

  const suffix = randomUUID().replaceAll('-', '').slice(0, 12);
  const email = `pdf-artifact-${suffix}@example.test`;
  const password = `af${randomUUID().replaceAll('-', '')}`;
  const title = `PDF artifact cage ${suffix}`;
  const searchMarker = `PdfCage${suffix}`;
  const pdf = buildPdf(`ArtifactFlow ${searchMarker}`);
  let hostileScriptRequests = 0;

  runAppCommand(
    `php artisan artifactflow:create-user --name=PdfArtifactE2E --email=${email} --password=${password}`,
    'Failed to prepare the PDF artifact e2e account.',
  );

  await page.route('**/pdf-upload-js-probe', async (route) => {
    hostileScriptRequests += 1;
    await route.abort();
  });

  await login(page, email, password);
  await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-create-page-form]')).toHaveAttribute(
    'data-create-page-mode-ready',
    'true',
  );
  await page.locator('select[name="type"]').selectOption('pdf');
  await expect(page.locator('select[name="mode"]')).toHaveValue('pdf_upload');
  await page.locator('input[name="title"]').fill(title);
  await page.locator('input[name="pdf_file"]').setInputFiles({
    name: 'native-text.pdf',
    mimeType: 'application/pdf',
    buffer: pdf,
  });

  const previewResponsePromise = page.waitForResponse(
    (response) =>
      response.request().resourceType() === 'document' &&
      new URL(response.url()).pathname.startsWith('/pdf-artifacts/'),
  );

  await page.getByRole('button', { name: 'Save page' }).click();
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u, { timeout: 30_000 });
  await expect(page.getByRole('heading', { name: title })).toBeVisible();
  await expect(page.getByText('Text extraction: Indexed')).toBeVisible();

  const previewWrapper = page.locator('[data-pdf-preview]');
  const previewFrame = page.locator('iframe[title="PDF preview"]');
  expect(await previewWrapper.getAttribute('data-artifact-preview-refresh-endpoint')).toBeNull();
  await expect(previewFrame).toHaveAttribute('allow', '');
  await expect(previewFrame).toHaveAttribute('referrerpolicy', 'no-referrer');
  expect(await previewFrame.getAttribute('sandbox')).toBeNull();

  const previewResponse = await previewResponsePromise;
  expect(new URL(previewResponse.url()).origin).not.toBe(new URL(baseUrl).origin);
  expect(previewResponse.status()).toBe(200);
  expect(await previewResponse.headerValue('content-type')).toBe('application/pdf');
  expect(await previewResponse.headerValue('content-disposition')).toMatch(/^inline; filename=/u);
  expect(await previewResponse.headerValue('cache-control')).toBe('no-store, private');
  expect(await previewResponse.headerValue('x-content-type-options')).toBe('nosniff');
  expect(await previewResponse.headerValue('accept-ranges')).toBe('none');
  expect(await previewResponse.headerValue('x-frame-options')).toBeNull();
  expect(await previewResponse.headerValue('set-cookie')).toBeNull();
  expect(await previewResponse.headerValue('access-control-allow-origin')).toBeNull();
  expect(await previewResponse.request().headerValue('cookie')).toBeNull();
  const previewCsp = await previewResponse.headerValue('content-security-policy');
  expect(previewCsp).toContain("default-src 'none'");
  expect(previewCsp).toContain("object-src 'none'");
  expect(previewCsp).toContain(`frame-ancestors ${baseUrl}`);
  expect(previewCsp).not.toMatch(/(?:^|;)\s*sandbox(?:\s|;|$)/u);

  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('link', { name: 'Download PDF' }).click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toMatch(
    /^artifactflow-[0-9a-hjkmnp-tv-z]{26}-v1\.pdf$/u,
  );
  const downloadPath = await download.path();
  expect(downloadPath).not.toBeNull();
  expect(readFileSync(downloadPath as string)).toEqual(pdf);

  await page.goto(`${baseUrl}/pages?q=${encodeURIComponent(searchMarker)}`, {
    waitUntil: 'domcontentloaded',
  });
  await expect(page.getByRole('link', { name: title })).toBeVisible();

  await page.getByRole('link', { name: title }).click();
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u);
  const replacementMarker = `PdfReplacement${suffix}`;
  const replacementPdf = buildPdf(`ArtifactFlow ${replacementMarker}`);
  await page.getByRole('button', { name: 'Replace PDF' }).click();
  const replacementDialog = page.locator('#pdf-version-dialog');
  await expect(replacementDialog).toBeVisible();
  await replacementDialog.locator('input[name="pdf_file"]').setInputFiles({
    name: 'replacement.pdf',
    mimeType: 'application/pdf',
    buffer: replacementPdf,
  });
  await replacementDialog.getByRole('button', { name: 'Replace PDF' }).click();
  await expect(page.getByRole('heading', { name: title })).toBeVisible({ timeout: 30_000 });

  await page.goto(`${baseUrl}/pages?q=${encodeURIComponent(replacementMarker)}`, {
    waitUntil: 'domcontentloaded',
  });
  await expect(page.getByRole('link', { name: title })).toBeVisible();
  await page.goto(`${baseUrl}/pages?q=${encodeURIComponent(searchMarker)}`, {
    waitUntil: 'domcontentloaded',
  });
  await expect(page.getByRole('link', { name: title })).toHaveCount(0);

  await page.goto(`${baseUrl}/pages?q=${encodeURIComponent(replacementMarker)}`, {
    waitUntil: 'domcontentloaded',
  });
  await page.getByRole('link', { name: title }).click();
  await page.getByRole('button', { name: 'Versions' }).click();
  const historyDialog = page.locator('#page-versions-dialog');
  await expect(historyDialog).toBeVisible();
  const versionOne = historyDialog.locator('article').filter({ hasText: 'Version 1' });
  await versionOne.getByRole('button', { name: 'Restore' }).click();
  await expect(page.getByRole('heading', { name: title })).toBeVisible({ timeout: 30_000 });

  await page.goto(`${baseUrl}/pages?q=${encodeURIComponent(searchMarker)}`, {
    waitUntil: 'domcontentloaded',
  });
  await expect(page.getByRole('link', { name: title })).toBeVisible();
  await page.goto(`${baseUrl}/pages?q=${encodeURIComponent(replacementMarker)}`, {
    waitUntil: 'domcontentloaded',
  });
  await expect(page.getByRole('link', { name: title })).toHaveCount(0);

  await page.goto(`${baseUrl}/pages?q=${encodeURIComponent(searchMarker)}`, {
    waitUntil: 'domcontentloaded',
  });
  await page.getByRole('link', { name: title }).click();
  const reprocessResponsePromise = page.waitForResponse(
    (response) =>
      response.request().method() === 'POST' &&
      new URL(response.url()).pathname.endsWith('/pdf/reprocess'),
  );
  await page.getByRole('button', { name: 'Reprocess PDF text' }).click({ noWaitAfter: true });
  const reprocessResponse = await reprocessResponsePromise;
  expect(reprocessResponse.status()).toBe(302);
  await expect(page.getByText('PDF text and processing facts were refreshed.')).toBeVisible();
  const versionsButton = page.getByRole('button', { name: 'Versions' });
  await expect(versionsButton).toHaveAttribute('data-editor-dialog-trigger-ready', '');
  await versionsButton.click();
  const versionsAfterReprocess = page.locator('#page-versions-dialog');
  await expect(versionsAfterReprocess).toBeVisible();
  await expect(versionsAfterReprocess.getByText('Version 3', { exact: true })).toBeVisible();
  await expect(versionsAfterReprocess.getByText('Version 4', { exact: true })).toHaveCount(0);

  const hostileTitle = `Renamed HTML ${suffix}`;
  await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-create-page-form]')).toHaveAttribute(
    'data-create-page-mode-ready',
    'true',
  );
  await page.locator('select[name="type"]').selectOption('pdf');
  await expect(page.locator('select[name="mode"]')).toHaveValue('pdf_upload');
  await page.locator('input[name="title"]').fill(hostileTitle);
  await page.locator('input[name="pdf_file"]').setInputFiles({
    name: 'renamed-html.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.from(
      `<!doctype html><script>fetch('${baseUrl}/pdf-upload-js-probe')</script>`,
      'utf8',
    ),
  });
  const rejectedUploadResponsePromise = page.waitForResponse(
    (response) =>
      response.request().method() === 'POST' && new URL(response.url()).pathname === '/pages',
  );
  const rejectedUploadNavigationPromise = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
  await page.getByRole('button', { name: 'Save page' }).click();
  const rejectedUploadResponse = await rejectedUploadResponsePromise;
  await rejectedUploadNavigationPromise;
  expect(rejectedUploadResponse.status()).toBe(302);
  expect(await rejectedUploadResponse.headerValue('location')).toBe(`${baseUrl}/pages/create`);
  await expect(page).toHaveURL(/\/pages\/create$/u);
  await expect(page.getByRole('heading', { name: 'Create page' })).toBeVisible();
  expect(hostileScriptRequests).toBe(0);

  await page.goto(`${baseUrl}/pages?q=${encodeURIComponent(hostileTitle)}`, {
    waitUntil: 'domcontentloaded',
  });
  await expect(page.getByRole('link', { name: hostileTitle })).toHaveCount(0);
});
