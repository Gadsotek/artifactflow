import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const baseUrl = (process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:18180').replace(/\/$/u, '');
const repoRoot = fileURLToPath(new URL('../..', import.meta.url));
const appCommandTarget = process.env.E2E_APP_COMMAND_TARGET ?? 'run-e2e-app-cmd';

test.use({ screenshot: 'off', trace: 'off', video: 'off' });

function runAppCommand(appCommand: string): void {
  if (!['run-e2e-app-cmd', 'run-app-cmd'].includes(appCommandTarget)) {
    throw new Error('Unsupported e2e app command target.');
  }

  execFileSync('make', [appCommandTarget, `APP_CMD=${appCommand}`], {
    cwd: repoRoot,
    stdio: 'ignore',
  });
}

async function login(page: Page, email: string, password: string): Promise<void> {
  await page.goto(`${baseUrl}/login`, { waitUntil: 'networkidle' });
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/dashboard$/u);
}

test('page access autocomplete finds and grants a registered coworker', async ({ page }) => {
  const suffix = randomUUID().replaceAll('-', '').slice(0, 10);
  const ownerEmail = `access-owner-${suffix}@example.test`;
  const coworkerEmail = `access-coworker-${suffix}@example.test`;
  const password = `af${randomUUID().replaceAll('-', '')}`;
  const coworkerName = `AccessCoworker${suffix}`;
  const pageTitle = `Access autocomplete ${suffix}`;

  runAppCommand(
    `php artisan artifactflow:create-user --name=AccessOwner${suffix} --email=${ownerEmail} --password=${password}`,
  );
  runAppCommand(
    `php artisan artifactflow:create-user --name=${coworkerName} --email=${coworkerEmail} --password=${password}`,
  );

  await login(page, ownerEmail, password);
  await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'networkidle' });
  await expect(page.locator('[data-content-editor]')).toHaveAttribute('data-editor-ready', 'true');
  await page.locator('input[name="title"]').fill(pageTitle);
  await page.getByRole('textbox', { name: 'Page content' }).fill(`# ${pageTitle}`);
  await page.getByRole('button', { name: 'Save page' }).click();
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u);

  const accessButton = page.getByRole('button', { name: 'Access', exact: true });
  await expect(accessButton).toHaveAttribute('data-editor-dialog-trigger-ready', '');
  await expect(page.locator('[data-known-user-picker]')).toHaveAttribute(
    'data-known-user-picker-ready',
    '',
  );
  await accessButton.click();
  const dialog = page.getByRole('dialog', { name: 'Access overrides' });
  const picker = dialog.getByRole('combobox', { name: 'User' });
  await picker.fill(coworkerName);

  const option = dialog.getByRole('option', { name: new RegExp(coworkerEmail, 'u') });
  await expect(option).toBeVisible();
  await option.click();
  await expect(picker).toHaveValue(coworkerEmail);
  await expect(dialog.getByText(`Granting access to ${coworkerName} (${coworkerEmail}).`)).toBeVisible();

  await dialog.getByRole('button', { name: 'Grant user access' }).click();
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u);
  await expect(
    page.getByText(
      'If that email belongs to an eligible registered coworker, their access has been granted.',
    ),
  ).toBeVisible();

  await expect(accessButton).toHaveAttribute('data-editor-dialog-trigger-ready', '');
  await accessButton.click();
  await expect(page.getByRole('dialog', { name: 'Access overrides' })).toContainText(coworkerEmail);
});
