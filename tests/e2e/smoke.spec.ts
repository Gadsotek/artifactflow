import { expect, test } from '@playwright/test';

test('redirects the public root to the login screen', async ({ page }) => {
  await page.goto('/');

  await expect(page).toHaveURL(/\/login$/u);
  await expect(page).toHaveTitle(/artifactflow/);
  await expect(page.getByRole('heading', { name: 'Sign in to your workspace' })).toBeVisible();
});
