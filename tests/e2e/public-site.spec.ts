import { expect, test, type Locator } from '@playwright/test';
import path from 'node:path';

test.beforeEach(async ({ page }) => {
  // Serve the actual public files in-browser without a deployment or app database.
  await page.route('http://public-site.test/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    const files: Record<string, string> = {
      '/': 'site/index.html',
      '/security/': 'site/security/index.html',
      '/workflow/': 'site/workflow/index.html',
      '/guides/ai-artifact-storage/': 'site/guides/ai-artifact-storage/index.html',
      '/assets/site.css': 'site/assets/site.css',
      '/assets/home.css': 'site/assets/home.css',
      '/assets/workflow.css': 'site/assets/workflow.css',
      '/assets/theme.js': 'site/assets/theme.js',
    };
    const file = files[pathname];

    if (file) {
      await route.fulfill({ path: path.resolve(file) });
    } else {
      await route.fulfill({ status: 204, body: '' });
    }
  });
});

async function expectCenteredThumb(toggle: Locator, dark: boolean): Promise<void> {
  const offset = await toggle.evaluate((button, isDark) => {
    const thumb = getComputedStyle(button, '::after');
    const buttonStyle = getComputedStyle(button);
    const bounds = button.getBoundingClientRect();
    const icon = button.querySelectorAll('svg')[isDark ? 1 : 0].getBoundingClientRect();
    const transform = new DOMMatrixReadOnly(thumb.transform);
    const centerX =
      bounds.left +
      parseFloat(buttonStyle.borderLeftWidth) +
      parseFloat(thumb.left) +
      parseFloat(thumb.width) / 2 +
      transform.m41;
    const centerY =
      bounds.top +
      parseFloat(buttonStyle.borderTopWidth) +
      parseFloat(thumb.top) +
      parseFloat(thumb.height) / 2 +
      transform.m42;

    return {
      x: Math.abs(centerX - icon.left - icon.width / 2),
      y: Math.abs(centerY - icon.top - icon.height / 2),
      edge: isDark
        ? bounds.right - centerX - parseFloat(thumb.width) / 2
        : centerX - parseFloat(thumb.width) / 2 - bounds.left,
      top: centerY - parseFloat(thumb.height) / 2 - bounds.top,
      bottom: bounds.bottom - centerY - parseFloat(thumb.height) / 2,
    };
  }, dark);

  expect(offset.x, 'The selected icon and thumb should share a horizontal center').toBeLessThan(
    0.75,
  );
  expect(offset.y, 'The selected icon and thumb should share a vertical center').toBeLessThan(0.75);
  expect(
    offset.edge,
    'The thumb needs breathing room at the end of the pill',
  ).toBeGreaterThanOrEqual(5);
  expect(Math.abs(offset.edge - offset.top), 'End and top insets should match').toBeLessThan(0.75);
  expect(Math.abs(offset.edge - offset.bottom), 'End and bottom insets should match').toBeLessThan(
    0.75,
  );
}

for (const width of [1280, 390]) {
  for (const colorScheme of ['light', 'dark'] as const) {
    test.describe(`public website at ${width}px with system ${colorScheme}`, () => {
      test.use({
        viewport: { width, height: 844 },
        colorScheme,
        contextOptions: { reducedMotion: 'reduce' },
      });

      test('theme switch stays centered when toggled and reloaded', async ({ page }) => {
        await page.goto('http://public-site.test/');
        expect(
          await page.evaluate(() => window.matchMedia('(prefers-reduced-motion: reduce)').matches),
          'Geometry checks require reduced motion to be active in the browser context',
        ).toBe(true);
        const toggle = page.getByRole('button', { name: /Switch to .* theme/ });
        expect(
          await toggle.evaluate((button) => getComputedStyle(button, '::after').transitionDuration),
          'The thumb should move immediately when reduced motion is requested',
        ).toBe('0s');
        const initiallyDark = colorScheme === 'dark';
        await expect(toggle).toHaveAttribute('aria-pressed', String(initiallyDark));
        await expectCenteredThumb(toggle, initiallyDark);

        await toggle.focus();
        await page.keyboard.press('Space');
        await expect(toggle).toBeFocused();
        await expect(toggle).toHaveAttribute('aria-pressed', String(!initiallyDark));
        await expectCenteredThumb(toggle, !initiallyDark);
        await expect(page.locator('[style]')).toHaveCount(0);

        await page.reload();
        await expect(toggle).toHaveAttribute('aria-pressed', String(!initiallyDark));
        await expectCenteredThumb(toggle, !initiallyDark);
      });

      test('article card groups leave room for the following content', async ({ page }) => {
        for (const pathname of ['/security/', '/guides/ai-artifact-storage/']) {
          await page.goto(`http://public-site.test${pathname}`);
          const groups = page.locator('.split-cards:has(+ p), .detail-list:has(+ .page-links)');
          expect(await groups.count()).toBeGreaterThan(0);

          for (const group of await groups.all()) {
            const gap = await group.evaluate((element) => {
              const next = element.nextElementSibling!;
              return next.getBoundingClientRect().top - element.getBoundingClientRect().bottom;
            });
            expect(
              gap,
              `${pathname} should separate card groups from the next block`,
            ).toBeGreaterThanOrEqual(24);
          }
        }
      });

      test('workflow remains readable without horizontal overflow', async ({ page }) => {
        await page.goto('http://public-site.test/workflow/');
        await expect(page.locator('.journey-steps > li')).toHaveCount(3);
        await expect(
          page.getByRole('heading', { name: 'Architecture notes', exact: true }),
        ).toBeVisible();
        const dimensions = await page.evaluate(() => ({
          content: document.documentElement.scrollWidth,
          viewport: document.documentElement.clientWidth,
        }));
        expect(dimensions.content).toBeLessThanOrEqual(dimensions.viewport);
        await expect(page.locator('[style]')).toHaveCount(0);
      });
    });
  }
}
