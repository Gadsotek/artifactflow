import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { connectionConfig } from './mcp-desktop/connection.mjs';

const source = new URL('./mcp-desktop/', import.meta.url);
const token = `af_mcp_${'a'.repeat(64)}`;

test('distribution metadata includes privacy, publisher contacts, and both PNG icon themes', () => {
  const manifest = JSON.parse(readFileSync(new URL('manifest.json', source)));
  assert.equal(manifest.author.name, 'Gadsotek');
  assert.equal(new URL(manifest.author.url).protocol, 'https:');
  assert.ok(manifest.author.email);
  assert.ok(manifest.privacy_policies.length > 0);
  for (const policy of manifest.privacy_policies) assert.equal(new URL(policy).protocol, 'https:');
  assert.equal(manifest.icon, 'assets/icon.png');
  assert.deepEqual(manifest.icons.map((icon) => icon.theme).sort(), ['dark', 'light']);
  for (const icon of manifest.icons) {
    assert.equal(icon.size, '512x512');
    const png = readFileSync(new URL(icon.src, source));
    assert.equal(png.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
    assert.equal(png.readUInt32BE(16), 512);
    assert.equal(png.readUInt32BE(20), 512);
    assert.equal(png[25], 6, 'Icons must preserve RGBA transparency.');
  }
  assert.match(manifest.user_config.server_url.description, /https:\/\/app\.example\.com/);
});

test('the packaged instructions disclose privacy and distribution limitations', () => {
  const readme = readFileSync(
    new URL('../docs/operations/mcp-desktop.md', import.meta.url),
    'utf8',
  );
  assert.match(readme, /^## Privacy Policy$/m);
  assert.doesNotMatch(readme, /mcp-desktop-publication|docs\/internal/);
  assert.match(readme, /\]\(mcp-desktop-privacy\.md\)/);
  const privacy = readFileSync(
    new URL('../docs/operations/mcp-desktop-privacy.md', import.meta.url),
    'utf8',
  );
  for (const section of ['Data handled', 'Storage and retention', 'Third parties', 'Contact']) {
    assert.ok(privacy.includes(`## ${section}`), `Missing privacy disclosure: ${section}`);
  }
});

test('accepts an app origin or the MCP endpoint without changing its authority', () => {
  for (const url of [
    'https://vault.example',
    'https://vault.example/',
    'https://vault.example/mcp',
    'https://vault.example/mcp/',
  ]) {
    assert.deepEqual(connectionConfig(url, token), {
      endpoint: 'https://vault.example/mcp',
      token,
      allowHttp: false,
    });
  }
  assert.equal(
    connectionConfig('https://vault.example:8443', token).endpoint,
    'https://vault.example:8443/mcp',
  );
});

test('permits plaintext only for explicit loopback development origins', () => {
  for (const host of ['localhost', '127.0.0.1', '[::1]']) {
    assert.equal(connectionConfig(`http://${host}:18080`, token).allowHttp, true);
  }
});

test('rejects ambiguous URLs and credentials in URLs without reflecting their values', () => {
  for (const url of [
    '',
    'vault.example',
    'http://vault.example',
    'http://127.1',
    'http://2130706433',
    'http://localhost:80@vault.example',
    'https://user:password@vault.example',
    'https://vault.example/mcp?token=secret',
    'https://vault.example/#secret',
    'https://vault.example/other',
    'https://vault.example/%6dcp',
    'https://vault.example/../mcp',
    'https://vault.example\\@other.example',
    'https://vault.example\n',
    'file:///tmp/mcp',
    'https://vault.example:443@evil.example',
  ]) {
    assert.throws(() => connectionConfig(url, token), {
      message:
        'Enter the HTTPS ArtifactFlow app URL or its /mcp endpoint. HTTP is allowed only on localhost.',
    });
  }
});

test('requires the exact personal token format and rejects header injection', () => {
  for (const value of [
    '',
    undefined,
    'Bearer ' + token,
    token + '\r\nX-Test: injected',
    token + ' ',
    'af_mcp_short',
    `af_mcp_${'_'.repeat(64)}`,
  ]) {
    assert.throws(() => connectionConfig('https://vault.example', value), {
      message:
        'Enter your complete personal MCP token from AI connections, without the Bearer prefix.',
    });
  }
});

test('Desktop collects credentials securely and launches its built-in Node runtime', () => {
  const manifest = JSON.parse(readFileSync(new URL('manifest.json', source)));
  assert.equal(manifest.server.type, 'node');
  assert.equal(manifest.server.mcp_config.command, 'node');
  assert.deepEqual(manifest.server.mcp_config.args, ['${__dirname}/server.mjs']);
  assert.equal(manifest.user_config.token.sensitive, true);
  assert.equal(manifest.user_config.token.required, true);
  assert.equal(manifest.user_config.token.default, undefined);
  assert.equal(manifest.user_config.server_url.required, true);
  assert.deepEqual(manifest.server.mcp_config.env, {
    ARTIFACTFLOW_MCP_URL: '${user_config.server_url}',
    ARTIFACTFLOW_MCP_TOKEN: '${user_config.token}',
  });
});

function launcherFixture(proxy) {
  const directory = mkdtempSync(join(tmpdir(), 'artifactflow-desktop-unit-'));
  for (const file of ['server.mjs', 'connection.mjs'])
    copyFileSync(new URL(file, source), join(directory, file));
  if (proxy !== undefined) {
    const dist = join(directory, 'node_modules/mcp-remote/dist');
    mkdirSync(dist, { recursive: true });
    writeFileSync(join(dist, 'proxy.js'), proxy);
  }
  return (overrides = {}) =>
    spawnSync(process.execPath, [join(directory, 'server.mjs')], {
      cwd: tmpdir(),
      env: {
        PATH: '/nonexistent',
        ARTIFACTFLOW_MCP_URL: 'https://vault.example',
        ARTIFACTFLOW_MCP_TOKEN: token,
        ...overrides,
      },
      encoding: 'utf8',
      timeout: 5000,
    });
}

test('missing bundled bridge fails closed without consulting PATH or downloading packages', () => {
  const result = launcherFixture()();
  assert.equal(result.status, 1);
  assert.equal(result.stdout, '');
  assert.match(result.stderr, /Reinstall the ArtifactFlow extension/);
  assert.ok(!result.stderr.includes(token));
});

test('launches the bundled bridge from another cwd without an external Node installation', () => {
  const result = launcherFixture(`
    const assert = require('node:assert/strict');
    assert.equal(process.env.AUTH_HEADER, 'Bearer ' + '${token}');
    assert.equal(process.env.ARTIFACTFLOW_MCP_TOKEN, undefined);
    assert.ok(!process.argv.join(' ').includes('${token}'));
    assert.ok(process.argv.includes('--silent'));
    assert.ok(process.argv.includes('http-only'));
    assert.ok(!process.argv.includes('--allow-http'));
    process.stdout.write('{"jsonrpc":"2.0","id":1,"result":{}}\\n');
  `)();
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout), { jsonrpc: '2.0', id: 1, result: {} });
  assert.equal(result.stderr, '');
});

test('bridge failures never copy raw diagnostics or credentials into Desktop logs', () => {
  const result = launcherFixture(`
    process.stderr.write(process.env.AUTH_HEADER + ' private artifact content');
    process.exit(1);
  `)();
  assert.equal(result.status, 1);
  assert.equal(result.stdout, '');
  assert.match(result.stderr, /Check the server URL, token, and network access/);
  assert.ok(!result.stderr.includes(token));
  assert.ok(!result.stderr.includes('private artifact content'));
});

test('invalid settings are rejected before launching the bridge', () => {
  const result = launcherFixture("process.stdout.write('bridge was started');")({
    ARTIFACTFLOW_MCP_URL: 'http://vault.example',
  });
  assert.equal(result.status, 1);
  assert.equal(result.stdout, '');
  assert.match(result.stderr, /HTTPS ArtifactFlow app URL/);
});

test('loopback enables HTTP explicitly while inherited TLS bypasses remain disabled', () => {
  const result = launcherFixture(`
    const assert = require('node:assert/strict');
    assert.ok(process.argv.includes('--allow-http'));
    assert.ok(process.argv.includes('http://127.0.0.1:18080/mcp'));
    assert.equal(process.env.NODE_TLS_REJECT_UNAUTHORIZED, '1');
  `)({
    ARTIFACTFLOW_MCP_URL: 'http://127.0.0.1:18080',
    NODE_TLS_REJECT_UNAUTHORIZED: '0',
  });
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.stdout, '');
});
