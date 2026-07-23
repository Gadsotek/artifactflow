import { defineConfig, devices } from '@playwright/test';

const requestedTestGrep = process.env.E2E_GREP ? new RegExp(process.env.E2E_GREP, 'u') : undefined;
// Every test runs on Chromium. Tests whose title carries @artifact-security
// additionally run on Firefox and WebKit; see tests/e2e/README.md.
const excludeNonArtifactSecurityTests = /^(?!.*@artifact-security)/u;

export default defineConfig({
  testDir: './tests/e2e',
  grep: requestedTestGrep,
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
    {
      name: 'firefox',
      grepInvert: excludeNonArtifactSecurityTests,
      use: { ...devices['Desktop Firefox'] },
    },
    {
      name: 'webkit',
      grepInvert: excludeNonArtifactSecurityTests,
      use: { ...devices['Desktop Safari'] },
    },
  ],
});
