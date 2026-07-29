import { expect, test } from '@playwright/test';

const turnstileOrigin = 'https://challenges.cloudflare.com';
const turnstileScriptUrl = `${turnstileOrigin}/turnstile/v0/api.js`;
const turnstileTestSiteKey = '1x00000000000000000000AA';
const turnstileAppPort = process.env.E2E_TURNSTILE_APP_PORT ?? '18182';
const turnstileAppUrl = `http://localhost:${turnstileAppPort}`;

test.use({ screenshot: 'off', trace: 'off', video: 'off' });

test('Turnstile widgets render on the real authentication pages under their CSP', async ({
  page,
}) => {
  test.setTimeout(60_000);

  const diagnostics: string[] = [];
  page.on('console', (message) => {
    if (['error', 'warning'].includes(message.type())) {
      diagnostics.push(`console ${message.type()}: ${message.text()}`);
    }
  });
  page.on('pageerror', (error) => {
    diagnostics.push(`page error: ${error.message}`);
  });
  page.on('requestfailed', (request) => {
    diagnostics.push(
      `request failed: ${request.url()} (${request.failure()?.errorText ?? 'unknown'})`,
    );
  });
  page.on('response', (response) => {
    if (response.url().startsWith(turnstileOrigin)) {
      diagnostics.push(`Turnstile response: ${response.status()} ${response.url()}`);
    }
  });

  const pages = [
    { action: 'login', path: '/login' },
    { action: 'password_reset_request', path: '/forgot-password' },
    {
      action: 'password_reset',
      path: '/reset-password/e2e-token?email=user%40example.test',
    },
  ];

  for (const authPage of pages) {
    const response = await page.goto(`${turnstileAppUrl}${authPage.path}`, {
      waitUntil: 'domcontentloaded',
    });
    const csp = response?.headers()['content-security-policy'] ?? '';
    const cspNonce = /'nonce-([^']+)'/u.exec(csp)?.[1] ?? '';
    const script = page.locator(`script[src="${turnstileScriptUrl}"]`);
    const browserNonce = await script.evaluate((element: HTMLScriptElement) => element.nonce);
    const widget = page.locator(`.cf-turnstile[data-action="${authPage.action}"]`);

    expect(response?.status()).toBe(200);
    expect(cspNonce).not.toBe('');
    expect(browserNonce).toBe(cspNonce);
    expect(csp).toContain(`'nonce-${browserNonce}'`);
    expect(csp).toMatch(
      new RegExp(`script-src [^;]*${turnstileOrigin.replaceAll('.', '\\.')}`, 'u'),
    );
    expect(csp).toMatch(
      new RegExp(`frame-src [^;]*${turnstileOrigin.replaceAll('.', '\\.')}`, 'u'),
    );
    await expect(widget).toHaveAttribute('data-sitekey', turnstileTestSiteKey);
    await expect(widget).toHaveAttribute('data-size', 'flexible');

    try {
      await expect
        .poll(() => page.frames().some((frame) => frame.url().startsWith(turnstileOrigin)), {
          timeout: 20_000,
        })
        .toBe(true);
      await expect(page.locator('input[name="cf-turnstile-response"]')).toHaveValue(/.+/u, {
        timeout: 20_000,
      });
    } catch (error) {
      throw new Error(
        `Turnstile did not render on ${authPage.path} under the application CSP.\n${diagnostics.join('\n')}`,
        { cause: error },
      );
    }
  }
});
