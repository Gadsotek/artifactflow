import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  grep: process.env.E2E_GREP ? new RegExp(process.env.E2E_GREP, 'u') : undefined,
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: process.env.CI ? [['html'], ['github']] : [['list'], ['html']],
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:18180',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    // Cross-engine Firefox and WebKit coverage is planned but deferred: the
    // existing suite is flaky on those engines (pervasive `networkidle` waits
    // trip WebKit; sub-pixel layout trips a tight Firefox assertion). Tracked
    // separately so the sandbox specs can be hardened and scoped for it.
  ],
});
