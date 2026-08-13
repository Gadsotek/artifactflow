import { expect, test, type Browser, type BrowserContext, type Locator, type Page } from '@playwright/test';
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
  await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/dashboard$/u);
}

async function openWorkspaceDialog(page: Page): Promise<Locator> {
  const trigger = page.getByRole('button', { name: 'Create workspace' });
  await expect(trigger).toHaveAttribute('data-editor-dialog-trigger-ready', '');
  await trigger.click();
  const dialog = page.locator('#workspace-create-dialog');
  await expect(dialog).toBeVisible();

  return dialog;
}

async function createWorkspace(page: Page, name: string, parentName: string | null): Promise<void> {
  const dialog = await openWorkspaceDialog(page);
  await dialog.getByLabel('Workspace name').fill(name);

  if (parentName === null) {
    await dialog.getByLabel('Parent workspace').selectOption('');
  } else {
    const parentOption = dialog
      .locator('select[name="parent_workspace_uid"] option')
      .filter({ hasText: parentName });
    const parentWorkspaceUid = await parentOption.getAttribute('value');
    expect(parentWorkspaceUid).not.toBeNull();
    await dialog.getByLabel('Parent workspace').selectOption(parentWorkspaceUid ?? '');
  }

  await dialog.getByRole('button', { name: 'Create workspace' }).click();
  await expect(page).toHaveURL(/\/dashboard$/u);
  await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
}

async function switchWorkspace(page: Page, name: string): Promise<void> {
  await page.locator('[data-workspace-depth]').filter({ hasText: name }).click();
  await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
}

async function addEditorCollaborator(page: Page, workspaceName: string, email: string): Promise<void> {
  await switchWorkspace(page, workspaceName);
  await page.goto(`${baseUrl}/dashboard?tab=members`, { waitUntil: 'domcontentloaded' });
  const addCollaboratorButton = page.getByRole('button', { name: 'Add existing collaborator' });
  await expect(addCollaboratorButton).toHaveAttribute('data-editor-dialog-trigger-ready', '');
  await addCollaboratorButton.click();

  const collaboratorDialog = page.getByRole('dialog', { name: 'Add existing collaborator' });
  const collaboratorPicker = collaboratorDialog.getByRole('combobox', { name: 'Search people' });
  await expect(collaboratorDialog.locator('[data-known-user-picker]')).toHaveAttribute(
    'data-known-user-picker-ready',
    '',
  );
  await collaboratorPicker.fill(email);
  const collaboratorOption = collaboratorDialog.getByRole('option', {
    name: new RegExp(email, 'u'),
  });
  await expect(collaboratorOption).toBeVisible();
  await collaboratorOption.click();
  await collaboratorDialog.getByLabel('Workspace role').selectOption('editor');
  await collaboratorDialog.getByRole('button', { name: 'Add to workspace' }).click();
  await expect(page.getByText('Collaborator added to the workspace.')).toBeVisible();
}

async function createHtmlArtifact(
  page: Page,
  workspaceName: string,
  title: string,
  markerId: string,
): Promise<string> {
  await switchWorkspace(page, workspaceName);
  await page.locator('header').getByRole('link', { name: 'Create page' }).click();
  const editorForm = page.locator('[data-content-editor]');
  await expect(editorForm).toHaveAttribute('data-editor-ready', 'true');
  await page.locator('select[name="type"]').selectOption('html_artifact');
  await page.locator('select[name="mode"]').selectOption('html_paste');
  await page.locator('input[name="title"]').fill(title);
  await expect(editorForm).toHaveAttribute('data-editor-language', 'html');
  const sourceEditor = page.locator('[data-source-editor-mount] .cm-content');
  await sourceEditor.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.insertText(
    `<!doctype html><html><body><h1 id="${markerId}">Inherited preview ready</h1></body></html>`,
  );
  await page.getByRole('button', { name: 'Save page' }).click();
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u, { timeout: 20_000 });

  return page.url();
}

async function openInheritedPreview(
  browser: Browser,
  email: string,
  password: string,
  pageUrl: string,
  pageTitle: string,
  markerId: string,
): Promise<{ context: BrowserContext; page: Page; frame: Locator; signedPreviewUrl: string }> {
  const context = await browser.newContext();
  const memberPage = await context.newPage();
  await login(memberPage, email, password);
  await memberPage.goto(pageUrl, { waitUntil: 'domcontentloaded' });
  await expect(memberPage.getByRole('heading', { name: pageTitle })).toBeVisible();
  const frame = memberPage.locator('iframe[title="Artifact preview"]');
  await expect(memberPage.frameLocator('iframe[title="Artifact preview"]').locator(`#${markerId}`)).toHaveText(
    'Inherited preview ready',
    { timeout: 20_000 },
  );
  const signedPreviewUrl = await frame.getAttribute('src');

  if (signedPreviewUrl === null) {
    throw new Error('Expected a saved artifact preview URL.');
  }

  return { context, page: memberPage, frame, signedPreviewUrl };
}

test('creates, navigates, and reparents a three-level workspace tree', async ({ page }) => {
  test.setTimeout(90_000);

  const suffix = randomUUID().replaceAll('-', '').slice(0, 10);
  const email = `workspace-hierarchy-${suffix}@example.test`;
  const password = `af${randomUUID().replaceAll('-', '')}`;
  const rootName = `Company ${suffix}`;
  const childName = `Department ${suffix}`;
  const grandchildName = `Project ${suffix}`;

  runAppCommand(
    `php artisan artifactflow:create-user --name=WorkspaceHierarchyE2E --email=${email} --password=${password}`,
  );

  await login(page, email, password);
  await createWorkspace(page, rootName, null);
  await createWorkspace(page, childName, rootName);
  await createWorkspace(page, grandchildName, childName);

  const rootItem = page.locator('[data-workspace-depth]').filter({ hasText: rootName });
  const childItem = page.locator('[data-workspace-depth]').filter({ hasText: childName });
  const grandchildItem = page.locator('[data-workspace-depth]').filter({ hasText: grandchildName });

  await expect(rootItem).toHaveAttribute('data-workspace-depth', '0');
  await expect(childItem).toHaveAttribute('data-workspace-depth', '1');
  await expect(grandchildItem).toHaveAttribute('data-workspace-depth', '2');

  await openWorkspaceDialog(page);
  await expect(
    page.locator('#workspace-create-dialog select[name="parent_workspace_uid"] option', {
      hasText: grandchildName,
    }),
  ).toHaveCount(0);
  await page.locator('#workspace-create-dialog').getByRole('button', { name: 'Close workspace form' }).click();

  await page.goto(`${baseUrl}/dashboard?tab=settings`, { waitUntil: 'domcontentloaded' });
  const hierarchyForm = page.locator('#workspace-settings-panel form[action*="/hierarchy"]');
  await hierarchyForm.locator('select[name="parent_workspace_uid"]').selectOption('');
  await hierarchyForm.getByRole('button', { name: 'Review impact' }).click();
  const impact = page.locator('[data-workspace-hierarchy-preview]');
  await expect(impact.getByRole('heading', { name: 'Review hierarchy change' })).toBeVisible();
  await expect(impact.getByText('1 workspace moved')).toBeVisible();
  await impact.getByRole('button', { name: 'Confirm hierarchy change' }).click();
  await expect(page).toHaveURL(/\/dashboard\?tab=settings$/u);
  await expect(page.getByText('Workspace hierarchy updated.')).toBeVisible();
  await expect(page.locator('[data-workspace-depth]').filter({ hasText: grandchildName })).toHaveAttribute(
    'data-workspace-depth',
    '0',
  );
});

test('parent membership removal invalidates a loaded descendant preview @artifact-security', async ({
  browser,
  page,
}: {
  browser: Browser;
  page: Page;
}) => {
  test.setTimeout(120_000);

  const suffix = randomUUID().replaceAll('-', '').slice(0, 10);
  const adminEmail = `hierarchy-preview-admin-${suffix}@example.test`;
  const memberEmail = `hierarchy-preview-member-${suffix}@example.test`;
  const memberName = `HierarchyMember${suffix}`;
  const password = `af${randomUUID().replaceAll('-', '')}`;
  const rootName = `Preview root ${suffix}`;
  const childName = `Preview child ${suffix}`;
  const grandchildName = `Preview project ${suffix}`;
  const pageTitle = `Inherited preview ${suffix}`;

  runAppCommand(
    `php artisan artifactflow:create-user --name=HierarchyAdmin${suffix} --email=${adminEmail} --password=${password}`,
  );
  runAppCommand(
    `php artisan artifactflow:create-user --name=${memberName} --email=${memberEmail} --password=${password}`,
  );

  await login(page, adminEmail, password);
  await createWorkspace(page, rootName, null);
  await createWorkspace(page, childName, rootName);
  await createWorkspace(page, grandchildName, childName);

  await addEditorCollaborator(page, rootName, memberEmail);
  const pageUrl = await createHtmlArtifact(
    page,
    grandchildName,
    pageTitle,
    'hierarchy-preview-ready',
  );
  const inheritedPreview = await openInheritedPreview(
    browser,
    memberEmail,
    password,
    pageUrl,
    pageTitle,
    'hierarchy-preview-ready',
  );
  const memberContext = inheritedPreview.context;
  const memberPage = inheritedPreview.page;
  const memberFrame = inheritedPreview.frame;
  const signedPreviewUrl = inheritedPreview.signedPreviewUrl;

  try {
    await page.goto(`${baseUrl}/dashboard`, { waitUntil: 'domcontentloaded' });
    await switchWorkspace(page, rootName);
    await page.goto(`${baseUrl}/dashboard?tab=members`, { waitUntil: 'domcontentloaded' });
    const memberRow = page.locator('.af-member-row').filter({ hasText: memberEmail });
    await expect(memberRow).toBeVisible();
    await memberRow.getByRole('button', { name: 'Remove member' }).click();
    await expect(page.getByText('Workspace member removed.')).toBeVisible();

    const revokedPreviewResponse = memberPage.waitForResponse(
      (response) => response.url() === signedPreviewUrl,
    );
    await memberFrame.evaluate((iframe, url) => {
      if (!(iframe instanceof HTMLIFrameElement) || url === null) {
        throw new Error('Expected a saved artifact preview URL.');
      }

      iframe.src = url;
    }, signedPreviewUrl);
    expect((await revokedPreviewResponse).status()).toBe(404);

    const revokedPageResponse = await memberPage.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    expect(revokedPageResponse?.status()).toBe(404);
  } finally {
    await memberContext.close();
  }
});

test('reparenting invalidates a loaded preview for a member losing inherited reach @artifact-security', async ({
  browser,
  page,
}: {
  browser: Browser;
  page: Page;
}) => {
  test.setTimeout(120_000);

  const suffix = randomUUID().replaceAll('-', '').slice(0, 10);
  const adminEmail = `hierarchy-reparent-admin-${suffix}@example.test`;
  const memberEmail = `hierarchy-reparent-member-${suffix}@example.test`;
  const password = `af${randomUUID().replaceAll('-', '')}`;
  const oldRootName = `Old root ${suffix}`;
  const newRootName = `New root ${suffix}`;
  const childName = `Moving child ${suffix}`;
  const pageTitle = `Reparented preview ${suffix}`;
  const markerId = `reparent-preview-${suffix}`;

  runAppCommand(
    `php artisan artifactflow:create-user --name=HierarchyReparentAdmin${suffix} --email=${adminEmail} --password=${password}`,
  );
  runAppCommand(
    `php artisan artifactflow:create-user --name=HierarchyReparentMember${suffix} --email=${memberEmail} --password=${password}`,
  );

  await login(page, adminEmail, password);
  await createWorkspace(page, oldRootName, null);
  await createWorkspace(page, newRootName, null);
  await createWorkspace(page, childName, oldRootName);
  await addEditorCollaborator(page, oldRootName, memberEmail);
  const pageUrl = await createHtmlArtifact(page, childName, pageTitle, markerId);
  const inheritedPreview = await openInheritedPreview(
    browser,
    memberEmail,
    password,
    pageUrl,
    pageTitle,
    markerId,
  );

  try {
    await page.goto(`${baseUrl}/dashboard`, { waitUntil: 'domcontentloaded' });
    await switchWorkspace(page, childName);
    await page.goto(`${baseUrl}/dashboard?tab=settings`, { waitUntil: 'domcontentloaded' });
    const hierarchyForm = page.locator('#workspace-settings-panel form[action$="/hierarchy/preview"]');
    const newParentOption = hierarchyForm
      .locator('select[name="parent_workspace_uid"] option')
      .filter({ hasText: newRootName });
    const newParentUid = await newParentOption.getAttribute('value');
    expect(newParentUid).not.toBeNull();
    await hierarchyForm.locator('select[name="parent_workspace_uid"]').selectOption(newParentUid ?? '');
    await hierarchyForm.getByRole('button', { name: 'Review impact' }).click();

    const impact = page.locator('[data-workspace-hierarchy-preview]');
    await expect(impact.getByText('1 user losing authority')).toBeVisible();
    await impact.getByRole('button', { name: 'Confirm hierarchy change' }).click();
    await expect(page.getByText('Workspace hierarchy updated.')).toBeVisible();

    const revokedPreviewResponse = inheritedPreview.page.waitForResponse(
      (response) => response.url() === inheritedPreview.signedPreviewUrl,
    );
    await inheritedPreview.frame.evaluate((iframe, url) => {
      if (!(iframe instanceof HTMLIFrameElement)) {
        throw new Error('Expected a saved artifact preview iframe.');
      }

      iframe.src = url;
    }, inheritedPreview.signedPreviewUrl);
    expect((await revokedPreviewResponse).status()).toBe(404);

    const revokedPageResponse = await inheritedPreview.page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    expect(revokedPageResponse?.status()).toBe(404);
  } finally {
    await inheritedPreview.context.close();
  }
});
