'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const XLSX = require('xlsx');

const {
  INPUT_MEDIA_TYPE,
  MANIFEST_MEDIA_TYPE,
  PROCESSOR_PROFILE,
  ProcessorProtocolError,
  ReplayCache,
  authenticateRequest,
  createProcessorServer,
  healthResponseSignature,
  healthSignature,
  requestSignature,
  responseSignature,
  runProjectionWorker,
  secretFromEnvironment,
} = require('../src/processor-protocol.cjs');

const SHARED_SECRET = 'test-xlsx-processor-shared-secret-0001';

test('normalizes installer-style Base64 secrets to their exact HMAC key bytes', () => {
  const bytes = crypto.randomBytes(32);
  const configured = `base64:${bytes.toString('base64')}`;
  const normalized = secretFromEnvironment(configured);

  assert.ok(Buffer.isBuffer(normalized));
  assert.deepEqual(normalized, bytes);
  assert.equal(
    healthSignature({ nonce: 'a'.repeat(32), secret: normalized, timestamp: '1800000000' }),
    healthSignature({ nonce: 'a'.repeat(32), secret: bytes, timestamp: '1800000000' }),
  );
  expectProtocolError(() => secretFromEnvironment('base64:not canonical'), 'configuration_error');
});

function workbookBytes(hyperlinkTarget = null) {
  const workbook = XLSX.utils.book_new();
  const sheet = XLSX.utils.aoa_to_sheet([
    ['Label', 'Value'],
    ['safe', 42],
  ]);
  if (hyperlinkTarget !== null) {
    sheet.A2.l = { Target: hyperlinkTarget };
  }
  XLSX.utils.book_append_sheet(workbook, sheet, 'Visible');

  return XLSX.write(workbook, {
    bookType: 'xlsx',
    compression: true,
    type: 'buffer',
  });
}

function signedHeaders(body, timestamp, nonce, overrides = {}) {
  const inputSha256 = crypto.createHash('sha256').update(body).digest('hex');
  const base = {
    'content-length': String(body.length),
    'content-type': INPUT_MEDIA_TYPE,
    'x-artifactflow-input-sha256': inputSha256,
    'x-artifactflow-processor-nonce': nonce,
    'x-artifactflow-processor-profile': PROCESSOR_PROFILE,
    'x-artifactflow-processor-timestamp': timestamp,
  };
  const headers = { ...base, ...overrides };
  headers['x-artifactflow-processor-signature'] = requestSignature({
    body,
    contentLength: headers['content-length'],
    contentType: headers['content-type'],
    inputSha256: headers['x-artifactflow-input-sha256'],
    nonce: headers['x-artifactflow-processor-nonce'],
    profile: headers['x-artifactflow-processor-profile'],
    secret: SHARED_SECRET,
    timestamp: headers['x-artifactflow-processor-timestamp'],
  });

  return headers;
}

function expectProtocolError(callback, code) {
  assert.throws(callback, (error) => {
    assert.ok(error instanceof ProcessorProtocolError);
    assert.equal(error.code, code);

    return true;
  });
}

function requestOverSocket(socketPath, body, headers) {
  return new Promise((resolve, reject) => {
    const request = http.request(
      {
        headers,
        method: 'POST',
        path: '/v1/xlsx/manifests',
        socketPath,
      },
      (response) => {
        const chunks = [];
        response.on('data', (chunk) => chunks.push(chunk));
        response.on('end', () =>
          resolve({
            body: Buffer.concat(chunks),
            headers: response.headers,
            statusCode: response.statusCode,
          }),
        );
      },
    );
    request.on('error', reject);
    request.end(body);
  });
}

function healthOverSocket(socketPath, headers = {}) {
  return new Promise((resolve, reject) => {
    const request = http.request(
      {
        headers,
        method: 'GET',
        path: '/health',
        socketPath,
      },
      (response) => {
        const chunks = [];
        response.on('data', (chunk) => chunks.push(chunk));
        response.on('end', () =>
          resolve({
            body: Buffer.concat(chunks),
            headers: response.headers,
            statusCode: response.statusCode,
          }),
        );
      },
    );
    request.on('error', reject);
    request.end();
  });
}

function openRequestOverSocket(socketPath, headers) {
  let settleResponse;
  const response = new Promise((resolve) => {
    settleResponse = resolve;
  });
  const request = http.request(
    {
      headers,
      method: 'POST',
      path: '/v1/xlsx/manifests',
      socketPath,
    },
    (incoming) => {
      const chunks = [];
      incoming.on('data', (chunk) => chunks.push(chunk));
      incoming.on('end', () =>
        settleResponse({
          body: Buffer.concat(chunks),
          headers: incoming.headers,
          statusCode: incoming.statusCode,
        }),
      );
    },
  );

  return { request, response };
}

function deferred() {
  let resolve;
  const promise = new Promise((settle) => {
    resolve = settle;
  });

  return { promise, resolve };
}

test('authenticates the exact request envelope and rejects byte or profile drift', () => {
  const body = workbookBytes();
  const timestamp = '1800000000';
  const nonce = 'a'.repeat(32);
  const headers = signedHeaders(body, timestamp, nonce);

  const authenticated = authenticateRequest({
    body,
    headers,
    nowSeconds: 1_800_000_000,
    replayCache: new ReplayCache(),
    secret: SHARED_SECRET,
  });

  assert.deepEqual(authenticated, {
    inputBytes: body.length,
    inputSha256: headers['x-artifactflow-input-sha256'],
    nonce,
  });

  expectProtocolError(
    () =>
      authenticateRequest({
        body: Buffer.concat([body, Buffer.from('tampered')]),
        headers,
        nowSeconds: 1_800_000_000,
        replayCache: new ReplayCache(),
        secret: SHARED_SECRET,
      }),
    'invalid_request',
  );

  const wrongProfile = signedHeaders(body, timestamp, nonce, {
    'x-artifactflow-processor-profile': 'xlsx-typed-view-v2',
  });
  expectProtocolError(
    () =>
      authenticateRequest({
        body,
        headers: wrongProfile,
        nowSeconds: 1_800_000_000,
        replayCache: new ReplayCache(),
        secret: SHARED_SECRET,
      }),
    'invalid_request',
  );
});

test('rejects replay, stale signatures, media-type parameters, and short secrets', () => {
  const body = workbookBytes();
  const timestamp = '1800000000';
  const nonce = 'b'.repeat(32);
  const replayCache = new ReplayCache();
  const headers = signedHeaders(body, timestamp, nonce);

  authenticateRequest({
    body,
    headers,
    nowSeconds: 1_800_000_000,
    replayCache,
    secret: SHARED_SECRET,
  });

  expectProtocolError(
    () =>
      authenticateRequest({
        body,
        headers,
        nowSeconds: 1_800_000_000,
        replayCache,
        secret: SHARED_SECRET,
      }),
    'replayed_request',
  );
  expectProtocolError(
    () =>
      authenticateRequest({
        body,
        headers: signedHeaders(body, timestamp, 'c'.repeat(32)),
        nowSeconds: 1_800_000_121,
        replayCache: new ReplayCache(),
        secret: SHARED_SECRET,
      }),
    'clock_skew',
  );
  expectProtocolError(
    () =>
      authenticateRequest({
        body,
        headers: signedHeaders(body, timestamp, 'd'.repeat(32), {
          'content-type': `${INPUT_MEDIA_TYPE}; charset=binary`,
        }),
        nowSeconds: 1_800_000_000,
        replayCache: new ReplayCache(),
        secret: SHARED_SECRET,
      }),
    'invalid_request',
  );
  expectProtocolError(
    () =>
      authenticateRequest({
        body,
        headers,
        nowSeconds: 1_800_000_000,
        replayCache: new ReplayCache(),
        secret: 'short',
      }),
    'configuration_error',
  );
});

test('serves only an authenticated, containment-checked health contract', async (context) => {
  const nowSeconds = 1_800_000_000;
  const timestamp = String(nowSeconds);
  const nonce = '9'.repeat(32);
  const socketPath = path.join(
    os.tmpdir(),
    `artifactflow-xlsx-health-${process.pid}-${crypto.randomBytes(6).toString('hex')}.sock`,
  );
  const server = createProcessorServer({
    containmentCheck: () => true,
    nowSeconds: () => nowSeconds,
    secret: SHARED_SECRET,
  });

  context.after(() => new Promise((resolve) => server.close(resolve)));
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(socketPath, resolve);
  });

  const unauthenticated = await healthOverSocket(socketPath);
  assert.equal(unauthenticated.statusCode, 401);

  const headers = {
    'x-artifactflow-processor-nonce': nonce,
    'x-artifactflow-processor-timestamp': timestamp,
  };
  headers['x-artifactflow-processor-signature'] = healthSignature({
    nonce,
    secret: SHARED_SECRET,
    timestamp,
  });
  const healthy = await healthOverSocket(socketPath, headers);
  assert.equal(healthy.statusCode, 200);
  assert.equal(healthy.headers['x-artifactflow-processor-nonce'], nonce);
  assert.equal(
    healthy.headers['x-artifactflow-processor-signature'],
    healthResponseSignature({ nonce, responseBody: healthy.body, secret: SHARED_SECRET }),
  );
  assert.deepEqual(JSON.parse(healthy.body.toString('utf8')), {
    containment: 'network-isolated',
    engine: 'sheetjs-ce',
    engineVersion: '0.20.3',
    profile: 'xlsx-typed-view-v1',
    schema: 'xlsx-processor-response-v1',
    status: 'ok',
  });

  const replayed = await healthOverSocket(socketPath, headers);
  assert.equal(replayed.statusCode, 401);
});

test('health fails closed when runtime network isolation is absent', async (context) => {
  const nowSeconds = 1_800_000_000;
  const timestamp = String(nowSeconds);
  const nonce = '8'.repeat(32);
  const socketPath = path.join(
    os.tmpdir(),
    `artifactflow-xlsx-health-${process.pid}-${crypto.randomBytes(6).toString('hex')}.sock`,
  );
  const server = createProcessorServer({
    containmentCheck: () => false,
    nowSeconds: () => nowSeconds,
    secret: SHARED_SECRET,
  });
  context.after(() => new Promise((resolve) => server.close(resolve)));
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(socketPath, resolve);
  });

  const headers = {
    'x-artifactflow-processor-nonce': nonce,
    'x-artifactflow-processor-timestamp': timestamp,
  };
  headers['x-artifactflow-processor-signature'] = healthSignature({
    nonce,
    secret: SHARED_SECRET,
    timestamp,
  });
  const unhealthy = await healthOverSocket(socketPath, headers);
  assert.equal(unhealthy.statusCode, 503);
});

test('hard-stops a non-responsive projection worker', async () => {
  const fixture = path.join(__dirname, 'fixtures', 'hanging-worker.fixture');

  await assert.rejects(
    runProjectionWorker({
      input: Buffer.from('bounded input'),
      timeoutMs: 100,
      workerPath: fixture,
    }),
    (error) => error instanceof ProcessorProtocolError && error.code === 'processor_timeout',
  );
});

test('serves one authenticated operation over a Unix socket and signs the bounded response', async (context) => {
  const body = workbookBytes();
  const nowSeconds = 1_800_000_000;
  const timestamp = String(nowSeconds);
  const nonce = 'e'.repeat(32);
  const socketPath = path.join(
    os.tmpdir(),
    `artifactflow-xlsx-${process.pid}-${crypto.randomBytes(6).toString('hex')}.sock`,
  );
  const server = createProcessorServer({
    nowSeconds: () => nowSeconds,
    secret: SHARED_SECRET,
  });

  context.after(() => new Promise((resolve) => server.close(resolve)));
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(socketPath, resolve);
  });

  const response = await requestOverSocket(socketPath, body, signedHeaders(body, timestamp, nonce));

  assert.equal(response.statusCode, 200);
  assert.equal(response.headers['cache-control'], 'no-store');
  assert.equal(response.headers['content-type'], MANIFEST_MEDIA_TYPE);
  assert.equal(response.headers['x-content-type-options'], 'nosniff');
  assert.equal(response.headers['x-artifactflow-processor-nonce'], nonce);
  assert.equal(
    response.headers['x-artifactflow-input-sha256'],
    crypto.createHash('sha256').update(body).digest('hex'),
  );
  assert.equal(
    response.headers['x-artifactflow-response-sha256'],
    crypto.createHash('sha256').update(response.body).digest('hex'),
  );
  assert.equal(Number(response.headers['content-length']), response.body.length);

  const envelope = JSON.parse(response.body.toString('utf8'));
  assert.deepEqual(Object.keys(envelope), [
    'schema',
    'profile',
    'engine',
    'input',
    'package',
    'manifest',
  ]);
  assert.equal(envelope.schema, 'xlsx-processor-response-v1');
  assert.equal(envelope.profile, PROCESSOR_PROFILE);
  assert.deepEqual(envelope.engine, { name: 'sheetjs-ce', version: '0.20.3' });
  assert.equal(envelope.input.bytes, body.length);
  assert.equal(envelope.input.sha256, response.headers['x-artifactflow-input-sha256']);
  assert.ok(envelope.package.entryCount > 0);
  assert.ok(envelope.package.expandedBytes > body.length);
  assert.equal(envelope.manifest.profile, PROCESSOR_PROFILE);
  assert.equal(envelope.manifest.workbook.visibleSheetCount, 1);

  assert.equal(
    response.headers['x-artifactflow-processor-signature'],
    responseSignature({
      inputBytes: body.length,
      inputSha256: envelope.input.sha256,
      nonce,
      responseBody: response.body,
      secret: SHARED_SECRET,
    }),
  );

  const replayed = await requestOverSocket(socketPath, body, signedHeaders(body, timestamp, nonce));
  assert.equal(replayed.statusCode, 401);
  assert.deepEqual(JSON.parse(replayed.body.toString('utf8')), { error: 'unauthenticated' });
  assert.equal(replayed.headers['x-artifactflow-processor-signature'], undefined);
});

test('rejects authority-form mailto before signing and releases the processor slot', async (context) => {
  const rejectedBody = workbookBytes('mailto://example.com/person');
  const acceptedBody = workbookBytes('mailto:person@example.com');
  const nowSeconds = 1_800_000_000;
  const timestamp = String(nowSeconds);
  const socketPath = path.join(
    os.tmpdir(),
    `artifactflow-xlsx-mailto-${process.pid}-${crypto.randomBytes(6).toString('hex')}.sock`,
  );
  const diagnostics = [];
  const server = createProcessorServer({
    diagnosticLogger: (message) => diagnostics.push(message),
    nowSeconds: () => nowSeconds,
    secret: SHARED_SECRET,
  });

  context.after(() => new Promise((resolve) => server.close(resolve)));
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(socketPath, resolve);
  });

  const rejected = await requestOverSocket(
    socketPath,
    rejectedBody,
    signedHeaders(rejectedBody, timestamp, '6'.repeat(32)),
  );
  assert.equal(rejected.statusCode, 422);
  assert.deepEqual(JSON.parse(rejected.body.toString('utf8')), { error: 'xlsx_rejected' });
  assert.equal(rejected.headers['x-artifactflow-processor-signature'], undefined);
  assert.equal(diagnostics.length, 1);
  assert.match(diagnostics[0], /^ArtifactFlow XLSX rejection [a-f0-9]{12}$/u);

  const accepted = await requestOverSocket(
    socketPath,
    acceptedBody,
    signedHeaders(acceptedBody, timestamp, '7'.repeat(32)),
  );
  assert.equal(accepted.statusCode, 200);
  assert.ok(accepted.headers['x-artifactflow-processor-signature']);
});

test('admits at most one request before either body can reach the worker', async (context) => {
  const body = workbookBytes();
  const nowSeconds = 1_800_000_000;
  const socketPath = path.join(
    os.tmpdir(),
    `artifactflow-xlsx-${process.pid}-${crypto.randomBytes(6).toString('hex')}.sock`,
  );
  const workerStarted = deferred();
  const releaseWorker = deferred();
  let workerCalls = 0;
  const server = createProcessorServer({
    nowSeconds: () => nowSeconds,
    secret: SHARED_SECRET,
    workerRunner: async () => {
      workerCalls += 1;
      workerStarted.resolve();
      await releaseWorker.promise;

      return {
        manifest: {
          profile: PROCESSOR_PROFILE,
          workbook: {
            visibleSheetCount: 1,
            omittedHiddenSheetCount: 0,
            formulaCount: 0,
            linkCount: 0,
          },
          sheets: [],
          searchText: '',
        },
        package: { entryCount: 1, expandedBytes: 1 },
      };
    },
  });

  context.after(() => new Promise((resolve) => server.close(resolve)));
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(socketPath, resolve);
  });

  const first = openRequestOverSocket(
    socketPath,
    signedHeaders(body, String(nowSeconds), 'f'.repeat(32)),
  );
  first.request.write(body.subarray(0, 1));
  await new Promise((resolve) => setImmediate(resolve));

  const secondResponse = requestOverSocket(
    socketPath,
    body,
    signedHeaders(body, String(nowSeconds), '1'.repeat(32)),
  );
  await Promise.race([secondResponse, workerStarted.promise]);
  releaseWorker.resolve();
  first.request.end(body.subarray(1));

  const [firstResult, second] = await Promise.all([first.response, secondResponse]);

  assert.equal(second.statusCode, 503);
  assert.deepEqual(JSON.parse(second.body.toString('utf8')), { error: 'service_unavailable' });
  assert.equal(workerCalls, 1);
  assert.equal(firstResult.statusCode, 200);
});
