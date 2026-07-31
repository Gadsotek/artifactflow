import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

type ArtifactParserCase = {
  index: number;
  id: string;
  payload: string;
  hardened: string;
};

type ArtifactParserCorpus = {
  seed: number;
  requestedCases: number;
  cases: ArtifactParserCase[];
};

const fallbackSeed = 1_831_565_813;
const defaultCaseCount = 128;
const maximumCaseCount = 512;
const repoRoot = fileURLToPath(new URL('../..', import.meta.url));
const appCommandTarget = process.env.E2E_APP_COMMAND_TARGET ?? 'run-e2e-app-cmd';

function unsignedSeed(value: string): number {
  if (!/^(?:0x[0-9a-f]+|[0-9]+)$/iu.test(value)) {
    throw new Error('ARTIFACT_PARSER_FUZZ_SEED must be an unsigned integer.');
  }

  const seed = Number(BigInt.asUintN(32, BigInt(value)));

  return seed === 0 ? fallbackSeed : seed;
}

function corpusSeed(): number {
  const explicitSeed = process.env.ARTIFACT_PARSER_FUZZ_SEED?.trim();

  if (explicitSeed) {
    return unsignedSeed(explicitSeed);
  }

  const commit = process.env.GITHUB_SHA?.trim();

  if (commit && /^[0-9a-f]{8,}$/iu.test(commit)) {
    return unsignedSeed(`0x${commit.slice(0, 8)}`);
  }

  return fallbackSeed;
}

function corpusCaseCount(): number {
  const configuredCount = process.env.ARTIFACT_PARSER_FUZZ_CASES?.trim();

  if (!configuredCount) {
    return defaultCaseCount;
  }

  if (!/^[1-9][0-9]*$/u.test(configuredCount)) {
    throw new Error('ARTIFACT_PARSER_FUZZ_CASES must be a positive integer.');
  }

  const count = Number(configuredCount);

  if (!Number.isSafeInteger(count) || count > maximumCaseCount) {
    throw new Error(`ARTIFACT_PARSER_FUZZ_CASES must not exceed ${maximumCaseCount}.`);
  }

  return count;
}

function generateCorpus(seed: number, caseCount: number): ArtifactParserCorpus {
  if (!['run-e2e-app-cmd', 'run-app-cmd'].includes(appCommandTarget)) {
    throw new Error('Unsupported e2e app command target.');
  }

  const command =
    'php tests/e2e/support/artifact-parser-differential-corpus.php' +
    ` --seed=${seed} --cases=${caseCount}`;
  const output = execFileSync(
    'make',
    ['--no-print-directory', appCommandTarget, `APP_CMD=${command}`],
    {
      cwd: repoRoot,
      encoding: 'utf8',
      maxBuffer: 16 * 1024 * 1024,
    },
  );

  return JSON.parse(output) as ArtifactParserCorpus;
}

test('artifact parser differential fuzz corpus @artifact-security', async ({ page }) => {
  test.setTimeout(120_000);

  const seed = corpusSeed();
  const requestedCases = corpusCaseCount();
  const corpus = generateCorpus(seed, requestedCases);

  expect(corpus.seed).toBe(seed);
  expect(corpus.requestedCases).toBe(requestedCases);
  expect(corpus.cases).toHaveLength(requestedCases);
  expect(new Set(corpus.cases.map(({ id }) => id)).size).toBe(requestedCases);

  let casesWithLiveRawFrames = 0;

  for (const parserCase of corpus.cases) {
    await page.setContent(parserCase.payload, { waitUntil: 'domcontentloaded' });
    const rawFrames = await page.evaluate(() => window.frames.length);

    if (rawFrames > 0) {
      casesWithLiveRawFrames += 1;
    }

    await page.setContent(parserCase.hardened, { waitUntil: 'domcontentloaded' });
    const hardenedFrames = await page.evaluate(() => window.frames.length);
    const reproduction =
      `Parser differential ${parserCase.id} (index ${parserCase.index}) left ` +
      `${hardenedFrames} nested frame(s); raw=${rawFrames}, seed=${seed}, ` +
      `cases=${requestedCases}. Reproduce with ARTIFACT_PARSER_FUZZ_SEED=${seed} ` +
      `ARTIFACT_PARSER_FUZZ_CASES=${requestedCases} ` +
      `E2E_GREP='artifact parser differential fuzz corpus' make e2e.\n` +
      `Payload: ${JSON.stringify(parserCase.payload)}`;

    expect(hardenedFrames, reproduction).toBe(0);
  }

  expect(
    casesWithLiveRawFrames,
    `Seed ${seed} did not exercise enough browser-live nested contexts.`,
  ).toBeGreaterThanOrEqual(Math.min(12, requestedCases));
});
