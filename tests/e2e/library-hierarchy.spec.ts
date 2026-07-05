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

async function createMarkdownPage(
  page: Page,
  title: string,
  parentTitle: string | null = null,
): Promise<string> {
  if (parentTitle === null) {
    await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'networkidle' });
  } else {
    await expect(page.getByRole('link', { name: 'New page', exact: true })).toHaveAttribute(
      'href',
      /parent_page_uid=[0-9a-hjkmnp-tv-z]{26}/u,
    );
    await page.getByRole('link', { name: 'Create page', exact: true }).click();
    await expect(page.locator('select[name="parent_page_uid"] option:checked')).toHaveText(
      parentTitle,
    );
  }

  const editorForm = page.locator('[data-content-editor]');
  await expect(editorForm).toHaveAttribute('data-editor-ready', 'true');

  const workspaceUid = await page.locator('select[name="workspace_uid"]').inputValue();
  await page.locator('input[name="title"]').fill(title);

  if (parentTitle !== null) {
    await page.locator('select[name="parent_page_uid"]').selectOption({ label: parentTitle });
  }

  const editor = page.getByRole('textbox', { name: 'Page content' });
  await editor.fill(`# ${title}`);
  await expect(page.locator('[data-editor-textarea]')).toHaveValue(`# ${title}`);
  await page.getByRole('button', { name: 'Save page' }).click();
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u);

  return workspaceUid;
}

test('Library and Overview visually nest a child page beneath its visible parent', async ({
  page,
}) => {
  const suffix = randomUUID().replaceAll('-', '').slice(0, 10);
  const email = `library-hierarchy-${suffix}@example.test`;
  const password = `af${randomUUID().replaceAll('-', '')}`;
  const parentTitle = `Parent ${suffix}`;
  const childTitle = `Child ${suffix}`;

  runAppCommand(
    `php artisan artifactflow:create-user --name=LibraryHierarchyE2E --email=${email} --password=${password}`,
  );

  await login(page, email, password);
  const workspaceUid = await createMarkdownPage(page, parentTitle);
  const childWorkspaceUid = await createMarkdownPage(page, childTitle, parentTitle);
  expect(childWorkspaceUid).toBe(workspaceUid);
  await page.goto(`${baseUrl}/pages?workspace_uid=${workspaceUid}&sort=title`, {
    waitUntil: 'networkidle',
  });

  const parentHeading = page.getByRole('heading', { name: parentTitle, exact: true });
  const childHeading = page.getByRole('heading', { name: childTitle, exact: true });
  const parentRow = page.locator('a[data-page-hierarchy-depth]').filter({ has: parentHeading });
  const childRow = page.locator('a[data-page-hierarchy-depth]').filter({ has: childHeading });

  await expect(parentRow).toHaveAttribute('data-page-hierarchy-depth', '0');
  await expect(childRow).toHaveAttribute('data-page-hierarchy-depth', '1');
  await expect(childRow.getByText(`Under ${parentTitle}`, { exact: true })).toBeVisible();

  const parentX = await parentHeading.evaluate((element) => element.getBoundingClientRect().x);
  const childX = await childHeading.evaluate((element) => element.getBoundingClientRect().x);

  expect(childX - parentX).toBeGreaterThan(20);

  await page.goto(`${baseUrl}/dashboard`, { waitUntil: 'networkidle' });
  const overviewParentHeading = page.getByRole('heading', { name: parentTitle, exact: true });
  const overviewChildHeading = page.getByRole('heading', { name: childTitle, exact: true });
  const overviewParentRow = page
    .locator('a[data-page-hierarchy-depth]')
    .filter({ has: overviewParentHeading });
  const overviewChildRow = page
    .locator('a[data-page-hierarchy-depth]')
    .filter({ has: overviewChildHeading });

  await expect(overviewParentRow).toHaveAttribute('data-page-hierarchy-depth', '0');
  await expect(overviewChildRow).toHaveAttribute('data-page-hierarchy-depth', '1');
  await expect(overviewChildRow.getByText(`Under ${parentTitle}`, { exact: true })).toBeVisible();

  const overviewParentX = await overviewParentHeading.evaluate(
    (element) => element.getBoundingClientRect().x,
  );
  const overviewChildX = await overviewChildHeading.evaluate(
    (element) => element.getBoundingClientRect().x,
  );

  expect(overviewChildX - overviewParentX).toBeGreaterThan(20);
});
