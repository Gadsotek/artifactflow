import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';

test.use({ screenshot: 'off', video: 'off', trace: 'off' });

function seed(action: 'accounts' | 'create' | 'edit', suffix: string): string {
  try {
    return execFileSync('make', ['run-e2e-app-cmd', `APP_CMD=php tests/e2e/support/seed-passage-retrieval.php ${action} ${suffix}`], {
      encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
    });
  } catch {
    throw new Error(`Passage test fixture failed during ${action}.`);
  }
}

async function signIn(page: Page, email: string, suffix: string): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(`Af${suffix}`);
  await page.getByRole('button', { name: 'Sign in' }).click();
}

async function confirm(page: Page, code: string, button: string): Promise<void> {
  await page.getByRole('button', { name: 'Use a recovery code' }).click();
  await page.locator('input[name="recovery_code"]:visible').fill(code.toUpperCase());
  await page.getByRole('button', { name: button }).click();
}

test('administrator toggles passage retrieval and a reader can reopen a cited revision after an edit', async ({ page, browser, baseURL }) => {
  test.setTimeout(90_000);
  const suffix = randomUUID().replaceAll('-', '');
  seed('accounts', suffix);
  await signIn(page, `passage-admin-${suffix}@example.test`, suffix);
  await confirm(page, suffix.slice(0, 10), 'Verify');
  await expect(page).toHaveURL(/\/dashboard$/u);
  await page.goto('/admin/settings');
  await confirm(page, suffix.slice(10, 20), 'Confirm access');

  const setting = page.getByRole('combobox', { name: 'Passage retrieval', exact: true });
  await expect(setting).toHaveValue('0');
  await setting.selectOption('1');
  await page.getByRole('button', { name: 'Save passage retrieval' }).click();
  await expect(setting).toHaveValue('1');
  const citation = seed('create', suffix).match(/^PASSAGE_CITATION=(https?:\/\/[^\s]+)$/mu)?.[1];
  if (!citation || new URL(citation).origin !== new URL(page.url()).origin) {
    throw new Error('The passage tool did not return a citation on the application origin.');
  }
  seed('edit', suffix);
  await setting.selectOption('0');
  await page.getByRole('button', { name: 'Save passage retrieval' }).click();
  await expect(setting).toHaveValue('0');

  const reader = await browser.newPage({ baseURL });
  try {
    await signIn(reader, `passage-reader-${suffix}@example.test`, suffix);
    await expect(reader).toHaveURL(/\/dashboard$/u);
    const denied = await reader.goto('/admin/settings');
    expect(denied?.status()).toBe(403);
    await reader.goto(citation);
    await expect(reader.getByText('Historical version 1 · Current version is 2')).toBeVisible();
    await expect(reader.locator('#version-preview')).toContainText('Reconcile the invoice before payment.');
  } finally {
    await reader.close();
  }
});
