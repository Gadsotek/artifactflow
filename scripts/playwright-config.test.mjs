import assert from 'node:assert/strict';
import { test } from 'node:test';

for (const ci of [false, true]) {
  test(`HTML reports allow the runner to exit and clean up (${ci ? 'CI' : 'local'})`, async () => {
    const previousCi = process.env.CI;

    try {
      if (ci) process.env.CI = 'true';
      else delete process.env.CI;

      const { default: config } = await import(
        new URL(`../playwright.config.ts?ci=${ci}`, import.meta.url)
      );
      const htmlReporter = config.reporter.find(([name]) => name === 'html');

      assert.ok(htmlReporter, 'Keep the HTML report available for manual inspection.');
      assert.equal(
        htmlReporter[1]?.open,
        'never',
        'Report serving must not hold test cleanup open.',
      );
    } finally {
      if (previousCi === undefined) delete process.env.CI;
      else process.env.CI = previousCi;
    }
  });
}
