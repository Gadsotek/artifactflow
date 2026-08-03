import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const EXPECTED_VERSION = '0.1.38';
const EXPECTED_NODE_RANGE = '>=20.18.1';
const EXPECTED_LOCK_SHA256 = '994897fb2252976d5b29427609224b4453f3ca60ef75c3aa0ab2fc6f6c0dd9e2';
const MINIMUM_NODE_VERSION = [20, 18, 1];
const EXPECTED_INTEGRITY =
  'sha512-w+JU4U3CfG29TawXR4JLNQ9d1Un5nT8AGI65f/juCaqUdF/V6fS7wE4o7xNPbB8X58o46hRXEJgYglQMAKQs4w==';
const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const bridgeDirectory = resolve(repositoryRoot, 'scripts/mcp-remote-bridge');
const lockPath = resolve(bridgeDirectory, 'package-lock.json');

function readJson(path) {
  try {
    return JSON.parse(readFileSync(path, 'utf8'));
  } catch (error) {
    console.error(`Could not read valid JSON from ${path}: ${error.message}`);
    process.exit(1);
  }
}

function requireCondition(condition, message) {
  if (!condition) {
    console.error(message);
    process.exit(1);
  }
}

const manifest = readJson(resolve(bridgeDirectory, 'package.json'));
const lockBytes = readFileSync(lockPath);
const lock = readJson(lockPath);
const connectorSource = readFileSync(resolve(repositoryRoot, 'scripts/connect-mcp.sh'), 'utf8');
const lockedRoot = lock.packages?.[''];
const lockedBridge = lock.packages?.['node_modules/mcp-remote'];

const nodeVersion = process.versions.node.split('.').map((part) => Number.parseInt(part, 10));
let nodeIsSupported = true;
for (const [index, minimumPart] of MINIMUM_NODE_VERSION.entries()) {
  if (nodeVersion[index] > minimumPart) {
    break;
  }

  if (nodeVersion[index] < minimumPart) {
    nodeIsSupported = false;
    break;
  }
}

requireCondition(
  manifest.engines?.node === EXPECTED_NODE_RANGE,
  `The bridge manifest must require Node.js ${EXPECTED_NODE_RANGE}.`,
);
requireCondition(
  nodeIsSupported,
  `Node.js ${EXPECTED_NODE_RANGE} is required to verify and run the locked bridge (found ${process.versions.node}).`,
);
requireCondition(
  createHash('sha256').update(lockBytes).digest('hex') === EXPECTED_LOCK_SHA256,
  'The bridge package-lock fingerprint changed; review it and update the expected fingerprint.',
);
requireCondition(
  connectorSource.includes(`MCP_REMOTE_LOCK_SHA256="${EXPECTED_LOCK_SHA256}"`),
  'connect-mcp.sh must version installs by the reviewed package-lock fingerprint.',
);
requireCondition(
  connectorSource.includes(`MCP_REMOTE_VERSION="${EXPECTED_VERSION}"`),
  'connect-mcp.sh must validate installs against the reviewed mcp-remote version.',
);
requireCondition(
  connectorSource.includes(`MCP_REMOTE_MIN_NODE_VERSION="${EXPECTED_NODE_RANGE.slice(2)}"`),
  'connect-mcp.sh must enforce the reviewed Node.js engine floor.',
);

requireCondition(
  manifest.dependencies?.['mcp-remote'] === EXPECTED_VERSION,
  `mcp-remote must remain exactly pinned to ${EXPECTED_VERSION} in package.json.`,
);
requireCondition(
  lock.lockfileVersion === 3,
  'The mcp-remote bridge requires npm lockfileVersion 3.',
);
requireCondition(
  lockedRoot?.dependencies?.['mcp-remote'] === EXPECTED_VERSION,
  `The package-lock root must require exactly mcp-remote@${EXPECTED_VERSION}.`,
);
requireCondition(
  lockedBridge?.version === EXPECTED_VERSION,
  `The lock resolved mcp-remote to ${lockedBridge?.version ?? 'nothing'}, expected ${EXPECTED_VERSION}.`,
);
requireCondition(
  lockedBridge?.integrity === EXPECTED_INTEGRITY,
  'The reviewed mcp-remote tarball integrity changed; re-review it before updating the pin.',
);

let registryPackages = 0;
for (const [name, entry] of Object.entries(lock.packages ?? {})) {
  if (!entry.resolved) {
    continue;
  }

  registryPackages += 1;
  requireCondition(
    entry.resolved.startsWith('https://registry.npmjs.org/'),
    `Locked package ${name} does not resolve from the HTTPS npm registry: ${entry.resolved}`,
  );
  requireCondition(
    typeof entry.integrity === 'string' && entry.integrity.startsWith('sha512-'),
    `Locked package ${name} is missing a sha512 integrity value.`,
  );
}

if (process.argv.includes('--installed')) {
  const installed = readJson(resolve(bridgeDirectory, 'node_modules/mcp-remote/package.json'));
  requireCondition(
    installed.version === EXPECTED_VERSION,
    `Installed mcp-remote is ${installed.version ?? 'unknown'}, expected ${EXPECTED_VERSION}.`,
  );
}

console.log(
  `mcp-remote@${EXPECTED_VERSION} and ${registryPackages} integrity-locked registry packages verified.`,
);
