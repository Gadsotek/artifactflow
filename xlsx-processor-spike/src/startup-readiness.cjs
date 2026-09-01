'use strict';

const {
  ProcessorProtocolError,
  runProjectionWorker,
} = require('./processor-protocol.cjs');

const STARTUP_PROBE_TIMEOUT_MS = 30_000;
const STARTUP_PROBE_BYTES = Buffer.from('artifactflow-xlsx-startup-probe-v1', 'ascii');

async function verifyProjectionWorkerReadiness({
  timeoutMs = STARTUP_PROBE_TIMEOUT_MS,
  workerRunner = runProjectionWorker,
} = {}) {
  try {
    await workerRunner({ input: STARTUP_PROBE_BYTES, timeoutMs });
  } catch (error) {
    if (error instanceof ProcessorProtocolError && error.code === 'xlsx_rejected') {
      return;
    }

    throw error;
  }

  throw new ProcessorProtocolError('processor_protocol_error');
}

module.exports = {
  STARTUP_PROBE_TIMEOUT_MS,
  verifyProjectionWorkerReadiness,
};
