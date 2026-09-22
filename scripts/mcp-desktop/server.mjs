import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { connectionConfig } from './connection.mjs';

function fail(message) {
  process.stderr.write(`ArtifactFlow: ${message}\n`);
  process.exit(1);
}

let connection;
try {
  connection = connectionConfig(
    process.env.ARTIFACTFLOW_MCP_URL,
    process.env.ARTIFACTFLOW_MCP_TOKEN,
  );
} catch (error) {
  fail(error.message);
}

const entrypoint = fileURLToPath(
  new URL('./node_modules/mcp-remote/dist/proxy.js', import.meta.url),
);
if (!existsSync(entrypoint))
  fail('Reinstall the ArtifactFlow extension; its bundled bridge is missing.');

const env = {
  ...process.env,
  AUTH_HEADER: `Bearer ${connection.token}`,
  NODE_TLS_REJECT_UNAUTHORIZED: '1',
};
delete env.ARTIFACTFLOW_MCP_TOKEN;
delete env.ARTIFACTFLOW_MCP_URL;
delete process.env.ARTIFACTFLOW_MCP_TOKEN;

const child = spawn(
  process.execPath,
  [
    entrypoint,
    connection.endpoint,
    ...(connection.allowHttp ? ['--allow-http'] : []),
    '--transport',
    'http-only',
    '--header',
    'Authorization:${AUTH_HEADER}',
    '--silent',
  ],
  {
    env,
    // stdout is the MCP protocol stream. Suppress third-party stderr entirely:
    // even a remote error body can contain private content or a reflected token.
    stdio: ['inherit', 'inherit', 'ignore'],
    windowsHide: true,
  },
);

let stopping = false;
for (const signal of ['SIGINT', 'SIGTERM']) {
  process.once(signal, () => {
    stopping = true;
    child.kill(signal);
    setTimeout(() => child.kill('SIGKILL'), 2000).unref();
  });
}
child.once('error', () =>
  fail('Unable to start the bundled bridge. Reinstall the ArtifactFlow extension.'),
);
child.once('exit', (code) => {
  if (!stopping && code !== 0)
    fail('Connection failed. Check the server URL, token, and network access.');
  process.exit(stopping ? 0 : (code ?? 1));
});
