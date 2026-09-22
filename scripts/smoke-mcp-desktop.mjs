import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { createInterface } from 'node:readline';

const archive = process.argv[2];
if (!archive) throw new Error('Usage: node scripts/smoke-mcp-desktop.mjs BUNDLE.mcpb');
const directory = mkdtempSync(join(tmpdir(), 'artifactflow-desktop-smoke-'));
const extracted = spawnSync('unzip', ['-q', resolve(archive), '-d', directory], {
  encoding: 'utf8',
});
assert.equal(extracted.status, 0, 'Could not extract the MCPB archive.');
const manifest = JSON.parse(readFileSync(join(directory, 'manifest.json')));
const receipt = JSON.parse(readFileSync(join(directory, 'BUILD.json')));
assert.equal(receipt.extensionVersion, manifest.version);
for (const file of [
  'manifest.json',
  manifest.icon,
  ...manifest.icons.map((icon) => icon.src),
  'README.md',
  'PRIVACY.md',
]) {
  const bytes = readFileSync(join(directory, file));
  assert.equal(
    createHash('sha256').update(bytes).digest('hex'),
    receipt.sources[file],
    `Bundled source hash mismatch: ${file}`,
  );
}
assert.match(readFileSync(join(directory, 'README.md'), 'utf8'), /\]\(PRIVACY\.md\)/);
assert.doesNotMatch(
  readFileSync(join(directory, 'README.md'), 'utf8'),
  /mcp-desktop-publication|docs\/internal/,
);
assert.match(
  readFileSync(join(directory, 'PRIVACY.md'), 'utf8'),
  /^# ArtifactFlow Desktop Extension Privacy Policy/m,
);
console.log('Verified bundled branding, offline privacy notice, and source hashes.');
const token = `af_mcp_${'s'.repeat(64)}`;
const secretContent = 'private-smoke-artifact-text';

async function exercise(status) {
  const seen = [];
  const server = createServer((request, response) => {
    if (request.url !== '/mcp' || request.method !== 'POST') {
      response.writeHead(404).end();
      return;
    }
    if (status !== 200) {
      response.writeHead(status, { 'Content-Type': 'text/plain' }).end(`${token} ${secretContent}`);
      return;
    }
    if (request.headers.authorization !== `Bearer ${token}`) {
      response.writeHead(401).end();
      return;
    }
    const chunks = [];
    request.on('data', (chunk) => chunks.push(chunk));
    request.on('end', () => {
      const message = JSON.parse(Buffer.concat(chunks).toString());
      seen.push(message);
      if (message.id === undefined) {
        response.writeHead(202).end();
        return;
      }
      let result;
      if (message.method === 'initialize') {
        result = {
          protocolVersion: message.params.protocolVersion,
          capabilities: { tools: {} },
          serverInfo: { name: 'artifactflow-desktop-fixture', version: '1.0.0' },
        };
      } else if (message.method === 'tools/list') {
        result = {
          tools: [{ name: 'read', description: 'Read fixture', inputSchema: { type: 'object' } }],
        };
      } else if (message.method === 'tools/call') {
        result = { content: [{ type: 'text', text: secretContent }] };
      } else {
        response.writeHead(400).end();
        return;
      }
      response
        .writeHead(200, { 'Content-Type': 'application/json' })
        .end(JSON.stringify({ jsonrpc: '2.0', id: message.id, result }));
    });
  });
  await new Promise((done, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', done);
  });
  const endpoint = `http://127.0.0.1:${server.address().port}`;
  const config = manifest.server.mcp_config;
  const expand = (value) =>
    value
      .replaceAll('${__dirname}', directory)
      .replaceAll('${user_config.server_url}', endpoint)
      .replaceAll('${user_config.token}', token);
  const child = spawn(process.execPath, config.args.map(expand), {
    cwd: tmpdir(),
    // No Node/npm/Python on PATH, no checkout dependencies, no real user cache.
    env: {
      PATH: '/nonexistent',
      HOME: directory,
      USERPROFILE: directory,
      ...Object.fromEntries(Object.entries(config.env).map(([key, value]) => [key, expand(value)])),
    },
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  let stderr = '';
  child.stderr.on('data', (chunk) => {
    stderr += chunk;
  });
  const messages = [];
  const lines = createInterface({ input: child.stdout });
  const send = (message) =>
    child.stdin.write(`${JSON.stringify({ jsonrpc: '2.0', ...message })}\n`);
  const exited = new Promise((done) => child.once('exit', (code) => done(code)));
  let timer;
  try {
    await new Promise((done, reject) => {
      timer = setTimeout(
        () => reject(new Error(`Desktop smoke timed out for HTTP ${status}.`)),
        20000,
      );
      child.once('error', reject);
      child.once('exit', (code) =>
        status !== 200 && code !== 0
          ? done()
          : reject(new Error('Bridge exited before completing the exchange.')),
      );
      lines.on('line', (line) => {
        try {
          const message = JSON.parse(line);
          messages.push(message);
          if (message.id === 1 && message.result) {
            send({ method: 'notifications/initialized' });
            send({ id: 2, method: 'tools/list' });
          } else if (message.id === 2 && message.result) {
            send({ id: 3, method: 'tools/call', params: { name: 'read', arguments: {} } });
          } else if (message.id === 3 && message.result) done();
        } catch {
          reject(new Error('Bridge emitted an invalid protocol message.'));
        }
      });
      send({
        id: 1,
        method: 'initialize',
        params: {
          protocolVersion: '2025-06-18',
          capabilities: {},
          clientInfo: { name: 'artifactflow-desktop-smoke', version: '1.0.0' },
        },
      });
    });
    assert.ok(
      !stderr.includes(token) && !stderr.includes(secretContent),
      'Private data reached Desktop diagnostics.',
    );
    assert.ok(!JSON.stringify(messages).includes(token), 'Token reached the protocol stream.');
    if (status === 200) {
      // The reviewed bridge performs its own initialization probe before
      // forwarding Desktop's initialization on this stateless HTTP fixture.
      assert.deepEqual(
        seen.map((message) => message.method),
        [
          'initialize',
          'notifications/initialized',
          'initialize',
          'notifications/initialized',
          'tools/list',
          'tools/call',
        ],
      );
      assert.equal(
        messages.find((message) => message.id === 3).result.content[0].text,
        secretContent,
      );
      assert.equal(stderr, '');
    } else {
      assert.ok(
        !messages.some((message) => message.result),
        'An authorization failure produced a successful result.',
      );
      assert.match(stderr, /Check the server URL, token, and network access/);
    }
  } finally {
    clearTimeout(timer);
    lines.close();
    child.kill('SIGTERM');
    await exited;
    server.closeAllConnections();
    await new Promise((done) => server.close(done));
  }
}

await exercise(200);
await exercise(401);
await exercise(403);
console.log(
  'Desktop bundle: authenticated initialize, tools/list, tools/call, 401/403 rejection, and private diagnostics passed without external runtimes on PATH.',
);
