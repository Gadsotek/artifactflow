import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';

// Run only after reviewing the upstream delta and independently checking the
// published tarball. Explicit values bind that review to these exact bytes.
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const read = (path) => readFileSync(resolve(root, path), 'utf8');
const requireCondition = (condition, message) => {
  if (!condition) throw new Error(message);
};

function update() {
  const { values } = parseArgs({
    options: {
      'reviewed-version': { type: 'string' },
      'reviewed-lock-sha256': { type: 'string' },
      'reviewed-integrity': { type: 'string' },
    },
  });
  const version = values['reviewed-version'];
  const hash = values['reviewed-lock-sha256'];
  const integrity = values['reviewed-integrity'];
  requireCondition(
    /^\d+\.\d+\.\d+$/u.test(version ?? ''),
    'Pass --reviewed-version with the exact reviewed stable version.',
  );
  requireCondition(
    /^[a-f0-9]{64}$/u.test(hash ?? ''),
    'Pass --reviewed-lock-sha256 with the reviewed lock fingerprint.',
  );
  requireCondition(
    /^sha512-[A-Za-z0-9+/]{86}==$/u.test(integrity ?? ''),
    'Pass --reviewed-integrity with the independently verified SHA-512 tarball integrity.',
  );

  const manifest = JSON.parse(read('scripts/mcp-remote-bridge/package.json'));
  const lockBytes = readFileSync(resolve(root, 'scripts/mcp-remote-bridge/package-lock.json'));
  const lock = JSON.parse(lockBytes.toString('utf8'));
  const bridge = lock.packages?.['node_modules/mcp-remote'];
  requireCondition(
    createHash('sha256').update(lockBytes).digest('hex') === hash,
    'The lock bytes differ from --reviewed-lock-sha256.',
  );
  requireCondition(
    manifest.dependencies?.['mcp-remote'] === version &&
      lock.packages?.['']?.dependencies?.['mcp-remote'] === version &&
      bridge?.version === version,
    'Manifest and lock must match --reviewed-version.',
  );
  requireCondition(
    bridge?.integrity === integrity,
    'The tarball differs from --reviewed-integrity.',
  );
  requireCondition(
    bridge?.resolved === `https://registry.npmjs.org/mcp-remote/-/mcp-remote-${version}.tgz`,
    'The reviewed bridge must resolve from the exact HTTPS npm tarball.',
  );

  const verifierPath = 'scripts/verify-mcp-remote.mjs';
  const verifier = read(verifierPath);
  const capture = (source, pattern, label) => {
    const matches = [...source.matchAll(pattern)];
    requireCondition(matches.length === 1, `Expected one ${label}; review source drift manually.`);
    return matches[0][1];
  };
  const oldVersion = capture(
    verifier,
    /^const EXPECTED_VERSION = '([^']+)';$/gmu,
    'EXPECTED_VERSION',
  );
  const oldHash = capture(
    verifier,
    /^const EXPECTED_LOCK_SHA256 = '([^']+)';$/gmu,
    'EXPECTED_LOCK_SHA256',
  );
  const oldIntegrity = capture(
    verifier,
    /^const EXPECTED_INTEGRITY =\s*'([^']+)';$/gmu,
    'EXPECTED_INTEGRITY',
  );
  const nodeRange = capture(
    verifier,
    /^const EXPECTED_NODE_RANGE = '([^']+)';$/gmu,
    'EXPECTED_NODE_RANGE',
  );
  const qsVersion = capture(
    verifier,
    /^const EXPECTED_QS_VERSION = '([^']+)';$/gmu,
    'EXPECTED_QS_VERSION',
  );
  const qsIntegrity = capture(
    verifier,
    /^const EXPECTED_QS_INTEGRITY =\s*'([^']+)';$/gmu,
    'EXPECTED_QS_INTEGRITY',
  );
  requireCondition(
    manifest.engines?.node === nodeRange && lock.packages?.['']?.engines?.node === nodeRange,
    'Node floor changes require a separate review and fixture update.',
  );
  requireCondition(
    manifest.overrides?.qs === qsVersion &&
      lock.packages?.['node_modules/qs']?.version === qsVersion &&
      lock.packages?.['node_modules/qs']?.integrity === qsIntegrity,
    'The qs override changed; review it separately.',
  );
  requireCondition(lock.lockfileVersion === 3, 'The reviewed lock must use lockfileVersion 3.');
  for (const [name, entry] of Object.entries(lock.packages ?? {})) {
    if (name === '') continue;
    requireCondition(
      typeof entry.resolved === 'string' &&
        entry.resolved.startsWith('https://registry.npmjs.org/') &&
        /^sha512-[A-Za-z0-9+/]{86}==$/u.test(entry.integrity ?? ''),
      `Unreviewed registry source or integrity for ${name}.`,
    );
  }

  const connector = read('scripts/connect-mcp.sh');
  requireCondition(
    connector.includes(`MCP_REMOTE_VERSION="${oldVersion}"`) &&
      connector.includes(`MCP_REMOTE_LOCK_SHA256="${oldHash}"`),
    'Connector pins have drifted from the verifier.',
  );
  const smoke = read('scripts/smoke-mcp-remote.mjs');
  requireCondition(
    smoke.includes(`const BRIDGE_VERSION = '${oldVersion}';`),
    'BRIDGE_VERSION has drifted from the verifier.',
  );
  const paths = [
    verifierPath,
    'scripts/connect-mcp.sh',
    'scripts/smoke-mcp-remote.mjs',
    'tests/Feature/Console/ConnectMcpNodeRuntimeGuardTest.php',
    'tests/Feature/Console/ConnectMcpLoopbackGuardTest.php',
  ];
  // Validate all target contents before writing any file. An unfamiliar source
  // layout needs a deliberate edit, not a partially synchronized upgrade.
  const changes = paths.map((path) => {
    const source = read(path);
    requireCondition(source.includes(oldVersion), `Missing reviewed version in ${path}.`);
    if (path.endsWith('ConnectMcpNodeRuntimeGuardTest.php')) {
      requireCondition(
        source.includes(oldHash) && source.includes(oldIntegrity),
        `Regression pins have drifted in ${path}.`,
      );
    }
    return [
      path,
      source
        .replaceAll(oldVersion, version)
        .replaceAll(oldHash, hash)
        .replaceAll(oldIntegrity, integrity),
    ];
  });
  for (const [path, content] of changes) writeFileSync(resolve(root, path), content);
  console.log(
    `Updated reviewed mcp-remote@${version} pins in ${changes.length} files. Run the verifier, connector tests, and installed smoke; document the upstream review.`,
  );
}

try {
  update();
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
