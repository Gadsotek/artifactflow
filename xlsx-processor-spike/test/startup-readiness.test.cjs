'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { ProcessorProtocolError } = require('../src/processor-protocol.cjs');
const {
  STARTUP_PROBE_TIMEOUT_MS,
  verifyProjectionWorkerReadiness,
} = require('../src/startup-readiness.cjs');

test('startup readiness requires the bounded worker to reject the probe', async () => {
  let observedInput;
  let observedTimeout;

  await verifyProjectionWorkerReadiness({
    workerRunner: async ({ input, timeoutMs }) => {
      observedInput = input;
      observedTimeout = timeoutMs;
      throw new ProcessorProtocolError('xlsx_rejected');
    },
  });

  assert.equal(Buffer.isBuffer(observedInput), true);
  assert.ok(observedInput.length > 0);
  assert.equal(observedTimeout, STARTUP_PROBE_TIMEOUT_MS);
});

test('startup readiness fails closed on worker failure or probe acceptance', async () => {
  await assert.rejects(
    verifyProjectionWorkerReadiness({
      workerRunner: async () => {
        throw new ProcessorProtocolError('processor_timeout');
      },
    }),
    (error) => error instanceof ProcessorProtocolError && error.code === 'processor_timeout',
  );

  await assert.rejects(
    verifyProjectionWorkerReadiness({ workerRunner: async () => ({}) }),
    (error) => error instanceof ProcessorProtocolError && error.code === 'processor_protocol_error',
  );
});
