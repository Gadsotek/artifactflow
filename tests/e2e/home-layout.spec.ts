import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';

test.use({ trace: 'off', video: 'off' });

test('Library search controls align for one workspace and all workspaces', async ({ page }) => {
  if (process.env.E2E_APP_COMMAND_TARGET !== 'run-e2e-app-cmd')
    throw new Error('Use the isolated make e2e wrapper.');
  const suffix = randomUUID().replaceAll('-', '');
  const email = `library-alignment-${suffix}@example.test`;
  const password = `af${suffix}`;
  execFileSync(
    'make',
    [
      'run-e2e-app-cmd',
      `APP_CMD=php artisan artifactflow:create-user --name=LibraryAlignment --email=${email} --password=${password}`,
    ],
    { stdio: 'ignore' },
  );
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/dashboard$/u);
  await page.setViewportSize({ width: 1600, height: 1000 });
  await page.goto('/pages?workspace_uid=all');
  const workspace = await page
    .locator('[data-library-workspace-filter] option')
    .nth(1)
    .getAttribute('value');
  expect(workspace).toBeTruthy();
  for (const scope of ['all', workspace!]) {
    await page.goto(`/pages?workspace_uid=${scope}`);
    const controls = await page
      .locator('form[action$="/pages"]')
      .first()
      .evaluate((form) => {
        const input = form.querySelector('input[type="search"]')!;
        const select = form.querySelector('select[name="type"]')!;
        const button = Array.from(form.querySelectorAll('button')).find(
          (button) => button.textContent?.trim() === 'Search pages',
        )!;
        return [input, select, button].map((element) => ({
          top: element.getBoundingClientRect().top,
          height: element.getBoundingClientRect().height,
        }));
      });
    for (const control of controls.slice(1)) {
      expect(Math.abs(control.top - controls[0].top)).toBeLessThanOrEqual(1);
      expect(Math.abs(control.height - controls[0].height)).toBeLessThanOrEqual(1);
    }
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    await page.setViewportSize({ width: 1600, height: 1000 });
  }
});

function contrast(first: string, second: string): number {
  const luminance = (color: string) => {
    const channels = color
      .match(/[\d.]+/gu)!
      .slice(0, 3)
      .map((value) => {
        const channel = Number(value) / 255;
        return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
      });
    return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
  };
  const a = luminance(first);
  const b = luminance(second);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

async function createWorkspace(page: Page, name: string, parent: string | null): Promise<void> {
  const trigger = page.getByRole('button', { name: 'Create workspace', exact: true });
  await expect(trigger).toHaveAttribute('data-editor-dialog-trigger-ready', '');
  await trigger.click();
  const dialog = page.locator('#workspace-create-dialog');
  await dialog.getByLabel('Workspace name').fill(name);
  const option = parent
    ? await dialog
        .locator('select[name="parent_workspace_uid"] option')
        .filter({ hasText: parent })
        .getAttribute('value')
    : '';
  await dialog.getByLabel('Parent workspace').selectOption(option ?? '');
  await dialog.getByRole('button', { name: 'Create workspace', exact: true }).click();
  await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
}

test('home layout integrates personal sections and keeps search and workspace levels clear', async ({
  page,
}) => {
  test.setTimeout(120_000);
  if (process.env.E2E_APP_COMMAND_TARGET !== 'run-e2e-app-cmd')
    throw new Error('Use the isolated make e2e wrapper.');
  const suffix = randomUUID().replaceAll('-', '');
  const email = `home-layout-${suffix}@example.test`;
  const password = `af${suffix}`;
  execFileSync(
    'make',
    [
      'run-e2e-app-cmd',
      `APP_CMD=php artisan artifactflow:create-user --name=HomeLayout --email=${email} --password=${password}`,
    ],
    { stdio: 'ignore' },
  );
  await page.setViewportSize({ width: 1440, height: 1100 });
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/dashboard$/u);
  await createWorkspace(page, 'Product design', null);
  await createWorkspace(page, 'Research and discovery', 'Product design');
  await createWorkspace(page, 'Customer interviews', 'Research and discovery');

  for (const title of [
    'Research findings and decisions',
    'A detailed reference for our next product design review',
  ]) {
    await page.goto('/pages/create');
    await expect(page.locator('[data-content-editor]')).toHaveAttribute(
      'data-editor-ready',
      'true',
    );
    await page.locator('input[name="title"]').fill(title);
    await page
      .getByRole('textbox', { name: 'Page content' })
      .fill(`# ${title}\n\nA concise summary for the team.`);
    await page.getByRole('button', { name: 'Save page' }).click();
    await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u);
    await page.getByRole('button', { name: 'Favorite', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Remove favorite', exact: true })).toBeVisible();
  }

  await page.goto('/dashboard');
  for (const theme of ['Light', 'Dark']) {
    await page.getByRole('button', { name: `${theme} theme`, exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', theme.toLowerCase());
    await page.screenshot({
      path: `storage/framework/testing/ux-home-${theme.toLowerCase()}.png`,
      fullPage: true,
    });
    const overview = page.locator('#workspace-overview-panel');
    await expect(
      overview.getByRole('heading', { name: 'Continue where you left off' }),
    ).toBeVisible();
    const geometry = await page.evaluate(() => {
      const box = (selector: string) => document.querySelector(selector)!.getBoundingClientRect();
      const search = box('[data-home-search] input[type="search"]');
      const form = box('[data-home-search]');
      const trigger = box('[data-open-quick-navigation]');
      const nav = box('[data-primary-navigation]');
      const workspaceSearch = document.querySelector('[data-workspace-search]')!;
      const style = getComputedStyle(workspaceSearch);
      return {
        searchWidth: search.width,
        formWidth: form.width,
        gap: nav.top - trigger.bottom,
        searchHeight: search.height,
        border: Number.parseFloat(style.borderTopWidth),
        borderColor: style.borderTopColor,
        background: style.backgroundColor,
        placeholder: getComputedStyle(workspaceSearch, '::placeholder').color,
      };
    });
    expect(geometry.searchWidth / geometry.formWidth).toBeGreaterThan(0.7);
    expect(geometry.searchHeight).toBeGreaterThanOrEqual(44);
    expect(geometry.gap).toBeGreaterThanOrEqual(16);
    expect(geometry.border).toBeGreaterThanOrEqual(1);
    expect(contrast(geometry.borderColor, geometry.background)).toBeGreaterThanOrEqual(3);
    expect(contrast(geometry.placeholder, geometry.background)).toBeGreaterThanOrEqual(4.5);
  }

  const names = page.locator('[data-workspace-name]');
  const positions = await names.evaluateAll((elements) =>
    elements
      .filter((element) =>
        ['Product design', 'Research and discovery', 'Customer interviews'].includes(
          element.textContent!.trim(),
        ),
      )
      .map((element) => element.getBoundingClientRect().x),
  );
  expect(positions).toHaveLength(3);
  expect(positions[1] - positions[0]).toBeGreaterThanOrEqual(14);
  expect(positions[2] - positions[1]).toBeGreaterThanOrEqual(14);
  const search = page.locator('[data-workspace-search]');
  await search.fill('Customer');
  await expect(page.locator('[data-workspace-option]:visible')).toHaveCount(1);
  await search.fill('');

  for (const width of [1040, 768, 390]) {
    await page.setViewportSize({ width, height: 1000 });
    if (width <= 960)
      await expect(
        page.getByRole('button', { name: 'Open navigation', exact: true }),
      ).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    await page.screenshot({
      path: `storage/framework/testing/ux-home-${width}.png`,
      fullPage: true,
    });
  }
});
