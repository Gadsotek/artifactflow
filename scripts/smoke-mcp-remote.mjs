import { spawn } from 'node:child_process';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { createInterface } from 'node:readline';
import { fileURLToPath, pathToFileURL } from 'node:url';

const BRIDGE_VERSION = '0.16.7';
const BEARER_TOKEN = 'artifactflow-nightly-bridge-token';
const AUTHORIZATION = `Bearer ${BEARER_TOKEN}`;
const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const bridgeDirectory = resolve(repositoryRoot, 'scripts/mcp-remote-bridge');
const bridgeEntrypoint = resolve(bridgeDirectory, 'node_modules/mcp-remote/dist/proxy.js');
const smokeWorkingDirectory = process.env.MCP_BRIDGE_SMOKE_CWD;
let authorizedRequests = 0;
let authorizedProbeRequests = 0;
let crossOriginRequests = 0;
const forwardedMethods = [];
let forwardedClientName;

if (!smokeWorkingDirectory) {
  throw new Error('MCP_BRIDGE_SMOKE_CWD must point at a dedicated empty working directory.');
}

const bridgeRequire = createRequire(resolve(bridgeDirectory, 'package.json'));

// ArtifactFlow supplies Authorization through an environment placeholder. Two
// tokens must select different credential caches, even with identical CLI args.
const chunk = readFileSync(bridgeEntrypoint, 'utf8').match(/from "\.\/(chunk-[^"]+\.js)"/u)?.[1];
assert.ok(chunk, 'The reviewed bridge must expose its shared CLI parser.');
const { parseCommandLineArgs } = await import(
  pathToFileURL(resolve(dirname(bridgeEntrypoint), chunk))
);
const priorHeader = process.env.AUTH_HEADER;
try {
  const parse = (header) =>
    parseCommandLineArgs(
      [
        'https://artifactflow.example/mcp',
        '--header',
        header,
        // Avoid reading any real OAuth registration while exercising cache keys.
        '--static-oauth-client-info',
        '{"client_id":"artifactflow-smoke"}',
        '--silent',
      ],
      'ArtifactFlow bridge compatibility smoke',
    );
  process.env.AUTH_HEADER = 'Bearer artifactflow-smoke-token-a';
  const first = await parse('Authorization:${AUTH_HEADER}');
  process.env.AUTH_HEADER = 'Bearer artifactflow-smoke-token-b';
  const second = await parse('Authorization:${AUTH_HEADER}');
  const literal = await parse('Authorization:Bearer artifactflow-smoke-token-a');
  assert.equal(first.headers.Authorization, 'Bearer artifactflow-smoke-token-a');
  assert.equal(second.headers.Authorization, 'Bearer artifactflow-smoke-token-b');
  assert.notEqual(
    first.serverUrlHash,
    second.serverUrlHash,
    'Different expanded bearer tokens must use distinct credential caches.',
  );
  assert.equal(
    first.serverUrlHash,
    literal.serverUrlHash,
    'Literal and environment-expanded headers must select the same credential cache.',
  );
} finally {
  if (priorHeader === undefined) delete process.env.AUTH_HEADER;
  else process.env.AUTH_HEADER = priorHeader;
}
console.log('Bridge expanded-header credential-cache isolation regression passed.');

// GHSA-6pq8-jhm2-2vcw: comma serialization must tolerate JSON or parsed
// objects without a callable toString. This is dependency coverage, not a
// claim that ArtifactFlow exposes this serialization path to remote input.
const qs = bridgeRequire('qs');
for (const encodeValuesOnly of [false, true]) {
  const options = { arrayFormat: 'comma', encodeValuesOnly };
  const input = JSON.parse('{"items":["start",{"toString":"untrusted"},"end"]}');
  assert.doesNotThrow(
    () => qs.stringify(input, options),
    'GHSA-6pq8-jhm2-2vcw: non-callable toString must not crash comma serialization.',
  );
  assert.equal(
    qs.stringify(input, options),
    encodeValuesOnly
      ? 'items=start,%5Bobject%20Object%5D,end'
      : 'items=start%2C%5Bobject%20Object%5D%2Cend',
  );
  for (const parsed of [
    qs.parse('items[][label]=value', { plainObjects: true }),
    qs.parse('items[0][toString]=untrusted', { allowPrototypes: true }),
    JSON.parse('{"items":[[{"toString":"untrusted"}]]}'),
  ]) {
    assert.equal(qs.stringify(parsed, options), 'items=%5Bobject%20Object%5D');
  }
  assert.equal(
    qs.stringify({ items: ['one', 2, true] }, options),
    encodeValuesOnly ? 'items=one,2,true' : 'items=one%2C2%2Ctrue',
  );
  assert.equal(qs.stringify({ items: [{ toString: () => 'custom' }] }, options), 'items=custom');
  assert.throws(
    () =>
      qs.stringify(
        {
          items: [
            {
              toString() {
                throw new SyntaxError('fixture');
              },
            },
          ],
        },
        options,
      ),
    SyntaxError,
    'User-defined exceptions must not be swallowed by the security fix.',
  );
}
assert.deepEqual(qs.parse('name=example&tags[]=one&tags[]=two'), {
  name: 'example',
  tags: ['one', 'two'],
});
console.log('Bridge qs comma serialization regression passed.');
// GHSA-jqcg-44mw-7w3h: neither trust path may accept a forged forwarded address
// from an IPv4 peer through a native IPv6 or short-prefix mapped trust subnet.
const proxyaddr = bridgeRequire('proxy-addr');
for (const subnet of ['::ffff:10.0.0.0/8', '::/1']) {
  for (const trusted of [[subnet], [subnet, '2001:db8::/32']]) {
    for (const remoteAddress of ['203.0.113.10', '::ffff:203.0.113.10']) {
      assert.equal(
        proxyaddr(
          { connection: { remoteAddress }, headers: { 'x-forwarded-for': '127.0.0.1' } },
          trusted,
        ),
        remoteAddress,
        `Untrusted socket peer must not control the client address through ${subnet}.`,
      );
    }
  }
}
for (const trusted of [['::ffff:10.0.0.0/104'], ['::ffff:10.0.0.0/104', '2001:db8::/32']]) {
  const trust = proxyaddr.compile(trusted);
  assert.equal(trust('10.1.2.3'), true);
  assert.equal(trust('::ffff:10.1.2.3'), true);
  assert.equal(trust('203.0.113.10'), false);
  assert.equal(trust('::ffff:203.0.113.10'), false);
  assert.equal(trust('::1'), false);
}
assert.equal(proxyaddr.compile(['2001:db8::/32'])('2001:db8::1'), true);
console.log('Bridge proxy-address trust regression passed.');

// The bridge's authentication probes carry user-supplied headers. Follow a
// redirect within the selected origin, but never send those headers elsewhere.
const otherOrigin = createServer((_request, response) => {
  crossOriginRequests += 1;
  response.writeHead(200).end();
});
await new Promise((resolveListen, rejectListen) => {
  otherOrigin.once('error', rejectListen);
  otherOrigin.listen(0, '127.0.0.1', resolveListen);
});
const otherAddress = otherOrigin.address();
if (!otherAddress || typeof otherAddress === 'string') {
  throw new Error('MCP bridge redirect fixture did not bind a TCP port.');
}

const server = createServer((request, response) => {
  if (request.method === 'GET' && request.url === '/mcp') {
    response.writeHead(307, { Location: '/auth-probe' }).end();
    return;
  }
  if (request.method === 'GET' && request.url === '/auth-probe') {
    if (
      request.headers.authorization === AUTHORIZATION &&
      request.headers['x-artifactflow-smoke'] === AUTHORIZATION
    ) {
      authorizedProbeRequests += 1;
    }
    response
      .writeHead(307, {
        Location: `http://127.0.0.1:${otherAddress.port}/unexpected`,
      })
      .end();
    return;
  }
  if (request.url !== '/mcp' || request.method !== 'POST') {
    response.writeHead(404).end();
    return;
  }

  if (request.headers.authorization !== AUTHORIZATION) {
    response.writeHead(401).end();
    return;
  }

  authorizedRequests += 1;
  const chunks = [];
  request.on('data', (chunk) => chunks.push(chunk));
  request.on('end', () => {
    let message;
    try {
      message = JSON.parse(Buffer.concat(chunks).toString('utf8'));
    } catch {
      response.writeHead(400).end();
      return;
    }

    forwardedMethods.push(message.method);
    if (message.method === 'initialize') {
      forwardedClientName = message.params?.clientInfo?.name;
    }

    if (message.id === undefined) {
      response.writeHead(202).end();
      return;
    }

    let result;
    if (message.method === 'initialize') {
      response.setHeader('Mcp-Session-Id', 'artifactflow-nightly-session');
      result = {
        protocolVersion: message.params?.protocolVersion ?? '2025-06-18',
        capabilities: { tools: {} },
        serverInfo: { name: 'artifactflow-nightly-fixture', version: '1.0.0' },
      };
    } else if (message.method === 'tools/list') {
      result = { tools: [] };
    } else {
      response.writeHead(200, { 'Content-Type': 'application/json' }).end(
        JSON.stringify({
          jsonrpc: '2.0',
          id: message.id,
          error: { code: -32601, message: 'Method not found' },
        }),
      );
      return;
    }

    response
      .writeHead(200, { 'Content-Type': 'application/json' })
      .end(JSON.stringify({ jsonrpc: '2.0', id: message.id, result }));
  });
});

await new Promise((resolveListen, rejectListen) => {
  server.once('error', rejectListen);
  server.listen(0, '127.0.0.1', resolveListen);
});

const address = server.address();
if (!address || typeof address === 'string') {
  throw new Error('Nightly MCP bridge fixture did not bind a TCP port.');
}

const child = spawn(
  process.execPath,
  [
    bridgeEntrypoint,
    `http://127.0.0.1:${address.port}/mcp`,
    '--allow-http',
    '--transport',
    'http-only',
    '--header',
    'Authorization:${AUTH_HEADER}',
    '--header',
    'X-ArtifactFlow-Smoke:${AUTH_HEADER}',
    '--silent',
  ],
  {
    // Deliberately launch outside the install directory. Client-specific cwd
    // keys are not portable, so only the absolute entrypoint may resolve it.
    cwd: smokeWorkingDirectory,
    env: {
      ...process.env,
      AUTH_HEADER: AUTHORIZATION,
    },
    stdio: ['pipe', 'pipe', 'pipe'],
  },
);

let stderr = '';
let stdout = '';
child.stderr.setEncoding('utf8');
child.stderr.on('data', (chunk) => {
  stderr += chunk;
});
child.stdout.setEncoding('utf8');
child.stdout.on('data', (chunk) => {
  stdout += chunk;
});

const lines = createInterface({ input: child.stdout });
const completion = new Promise((resolveCompletion, rejectCompletion) => {
  let completed = false;
  const timer = setTimeout(() => {
    rejectCompletion(
      new Error(`Timed out waiting for direct MCP bridge output. stderr: ${stderr}`),
    );
  }, 20_000);

  child.once('exit', (code, signal) => {
    if (!completed) {
      clearTimeout(timer);
      rejectCompletion(
        new Error(`Direct MCP bridge exited ${code} (${signal ?? 'no signal'}). stderr: ${stderr}`),
      );
    }
  });

  lines.on('line', (line) => {
    let message;
    try {
      message = JSON.parse(line);
    } catch {
      return;
    }

    if (message.id === 1 && message.result) {
      child.stdin.write(
        `${JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' })}\n`,
      );
      child.stdin.write(`${JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'tools/list' })}\n`);
      return;
    }

    if (message.id === 2 && Array.isArray(message.result?.tools)) {
      completed = true;
      clearTimeout(timer);
      resolveCompletion();
    }
  });
});

child.stdin.write(
  `${JSON.stringify({
    jsonrpc: '2.0',
    id: 1,
    method: 'initialize',
    params: {
      protocolVersion: '2025-06-18',
      capabilities: {},
      clientInfo: { name: 'artifactflow-nightly', version: '1.0.0' },
    },
  })}\n`,
);

try {
  await completion;
  assert.ok(authorizedProbeRequests > 0, 'Same-origin probes must retain configured headers.');
  assert.equal(
    crossOriginRequests,
    0,
    'Authentication probes must not follow cross-origin redirects.',
  );
  if (forwardedClientName !== 'artifactflow-nightly (via mcp-remote ' + BRIDGE_VERSION + ')') {
    throw new Error('The server did not observe the reviewed MCP bridge version.');
  }
  const allowedMethods = ['initialize', 'notifications/initialized', 'tools/list'];
  if (forwardedMethods.some((method) => !allowedMethods.includes(method))) {
    throw new Error('The default MCP bridge path sent unexpected protocol-discovery traffic.');
  }
  if (authorizedRequests < 2) {
    throw new Error(
      `Expected authenticated initialize and tools/list requests, saw ${authorizedRequests}.`,
    );
  }
  if (stderr.includes(BEARER_TOKEN)) {
    throw new Error('The bridge copied its bearer token to stderr.');
  }
  if (stdout.includes(BEARER_TOKEN)) {
    throw new Error('The bridge copied its bearer token to stdout.');
  }
  console.log(`Direct mcp-remote@${BRIDGE_VERSION} authenticated MCP smoke passed.`);
} finally {
  lines.close();
  child.kill('SIGTERM');
  await Promise.all(
    [server, otherOrigin].map(
      (fixture) => new Promise((resolveClose) => fixture.close(resolveClose)),
    ),
  );
}
