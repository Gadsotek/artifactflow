'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { createProcessorServer, secretFromEnvironment } = require('./processor-protocol.cjs');
const { verifyProjectionWorkerReadiness } = require('./startup-readiness.cjs');

const socketPath = process.env.XLSX_PROCESSOR_SOCKET_PATH ?? '';
const secret = secretFromEnvironment(process.env.XLSX_PROCESSOR_SHARED_SECRET ?? '');

if (
  !path.isAbsolute(socketPath) ||
  !socketPath.endsWith('.sock') ||
  socketPath.includes('\u0000') ||
  path.normalize(socketPath) !== socketPath
) {
  throw new Error('XLSX processor Unix socket path is not configured.');
}

async function start() {
  // Do not advertise readiness until a cold child process has loaded SheetJS
  // and exercised the bounded rejection path. The deliberately invalid probe
  // must be rejected; acceptance is itself a startup failure.
  await verifyProjectionWorkerReadiness();

  // Unix-domain socket files can survive an abrupt container stop. The socket
  // directory is a processor-owned volume, and rmSync without `recursive` cannot
  // remove a directory if the configured path is wrong.
  fs.rmSync(socketPath, { force: true });

  const server = createProcessorServer({ secret });

  server.listen(socketPath, () => {
    fs.chmodSync(socketPath, 0o666);
  });

  for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => server.close(() => process.exit(0)));
  }
}

start().catch(() => {
  console.error('XLSX processor startup self-test failed.');
  process.exitCode = 1;
});
