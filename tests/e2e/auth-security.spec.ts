import { expect, test, type Locator } from '@playwright/test';
import { readFileSync } from 'node:fs';

type ManifestEntry = {
  file: string;
};

const manifest = JSON.parse(
  readFileSync(new URL('../../public/build/manifest.json', import.meta.url), 'utf8'),
) as Record<string, ManifestEntry>;
const baseUrl = (process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:18180').replace(/\/$/u, '');
const appAsset = `${baseUrl}/build/${manifest['resources/js/app.js'].file}`;
const cssAsset = `${baseUrl}/build/${manifest['resources/css/app.css'].file}`;

async function contrastRatio(locator: Locator): Promise<number> {
  return locator.evaluate((element) => {
    const rgb = (value: string): [number, number, number] => {
      const channels = value
        .match(/[\d.]+/gu)
        ?.slice(0, 3)
        .map(Number);
      if (channels?.length !== 3) {
        throw new Error(`Expected an RGB color, received ${value}`);
      }

      return [channels[0], channels[1], channels[2]];
    };
    const luminance = (color: [number, number, number]): number => {
      const channels = color.map((channel) => {
        const normalized = channel / 255;

        return normalized <= 0.04045 ? normalized / 12.92 : ((normalized + 0.055) / 1.055) ** 2.4;
      });

      return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
    };
    const foreground = luminance(rgb(getComputedStyle(element).color));
    const background = luminance(rgb(getComputedStyle(document.body).backgroundColor));
    const lighter = Math.max(foreground, background);
    const darker = Math.min(foreground, background);

    return (lighter + 0.05) / (darker + 0.05);
  });
}

test.use({ screenshot: 'off', trace: 'off', video: 'off' });

test('two-factor recovery login is an explicit alternate mode', async ({ page }) => {
  await page.goto(`${baseUrl}/up`, { waitUntil: 'domcontentloaded' });
  await page.setContent(`
    <!doctype html>
    <html class="dark" data-theme="dark">
      <head><link rel="stylesheet" href="${cssAsset}"></head>
      <body style="background: var(--af-surface-solid); color: var(--af-text)">
        <form data-two-factor-challenge>
          <div data-two-factor-authenticator-panel>
            <label for="code">Authentication code</label>
            <input id="code" data-two-factor-authenticator-input required>
          </div>
          <div data-two-factor-recovery-panel hidden id="two-factor-recovery-panel">
            <label for="recovery_code">Recovery code</label>
            <input id="recovery_code" data-two-factor-recovery-input disabled>
          </div>
          <button class="af-auth-mode-toggle" type="button" data-two-factor-mode-toggle aria-controls="two-factor-recovery-panel" aria-expanded="false">Use a recovery code</button>
          <label data-two-factor-remember-device><input type="checkbox">Remember this device</label>
        </form>
        <script type="module" src="${appAsset}"></script>
      </body>
    </html>
  `);

  const authenticatorPanel = page.locator('[data-two-factor-authenticator-panel]');
  const authenticatorInput = page.locator('[data-two-factor-authenticator-input]');
  const recoveryPanel = page.locator('[data-two-factor-recovery-panel]');
  const recoveryInput = page.locator('[data-two-factor-recovery-input]');
  const rememberDevice = page.locator('[data-two-factor-remember-device]');
  const toggle = page.locator('[data-two-factor-mode-toggle]');

  await expect(toggle).toHaveCSS('color', 'rgb(176, 175, 186)');
  expect(await contrastRatio(toggle)).toBeGreaterThanOrEqual(4.5);
  await expect(authenticatorPanel).toBeVisible();
  await expect(recoveryPanel).toBeHidden();
  await expect(recoveryInput).toBeDisabled();
  await toggle.click();
  await expect(authenticatorPanel).toBeHidden();
  await expect(authenticatorInput).toBeDisabled();
  await expect(recoveryPanel).toBeVisible();
  await expect(recoveryInput).toBeEnabled();
  await expect(recoveryInput).toBeFocused();
  await expect(rememberDevice).toBeHidden();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(toggle).toHaveText('Use an authenticator code');
  await expect(toggle).toHaveCSS('color', 'rgb(139, 131, 255)');
  expect(await contrastRatio(toggle)).toBeGreaterThanOrEqual(4.5);

  await toggle.hover();
  await page.mouse.down();
  await expect(toggle).toHaveCSS('color', 'rgb(163, 157, 255)');
  expect(await contrastRatio(toggle)).toBeGreaterThanOrEqual(4.5);
  await page.mouse.up();
});

test('two-factor enrollment countdown sends an expired setup to password confirmation', async ({
  page,
}) => {
  const deadline = Math.floor(Date.now() / 1000) + 2;
  const expiredUrl = `${baseUrl}/up?two-factor-enrollment-expired=1`;

  await page.goto(`${baseUrl}/up`, { waitUntil: 'domcontentloaded' });
  await page.setContent(`
    <!doctype html>
    <html data-theme="light">
      <body>
        <section
          data-two-factor-enrollment-timer
          data-two-factor-enrollment-deadline="${deadline}"
          data-two-factor-enrollment-expired-url="${expiredUrl}"
        >
          <strong data-two-factor-enrollment-remaining>0:02</strong>
          <p data-two-factor-enrollment-expiry-message>Setup stays here if this expires.</p>
        </section>
        <script type="module" src="${appAsset}"></script>
      </body>
    </html>
  `);

  const timer = page.locator('[data-two-factor-enrollment-timer]');
  await expect(timer.locator('[data-two-factor-enrollment-remaining]')).toHaveText(/^0:0[0-2]$/u);
  await page.waitForURL(expiredUrl, { timeout: 5_000 });
});
