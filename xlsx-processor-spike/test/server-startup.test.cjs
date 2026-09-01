'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');
const test = require('node:test');
const { healthSignature } = require('../src/processor-protocol.cjs');

const serverPath = path.resolve(__dirname, '../src/start-server.cjs');

async function waitForSocket(socketPath, child) {
  const deadline = Date.now() + 35_000;

  while (Date.now() < deadline) {
    if (child.exitCode !== null) {
      throw new Error(`XLSX processor exited before binding its socket (code ${child.exitCode}).`);
    }

    try {
      const socket = fs.lstatSync(socketPath);
      if (socket.isSocket() && (socket.mode & 0o777) === 0o666) {
        return;
      }
    } catch (error) {
      if (error.code !== 'ENOENT') {
        throw error;
      }
    }

    await new Promise((resolve) => setTimeout(resolve, 20));
  }

  throw new Error('XLSX processor did not complete its startup self-test in time.');
}

function healthOverSocket(socketPath, secret) {
  const timestamp = String(Math.floor(Date.now() / 1_000));
  const nonce = crypto.randomBytes(16).toString('hex');

  return new Promise((resolve, reject) => {
    const request = http.get(
      {
        headers: {
          'X-ArtifactFlow-Processor-Nonce': nonce,
          'X-ArtifactFlow-Processor-Signature': healthSignature({ nonce, secret, timestamp }),
          'X-ArtifactFlow-Processor-Timestamp': timestamp,
        },
        path: '/health',
        socketPath,
      },
      (response) => {
        response.resume();
        response.on('end', () => resolve(response.statusCode));
      },
    );
    request.on('error', reject);
  });
}

test('removes a stale Unix socket path before binding', async (context) => {
  const socketPath = path.join(
    os.tmpdir(),
    `artifactflow-xlsx-start-${process.pid}-${crypto.randomBytes(6).toString('hex')}.sock`,
  );
  fs.writeFileSync(socketPath, 'stale socket placeholder', { mode: 0o600 });

  const child = spawn(process.execPath, [serverPath], {
    env: {
      ...process.env,
      XLSX_PROCESSOR_SHARED_SECRET: 'test-xlsx-startup-secret-000000000001',
      XLSX_PROCESSOR_SOCKET_PATH: socketPath,
    },
    stdio: 'ignore',
  });

  context.after(async () => {
    if (child.exitCode === null) {
      child.kill('SIGTERM');
      await new Promise((resolve) => child.once('exit', resolve));
    }

    fs.rmSync(socketPath, { force: true });
  });

  await waitForSocket(socketPath, child);
  assert.equal(fs.lstatSync(socketPath).isSocket(), true);
  assert.equal(fs.lstatSync(socketPath).mode & 0o777, 0o666);
});

test('decodes installer-style Base64 secrets before authenticating Laravel health probes', async (context) => {
  const socketPath = path.join(
    os.tmpdir(),
    `artifactflow-xlsx-start-${process.pid}-${crypto.randomBytes(6).toString('hex')}.sock`,
  );
  const secret = crypto.randomBytes(32);
  const child = spawn(process.execPath, [serverPath], {
    env: {
      ...process.env,
      XLSX_PROCESSOR_SHARED_SECRET: `base64:${secret.toString('base64')}`,
      XLSX_PROCESSOR_SOCKET_PATH: socketPath,
    },
    stdio: 'ignore',
  });

  context.after(async () => {
    if (child.exitCode === null) {
      child.kill('SIGTERM');
      await new Promise((resolve) => child.once('exit', resolve));
    }

    fs.rmSync(socketPath, { force: true });
  });

  await waitForSocket(socketPath, child);
  const status = await healthOverSocket(socketPath, secret);

  // A normal host has non-loopback interfaces and therefore returns 503 after
  // authentication; the networkless production container returns 200. A 401
  // would prove the environment value was incorrectly used as literal bytes.
  assert.ok([200, 503].includes(status));
});
