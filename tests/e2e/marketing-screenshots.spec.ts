import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';

type Demo = {
  email: string;
  workspaceUid: string;
  toolUid: string;
  guideUid: string;
  releaseUid: string;
  password: string;
};
const output = 'storage/framework/testing/marketing-capture';
const mode = process.env.E2E_MARKETING_CAPTURE;
test.use({
  trace: 'off',
  video: 'off',
  screenshot: 'off',
  viewport: { width: 1600, height: 1000 },
});

function prepareDemo(): Demo {
  if (process.env.E2E_APP_COMMAND_TARGET !== 'run-e2e-app-cmd') {
    throw new Error('Marketing captures require the isolated make e2e wrapper.');
  }
  const suffix = randomUUID().replaceAll('-', '');
  const result = execFileSync(
    'make',
    ['run-e2e-app-cmd', `APP_CMD=php tests/e2e/support/seed-marketing-demo.php ${suffix}`],
    { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
  );
  mkdirSync(output, { recursive: true });
  return { ...JSON.parse(result), password: `af${suffix}` } as Demo;
}

async function capture(page: Page, name: string): Promise<void> {
  await page.locator('body').click({ position: { x: 1500, y: 20 } });
  await page.evaluate(async () => {
    await document.fonts.ready;
  });
  await page.screenshot({ path: `${output}/${name}.png`, animations: 'disabled' });
}

test('marketing screenshots show a fictional team using the real application', async ({ page }) => {
  test.skip(
    mode !== 'automatic',
    'Opt-in capture; ordinary quality runs do not overwrite product screenshots.',
  );
  test.setTimeout(120_000);
  const demo = prepareDemo();
  await page.goto('/login');
  await page.getByLabel('Email').fill(demo.email);
  await page.getByLabel('Password', { exact: true }).fill(demo.password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/dashboard$/u);
  await page.getByRole('button', { name: 'Dark theme', exact: true }).click();
  await page.goto(`/dashboard?workspace_uid=${demo.workspaceUid}`);
  await expect(page.getByRole('heading', { name: 'Engineering', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Continue where you left off' })).toBeVisible();
  await capture(page, 'app-dashboard');
  await page.goto(`/pages?workspace_uid=${demo.workspaceUid}`);
  const plannerCard = page.getByRole('link').filter({
    has: page.getByRole('heading', { name: 'Sprint capacity planner', exact: true }),
  });
  await expect(plannerCard).toBeVisible();
  await expect(plannerCard).toHaveAttribute('href', new RegExp(`/pages/${demo.toolUid}$`, 'u'));
  await capture(page, 'app-library');
  await page.getByRole('button', { name: 'Search or jump to', exact: true }).click();
  await page.getByRole('searchbox', { name: 'Find a page' }).fill('release');
  await expect(
    page.getByRole('dialog').getByRole('link', { name: /Release checklist/u }),
  ).toBeVisible();
  await page.screenshot({ path: `${output}/app-quick-navigation.png`, animations: 'disabled' });
  await page.keyboard.press('Escape');
  await page.goto(`/pages/${demo.toolUid}`);
  const artifact = page.frameLocator('iframe[title="Artifact preview"]');
  await expect(artifact.getByRole('heading', { name: 'Sprint capacity planner' })).toBeVisible();
  await expect(artifact.locator('#capacity')).toHaveText('39');
  await capture(page, 'app-artifact-live');
  await page.goto(`/pages/${demo.guideUid}`);
  await expect(page.locator('[data-mermaid-diagram] svg')).toBeVisible();
  await capture(page, 'app-markdown');
});

test('prepare isolated marketing demo for manual browser capture', async ({ request }) => {
  test.skip(mode !== 'manual', 'Opt-in environment for the provided browser tool.');
  test.setTimeout(900_000);
  const completePath = `${output}/complete`;
  if (existsSync(completePath))
    throw new Error(
      'Remove the previous capture completion marker before starting another session.',
    );
  const demo = prepareDemo();
  writeFileSync(`${output}/demo.json`, JSON.stringify(demo), { mode: 0o600 });
  expect((await request.get('/login')).ok()).toBe(true);
  // The wrapper keeps its disposable database/services alive until capture is done.
  // This is fixture preparation, not a replacement for the UI/security test suite.
  await expect
    .poll(() => existsSync(completePath), { timeout: 840_000, intervals: [1000] })
    .toBe(true);
});
