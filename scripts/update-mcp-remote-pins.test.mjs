import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import {
  copyFileSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const root = fileURLToPath(new URL('../', import.meta.url));
const targets = [
  'scripts/connect-mcp.sh',
  'scripts/verify-mcp-remote.mjs',
  'scripts/smoke-mcp-remote.mjs',
  'tests/Feature/Console/ConnectMcpNodeRuntimeGuardTest.php',
  'tests/Feature/Console/ConnectMcpLoopbackGuardTest.php',
];

function fixture() {
  const directory = mkdtempSync(join(tmpdir(), 'artifactflow-reviewed-pins-'));
  const files = [
    ...targets,
    'scripts/mcp-remote-bridge/package.json',
    'scripts/mcp-remote-bridge/package-lock.json',
  ];
  for (const file of files) {
    mkdirSync(dirname(join(directory, file)), { recursive: true });
    copyFileSync(join(root, file), join(directory, file));
  }
  if (existsSync(join(root, 'scripts/update-mcp-remote-pins.mjs'))) {
    copyFileSync(
      join(root, 'scripts/update-mcp-remote-pins.mjs'),
      join(directory, 'scripts/update-mcp-remote-pins.mjs'),
    );
  }
  const manifestPath = join(directory, 'scripts/mcp-remote-bridge/package.json');
  const lockPath = join(directory, 'scripts/mcp-remote-bridge/package-lock.json');
  const manifest = JSON.parse(readFileSync(manifestPath));
  const lock = JSON.parse(readFileSync(lockPath));
  const version = '9.8.6';
  const integrity = `sha512-${Buffer.alloc(64, 42).toString('base64')}`;
  manifest.dependencies['mcp-remote'] = version;
  lock.packages[''].dependencies['mcp-remote'] = version;
  Object.assign(lock.packages['node_modules/mcp-remote'], {
    version,
    integrity,
    resolved: `https://registry.npmjs.org/mcp-remote/-/mcp-remote-${version}.tgz`,
  });
  writeFileSync(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`);
  writeFileSync(lockPath, `${JSON.stringify(lock, null, 2)}\n`);
  const hash = createHash('sha256').update(readFileSync(lockPath)).digest('hex');
  const args = [
    '--reviewed-version',
    version,
    '--reviewed-lock-sha256',
    hash,
    '--reviewed-integrity',
    integrity,
  ];
  const run = (options = args) =>
    spawnSync(
      process.execPath,
      [join(directory, 'scripts/update-mcp-remote-pins.mjs'), ...options],
      { encoding: 'utf8' },
    );
  const snapshot = () => targets.map((file) => readFileSync(join(directory, file), 'utf8'));
  return { directory, run, args, snapshot, lockPath, hash };
}

test('an explicitly reviewed update synchronizes every pin and passes the independent verifier', () => {
  const { directory, run, hash } = fixture();
  const result = run();
  assert.equal(result.status, 0, result.stderr);
  for (const file of targets)
    assert.ok(readFileSync(join(directory, file), 'utf8').includes('9.8.6'), file);
  assert.ok(readFileSync(join(directory, targets[0]), 'utf8').includes(hash));
  const verified = spawnSync(process.execPath, [join(directory, 'scripts/verify-mcp-remote.mjs')], {
    encoding: 'utf8',
  });
  assert.equal(verified.status, 0, verified.stderr);
  assert.equal(run().status, 0, 'Repeating the same reviewed update is harmless.');
});

for (const [field, wrongValue] of [
  ['--reviewed-version', '9.8.7'],
  ['--reviewed-lock-sha256', '0'.repeat(64)],
  ['--reviewed-integrity', `sha512-${Buffer.alloc(64, 43).toString('base64')}`],
]) {
  test(`rejects a mismatched ${field} before changing any pin`, () => {
    const { run, args, snapshot } = fixture();
    const before = snapshot();
    args[args.indexOf(field) + 1] = wrongValue;
    const result = run(args);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /reviewed/i);
    assert.deepEqual(snapshot(), before);
  });
}

test('requires explicit review values and never accepts changed bytes using stale approval', () => {
  const { run, snapshot, lockPath } = fixture();
  const before = snapshot();
  assert.notEqual(run([]).status, 0);
  writeFileSync(lockPath, `${readFileSync(lockPath, 'utf8')}\n`);
  assert.notEqual(run().status, 0);
  assert.deepEqual(snapshot(), before);
});

test('unexpected source drift fails before changing any other pin', () => {
  const { directory, run, snapshot } = fixture();
  const path = join(directory, 'scripts/smoke-mcp-remote.mjs');
  writeFileSync(
    path,
    readFileSync(path, 'utf8').replace('const BRIDGE_VERSION', 'const RENAMED_VERSION'),
  );
  const before = snapshot();
  const result = run();
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /BRIDGE_VERSION/);
  assert.deepEqual(snapshot(), before);
});

for (const [label, change, message] of [
  [
    'a non-registry dependency',
    (lock) => {
      lock.packages['node_modules/qs'].resolved = 'https://example.invalid/qs.tgz';
    },
    /registry/,
  ],
  [
    'a dependency without integrity',
    (lock) => {
      delete lock.packages['node_modules/express'].integrity;
    },
    /integrity/,
  ],
  [
    'a changed qs override',
    (lock) => {
      lock.packages['node_modules/qs'].version = '99.0.0';
    },
    /qs override/,
  ],
  [
    'a changed Node floor',
    (lock) => {
      lock.packages[''].engines.node = '>=99.0.0';
    },
    /Node floor/,
  ],
]) {
  test(`refuses ${label} even with an explicit lock fingerprint`, () => {
    const { run, args, snapshot, lockPath } = fixture();
    const before = snapshot();
    const lock = JSON.parse(readFileSync(lockPath));
    change(lock);
    writeFileSync(lockPath, `${JSON.stringify(lock, null, 2)}\n`);
    args[args.indexOf('--reviewed-lock-sha256') + 1] = createHash('sha256')
      .update(readFileSync(lockPath))
      .digest('hex');
    const result = run(args);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, message);
    assert.deepEqual(snapshot(), before);
  });
}
