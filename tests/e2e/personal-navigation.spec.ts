import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';

test('personal navigation supports favorites, recents, keyboard search and mobile themes', async ({
  page,
}) => {
  if (process.env.E2E_APP_COMMAND_TARGET !== 'run-e2e-app-cmd') {
    throw new Error('This test requires the isolated make e2e environment.');
  }
  const suffix = randomUUID().replaceAll('-', '');
  const email = `navigation-${suffix}@example.test`;
  const password = `af${suffix}`;
  const title = `Navigation guide ${suffix.slice(0, 8)}`;
  const quickNavigation = page.locator('[data-quick-navigation]');
  execFileSync(
    'make',
    [
      'run-e2e-app-cmd',
      `APP_CMD=php artisan artifactflow:create-user --name=NavigationE2E --email=${email} --password=${password}`,
    ],
    { stdio: 'ignore' },
  );
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/dashboard$/u);
  await page.goto('/pages/create');
  await expect(page.locator('[data-content-editor]')).toHaveAttribute('data-editor-ready', 'true');
  await page.locator('input[name="title"]').fill(title);
  await page.getByRole('textbox', { name: 'Page content' }).fill(`# ${title}`);
  await page.getByRole('button', { name: 'Save page' }).click();
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u);
  const savedUrl = page.url();
  await expect(
    page.getByRole('link', { name: 'New page', exact: true }).first(),
  ).not.toHaveAttribute('href', /parent_page_uid/u);
  await page.getByRole('button', { name: 'Favorite', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Remove favorite', exact: true })).toBeVisible();
  await page.getByRole('link', { name: 'Favorites', exact: true }).first().click();
  await expect(page.getByRole('link', { name: new RegExp(title, 'u') })).toBeVisible();
  await page.getByRole('link', { name: 'Recently opened', exact: true }).first().click();
  await expect(page.getByRole('link', { name: new RegExp(title, 'u') })).toBeVisible();
  await page.getByRole('button', { name: 'Clear history', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'No recently opened pages' })).toBeVisible();
  await expect(quickNavigation).toHaveAttribute('data-quick-navigation-ready', 'true');
  await page.getByRole('button', { name: 'Search or jump to', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Search or jump to' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('searchbox', { name: 'Find a page' }).fill(title);
  await expect(dialog.getByRole('link', { name: new RegExp(title, 'u') })).toBeVisible();
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(savedUrl);
  await expect(quickNavigation).toHaveAttribute('data-quick-navigation-ready', 'true');
  await page.keyboard.press('Control+k');
  await expect(dialog).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).not.toBeVisible();
  const editButton = page.getByRole('button', { name: 'Edit Markdown', exact: true });
  await expect(editButton).toHaveAttribute('data-editor-dialog-trigger-ready', '');
  await editButton.click();
  const editor = page.getByRole('dialog', { name: 'Edit Markdown', exact: true });
  await expect(editor.locator('[data-editor-unsaved-guard]')).toHaveAttribute(
    'data-editor-unsaved-guard',
    'ready',
  );
  await editor.getByRole('textbox', { name: 'Page content' }).fill('# Unsaved draft worth keeping');
  page.once('dialog', async (confirmation) => {
    await confirmation.dismiss();
  });
  await editor.getByRole('button', { name: 'Close content editor' }).click();
  await expect(editor).toBeVisible();
  await page.route('**/pages/*/versions', (route) =>
    route.fulfill({ status: 409, body: 'This page changed since you opened it.' }),
  );
  await editor.getByRole('button', { name: 'Save new version' }).click();
  await expect(editor.locator('[data-concurrency-error]')).toContainText('This page changed');
  await expect(editor.getByRole('textbox', { name: 'Page content' })).toContainText(
    'Unsaved draft worth keeping',
  );
  await expect(editor.getByRole('button', { name: 'Copy draft' })).toBeVisible();
  await expect(editor.getByRole('link', { name: 'Open current version' })).toHaveAttribute(
    'target',
    '_blank',
  );
  page.once('dialog', async (confirmation) => {
    await confirmation.accept();
  });
  await page.reload();
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(quickNavigation).toHaveAttribute('data-quick-navigation-ready', 'true');
  await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
  await expect(
    page.getByRole('link', { name: 'Recently opened', exact: true }).first(),
  ).toBeVisible();
  await page.getByRole('button', { name: 'Dark theme', exact: true }).click();
  await expect(page.locator('html')).toHaveClass(/dark/u);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
    true,
  );
  await page.screenshot({ path: 'storage/framework/testing/ux-mobile-dark.png', fullPage: true });
});
