import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { dirname, resolve } from 'node:path';
import { createInterface } from 'node:readline';
import { fileURLToPath } from 'node:url';

const BRIDGE_VERSION = '0.1.49';
const BEARER_TOKEN = 'artifactflow-nightly-bridge-token';
const AUTHORIZATION = `Bearer ${BEARER_TOKEN}`;
const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const bridgeDirectory = resolve(repositoryRoot, 'scripts/mcp-remote-bridge');
const bridgeEntrypoint = resolve(bridgeDirectory, 'node_modules/mcp-remote/dist/proxy.js');
const smokeWorkingDirectory = process.env.MCP_BRIDGE_SMOKE_CWD;
let authorizedRequests = 0;

if (!smokeWorkingDirectory) {
  throw new Error('MCP_BRIDGE_SMOKE_CWD must point at a dedicated empty working directory.');
}

const server = createServer((request, response) => {
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
    rejectCompletion(new Error(`Timed out waiting for direct MCP bridge output. stderr: ${stderr}`));
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
  await new Promise((resolveClose) => server.close(resolveClose));
}
