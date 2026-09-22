import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
  constants,
  copyFileSync,
  lstatSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  readdirSync,
  utimesSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const args = process.argv.slice(2);
if (args.length && (args.length !== 2 || args[0] !== '--output')) {
  console.error('Usage: node scripts/build-mcp-desktop.mjs [--output FILE.mcpb]');
  process.exit(1);
}

function run(command, commandArgs, options = {}) {
  const result = spawnSync(command, commandArgs, { cwd: root, stdio: 'inherit', ...options });
  if (result.error || result.status !== 0)
    throw new Error(`Required build step failed: ${command}`);
}

try {
  run(process.execPath, ['--test', 'scripts/mcp-desktop.test.mjs']);
  // The existing independent review gate remains authoritative; do not derive
  // approval from whichever lock happens to be present in the checkout.
  run(process.execPath, ['scripts/verify-mcp-remote.mjs']);
  const manifest = JSON.parse(readFileSync(join(root, 'scripts/mcp-desktop/manifest.json')));
  const packageBytes = readFileSync(join(root, 'scripts/mcp-remote-bridge/package.json'));
  const lockBytes = readFileSync(join(root, 'scripts/mcp-remote-bridge/package-lock.json'));
  const bridge = JSON.parse(packageBytes);
  if (manifest.compatibility.runtimes.node !== bridge.engines.node) {
    throw new Error('The Desktop runtime floor must match the reviewed bridge.');
  }
  const output = resolve(
    args[1] ?? join(root, `storage/app/mcp-desktop/artifactflow-${manifest.version}.mcpb`),
  );
  if (!output.endsWith('.mcpb')) throw new Error('The output filename must end in .mcpb.');
  const staging = mkdtempSync(join(tmpdir(), 'artifactflow-desktop-build-'));
  const bundle = join(staging, 'bundle');
  mkdirSync(bundle);
  const sourceFiles = [
    'manifest.json',
    'connection.mjs',
    'server.mjs',
    'assets/icon.png',
    'assets/icon-dark.png',
    'assets/icon.svg',
    'assets/icon-dark.svg',
    'assets/README.md',
  ];
  for (const file of sourceFiles) {
    mkdirSync(dirname(join(bundle, file)), { recursive: true });
    copyFileSync(join(root, 'scripts/mcp-desktop', file), join(bundle, file));
  }
  copyFileSync(join(root, 'LICENSE'), join(bundle, 'LICENSE'));
  copyFileSync(join(root, 'docs/operations/mcp-desktop-privacy.md'), join(bundle, 'PRIVACY.md'));
  const readme = readFileSync(join(root, 'docs/operations/mcp-desktop.md'), 'utf8')
    .replaceAll(
      '](mcp.md)',
      '](https://github.com/Gadsotek/artifactflow/blob/main/docs/operations/mcp.md)',
    )
    .replaceAll(
      '](mcp-bridge.md)',
      '](https://github.com/Gadsotek/artifactflow/blob/main/docs/operations/mcp-bridge.md)',
    )
    .replaceAll('](mcp-desktop-privacy.md)', '](PRIVACY.md)');
  writeFileSync(join(bundle, 'README.md'), readme);
  writeFileSync(join(bundle, 'package.json'), packageBytes);
  writeFileSync(join(bundle, 'package-lock.json'), lockBytes);
  const userConfig = join(staging, 'npm-user.conf');
  const globalConfig = join(staging, 'npm-global.conf');
  writeFileSync(userConfig, '');
  writeFileSync(globalConfig, '');
  const env = { ...process.env };
  for (const key of ['MCP_TOKEN', 'AUTH_HEADER', 'ARTIFACTFLOW_MCP_TOKEN']) delete env[key];
  run(
    'npm',
    [
      'ci',
      '--engine-strict',
      '--ignore-scripts',
      '--omit=dev',
      '--no-bin-links',
      '--no-audit',
      '--no-fund',
      '--cache',
      join(tmpdir(), 'artifactflow-mcpb-npm-cache'),
      '--userconfig',
      userConfig,
      '--globalconfig',
      globalConfig,
    ],
    { cwd: bundle, env },
  );
  const installed = JSON.parse(readFileSync(join(bundle, 'node_modules/mcp-remote/package.json')));
  if (installed.version !== bridge.dependencies['mcp-remote'])
    throw new Error('Installed bridge does not match the reviewed version.');
  writeFileSync(
    join(bundle, 'BUILD.json'),
    `${JSON.stringify(
      {
        extensionVersion: manifest.version,
        bridgeVersion: installed.version,
        lockSha256: createHash('sha256').update(lockBytes).digest('hex'),
        sources: Object.fromEntries(
          [...sourceFiles, 'README.md', 'PRIVACY.md', 'LICENSE'].map((file) => [
            file,
            createHash('sha256')
              .update(readFileSync(join(bundle, file)))
              .digest('hex'),
          ]),
        ),
      },
      null,
      2,
    )}\n`,
  );
  writeFileSync(
    join(bundle, 'NOTICE.txt'),
    'ArtifactFlow Desktop connector: AGPL-3.0-or-later; see LICENSE.\nIncludes the integrity-locked mcp-remote bridge and its dependencies.\nPreserve the license and notice files in node_modules when distributing.\nExact dependency versions and integrity values are in package-lock.json.\nSource: https://github.com/Gadsotek/artifactflow/tree/main/scripts/mcp-desktop\n',
  );

  // Package only a fresh, explicitly populated staging directory. Never zip
  // the checkout, existing node_modules, user settings, caches, or credentials.
  const files = [];
  function collect(relative = '') {
    for (const name of readdirSync(join(bundle, relative)).sort()) {
      const path = relative ? `${relative}/${name}` : name;
      const stat = lstatSync(join(bundle, path));
      if (stat.isSymbolicLink() || /[\r\n\\]/.test(name))
        throw new Error('Unsupported package entry.');
      if (name === '.package-lock.json') continue;
      if (stat.isDirectory()) collect(path);
      else if (stat.isFile()) {
        utimesSync(
          join(bundle, path),
          new Date('2000-01-01T00:00:00Z'),
          new Date('2000-01-01T00:00:00Z'),
        );
        files.push(path);
      } else throw new Error('Unsupported package entry.');
    }
  }
  collect();
  const archive = join(staging, 'artifactflow.mcpb');
  run('zip', ['-X', '-q', '-9', archive, '-@'], {
    cwd: bundle,
    input: files.sort().join('\n') + '\n',
    stdio: ['pipe', 'inherit', 'inherit'],
    env: { ...env, TZ: 'UTC' },
  });
  run(process.execPath, ['scripts/smoke-mcp-desktop.mjs', archive]);
  // Existing distributables are immutable: a failed rebuild must not replace
  // a package that an operator may already be distributing.
  mkdirSync(dirname(output), { recursive: true });
  copyFileSync(archive, output, constants.COPYFILE_EXCL);
  const hash = createHash('sha256').update(readFileSync(output)).digest('hex');
  console.log(`Built ${output}\nSHA-256 ${hash}`);
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
