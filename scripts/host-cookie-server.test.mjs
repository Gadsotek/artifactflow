import assert from 'node:assert/strict';
import childProcess from 'node:child_process';
import { mkdtempSync, readdirSync, rmdirSync } from 'node:fs';
import { request } from 'node:http';
import { syncBuiltinESMExports } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { connect } from 'node:tls';
import { createHostCookieServer } from '../tests/e2e/helpers/host-cookie-server.ts';

test('cookie fixture starts with socket-backed OpenSSL stdio and receives HTTPS headers', async (t) => {
  const previousDirectory = process.cwd();
  const fixtureDirectory = mkdtempSync(join(tmpdir(), 'artifactflow-cookie-fixture-'));
  process.chdir(fixtureDirectory);
  t.after(() => {
    process.chdir(previousDirectory);
    rmdirSync(fixtureDirectory);
  });
  const origin = 'https://artifactflow.cookie-prefix.test';
  const server = await createHostCookieServer([origin]);
  t.after(() => server.close());
  assert.deepEqual(readdirSync(fixtureDirectory), [], 'TLS credentials must stay in memory.');
  const cookie = '__Host-artifactflow_session=fixture; Secure; HttpOnly; Path=/';
  server.responseCookies.set(`${origin}/probe`, cookie);

  const tunnel = await new Promise((resolve, reject) => {
    const proxy = request(server.proxy.server, {
      method: 'CONNECT',
      path: 'artifactflow.cookie-prefix.test:443',
      timeout: 5_000,
    });
    proxy.once('error', reject);
    proxy.once('timeout', () => proxy.destroy(new Error('CONNECT timed out.')));
    proxy.once('connect', (response, socket) => {
      if (response.statusCode !== 200) {
        socket.destroy();
        reject(new Error(`CONNECT failed with ${response.statusCode}.`));
        return;
      }
      resolve(socket);
    });
    proxy.end();
  });

  const tls = connect({
    socket: tunnel,
    servername: 'artifactflow.cookie-prefix.test',
    rejectUnauthorized: false,
  });
  t.after(() => tls.destroy());
  tls.setTimeout(5_000, () => tls.destroy(new Error('TLS probe timed out.')));
  const response = await new Promise((resolve, reject) => {
    let received = '';
    tls.setEncoding('utf8');
    tls.once('error', reject);
    tls.on('data', (chunk) => {
      received += chunk;
    });
    tls.once('end', () => resolve(received));
    tls.once('secureConnect', () => {
      tls.write(
        'GET /probe HTTP/1.1\r\nHost: artifactflow.cookie-prefix.test\r\n' +
          'Cookie: __Host-artifactflow_session=received\r\nConnection: close\r\n\r\n',
      );
    });
  });

  assert.match(response, /^HTTP\/1\.1 200 /);
  assert.ok(response.includes(`Set-Cookie: ${cookie}\r\n`));
  assert.deepEqual(server.requests.get(`${origin}/probe`), {
    cookie: '__Host-artifactflow_session=received',
  });
});

test('cookie fixture setup errors do not expose child-process output', async (t) => {
  const capturedOutput = Buffer.from('sensitive fixture material');
  const mock = t.mock.method(childProcess, 'execFileSync', () => {
    throw Object.assign(new Error('tool failure with sensitive fixture material'), {
      stdout: capturedOutput,
      stderr: capturedOutput,
      output: [null, capturedOutput, capturedOutput],
    });
  });
  syncBuiltinESMExports();
  try {
    await assert.rejects(
      createHostCookieServer(['https://artifactflow.cookie-prefix.test']),
      (error) => {
        assert.equal(
          error.message,
          'OpenSSL could not generate the ephemeral cookie-test TLS certificate.',
        );
        assert.deepEqual(Object.keys(error), []);
        assert.equal(error.cause, undefined);
        return true;
      },
    );
  } finally {
    mock.mock.restore();
    syncBuiltinESMExports();
  }
});
