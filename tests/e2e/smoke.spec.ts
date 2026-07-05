import { expect, test } from '@playwright/test';

test('renders the artifactflow shell', async ({ page }) => {
  await page.goto('/');

  await expect(page).toHaveTitle(/artifactflow/);
  await expect(page.getByRole('heading', { name: /Store, search, and safely run/i })).toBeVisible();
});
