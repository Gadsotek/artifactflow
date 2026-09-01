'use strict';

const crypto = require('node:crypto');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');

const INPUT_MEDIA_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
const MANIFEST_MEDIA_TYPE = 'application/vnd.artifactflow.xlsx-manifest+json; charset=utf-8';
const PROCESSOR_PROFILE = 'xlsx-typed-view-v1';
const RESPONSE_SCHEMA = 'xlsx-processor-response-v1';
const ENGINE_NAME = 'sheetjs-ce';
const ENGINE_VERSION = '0.20.3';
const REQUEST_CONTEXT = 'artifactflow-xlsx-processor-request-v1';
const RESPONSE_CONTEXT = 'artifactflow-xlsx-processor-response-v1';
const HEALTH_CONTEXT = 'artifactflow-xlsx-processor-health-v1';
const HEALTH_RESPONSE_CONTEXT = 'artifactflow-xlsx-processor-health-response-v1';
const MAX_INPUT_BYTES = 16 * 1024 * 1024;
const MAX_RESPONSE_BYTES = 17 * 1024 * 1024;
const MAX_CLOCK_SKEW_SECONDS = 120;
const DEFAULT_TIMEOUT_MS = 15_000;

class ProcessorProtocolError extends Error {
  constructor(code, diagnosticCode = null) {
    super('The XLSX processor request failed.');
    this.code = code;
    this.diagnosticCode = diagnosticCode;
    this.name = 'ProcessorProtocolError';
  }
}

class ReplayCache {
  constructor() {
    this.nonces = new Map();
  }

  claim(nonce, timestamp, nowSeconds, maxClockSkewSeconds) {
    for (const [candidate, expiresAt] of this.nonces) {
      if (expiresAt < nowSeconds) {
        this.nonces.delete(candidate);
      }
    }

    if (this.nonces.has(nonce)) {
      return false;
    }

    this.nonces.set(nonce, timestamp + maxClockSkewSeconds);

    return true;
  }
}

function fail(code) {
  throw new ProcessorProtocolError(code);
}

function secretFromEnvironment(value) {
  if (typeof value !== 'string') {
    fail('configuration_error');
  }

  const secret = value.trim();

  if (!secret.startsWith('base64:')) {
    return secret;
  }

  const encoded = secret.slice(7);

  if (!/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/u.test(encoded)) {
    fail('configuration_error');
  }

  const decoded = Buffer.from(encoded, 'base64');

  if (decoded.toString('base64') !== encoded) {
    fail('configuration_error');
  }

  return decoded;
}

function validateSecret(secret) {
  const length = Buffer.isBuffer(secret)
    ? secret.length
    : typeof secret === 'string'
      ? Buffer.byteLength(secret, 'utf8')
      : 0;

  if (length < 32) {
    fail('configuration_error');
  }
}

function sha256(bytes) {
  return crypto.createHash('sha256').update(bytes).digest('hex');
}

function hmac(lines, secret) {
  return crypto.createHmac('sha256', secret).update(lines.join('\n')).digest('hex');
}

function requestSignature({
  body,
  contentLength,
  contentType,
  inputSha256,
  nonce,
  profile,
  secret,
  timestamp,
}) {
  return hmac(
    [REQUEST_CONTEXT, timestamp, nonce, profile, contentType, contentLength, inputSha256],
    secret,
  );
}

function responseSignature({ inputBytes, inputSha256, nonce, responseBody, secret }) {
  return hmac(
    [
      RESPONSE_CONTEXT,
      nonce,
      String(inputBytes),
      inputSha256,
      MANIFEST_MEDIA_TYPE,
      String(responseBody.length),
      sha256(responseBody),
      RESPONSE_SCHEMA,
      PROCESSOR_PROFILE,
      ENGINE_NAME,
      ENGINE_VERSION,
    ],
    secret,
  );
}

function healthSignature({ nonce, secret, timestamp }) {
  return hmac([HEALTH_CONTEXT, timestamp, nonce, 'GET', '/health'], secret);
}

function healthResponseSignature({ nonce, responseBody, secret }) {
  return hmac(
    [
      HEALTH_RESPONSE_CONTEXT,
      nonce,
      'application/json',
      String(responseBody.length),
      sha256(responseBody),
    ],
    secret,
  );
}

function headerValue(headers, name) {
  const value = headers[name];

  return typeof value === 'string' ? value : '';
}

function constantTimeHexEqual(expected, candidate) {
  if (!/^[a-f0-9]{64}$/u.test(candidate)) {
    return false;
  }

  return crypto.timingSafeEqual(Buffer.from(expected, 'hex'), Buffer.from(candidate, 'hex'));
}

function authenticateRequest({
  body,
  headers,
  nowSeconds = Math.floor(Date.now() / 1_000),
  replayCache,
  secret,
  maxClockSkewSeconds = MAX_CLOCK_SKEW_SECONDS,
}) {
  validateSecret(secret);

  if (
    !Buffer.isBuffer(body) ||
    body.length === 0 ||
    body.length > MAX_INPUT_BYTES ||
    !Number.isSafeInteger(nowSeconds) ||
    !Number.isSafeInteger(maxClockSkewSeconds) ||
    maxClockSkewSeconds < 1 ||
    maxClockSkewSeconds > 300 ||
    !(replayCache instanceof ReplayCache)
  ) {
    fail('invalid_request');
  }

  const contentLength = headerValue(headers, 'content-length');
  const contentType = headerValue(headers, 'content-type');
  const inputSha256 = headerValue(headers, 'x-artifactflow-input-sha256');
  const nonce = headerValue(headers, 'x-artifactflow-processor-nonce');
  const profile = headerValue(headers, 'x-artifactflow-processor-profile');
  const signature = headerValue(headers, 'x-artifactflow-processor-signature');
  const timestamp = headerValue(headers, 'x-artifactflow-processor-timestamp');

  if (
    !/^[1-9][0-9]{0,9}$/u.test(timestamp) ||
    !/^[1-9][0-9]{0,7}$/u.test(contentLength) ||
    !/^[a-f0-9]{32}$/u.test(nonce) ||
    !/^[a-f0-9]{64}$/u.test(inputSha256) ||
    contentType !== INPUT_MEDIA_TYPE ||
    profile !== PROCESSOR_PROFILE ||
    Number(contentLength) !== body.length ||
    inputSha256 !== sha256(body)
  ) {
    fail('invalid_request');
  }

  const expected = requestSignature({
    body,
    contentLength,
    contentType,
    inputSha256,
    nonce,
    profile,
    secret,
    timestamp,
  });

  if (!constantTimeHexEqual(expected, signature)) {
    fail('unauthenticated');
  }

  const timestampSeconds = Number(timestamp);

  if (Math.abs(nowSeconds - timestampSeconds) > maxClockSkewSeconds) {
    fail('clock_skew');
  }

  if (!replayCache.claim(nonce, timestampSeconds, nowSeconds, maxClockSkewSeconds)) {
    fail('replayed_request');
  }

  return { inputBytes: body.length, inputSha256, nonce };
}

function authenticateHealthRequest({
  headers,
  nowSeconds = Math.floor(Date.now() / 1_000),
  replayCache,
  secret,
  maxClockSkewSeconds = MAX_CLOCK_SKEW_SECONDS,
}) {
  validateSecret(secret);
  const nonce = headerValue(headers, 'x-artifactflow-processor-nonce');
  const signature = headerValue(headers, 'x-artifactflow-processor-signature');
  const timestamp = headerValue(headers, 'x-artifactflow-processor-timestamp');

  if (
    !/^[1-9][0-9]{0,9}$/u.test(timestamp) ||
    !/^[a-f0-9]{32}$/u.test(nonce) ||
    !/^[a-f0-9]{64}$/u.test(signature) ||
    !Number.isSafeInteger(nowSeconds) ||
    !(replayCache instanceof ReplayCache)
  ) {
    fail('unauthenticated');
  }

  if (!constantTimeHexEqual(healthSignature({ nonce, secret, timestamp }), signature)) {
    fail('unauthenticated');
  }

  const timestampSeconds = Number(timestamp);
  if (Math.abs(nowSeconds - timestampSeconds) > maxClockSkewSeconds) {
    fail('clock_skew');
  }
  if (!replayCache.claim(nonce, timestampSeconds, nowSeconds, maxClockSkewSeconds)) {
    fail('replayed_request');
  }

  return { nonce };
}

function hasOnlyLoopbackInterfaces() {
  const interfaces = Object.values(os.networkInterfaces()).flatMap((addresses) => addresses ?? []);

  return interfaces.length > 0 && interfaces.every((address) => address.internal === true);
}

function terminateWorker(child) {
  if (child.pid === undefined) {
    return;
  }

  try {
    if (process.platform !== 'win32') {
      process.kill(-child.pid, 'SIGKILL');
    } else {
      child.kill('SIGKILL');
    }
  } catch {
    child.kill('SIGKILL');
  }
}

function validateWorkerResult(value) {
  if (
    value === null ||
    typeof value !== 'object' ||
    Array.isArray(value) ||
    typeof value.ok !== 'boolean'
  ) {
    fail('processor_protocol_error');
  }

  if (value.ok === false) {
    if (
      Object.keys(value).sort().join(',') !== 'code,diagnostic,ok' ||
      typeof value.code !== 'string' ||
      !/^[a-z_]{1,64}$/u.test(value.code) ||
      typeof value.diagnostic !== 'string' ||
      !/^[a-f0-9]{12}$/u.test(value.diagnostic)
    ) {
      fail('processor_protocol_error');
    }

    throw new ProcessorProtocolError('xlsx_rejected', value.diagnostic);
  }

  if (
    Object.keys(value).sort().join(',') !== 'manifest,ok,package' ||
    value.package === null ||
    typeof value.package !== 'object' ||
    !Number.isSafeInteger(value.package.entryCount) ||
    value.package.entryCount < 1 ||
    !Number.isSafeInteger(value.package.expandedBytes) ||
    value.package.expandedBytes < 1 ||
    value.manifest === null ||
    typeof value.manifest !== 'object' ||
    Array.isArray(value.manifest) ||
    value.manifest.profile !== PROCESSOR_PROFILE
  ) {
    fail('processor_protocol_error');
  }

  return { manifest: value.manifest, package: value.package };
}

function runProjectionWorker({
  input,
  maxOutputBytes = MAX_RESPONSE_BYTES,
  timeoutMs = DEFAULT_TIMEOUT_MS,
  workerPath = path.join(__dirname, 'projection-worker.cjs'),
}) {
  if (
    !Buffer.isBuffer(input) ||
    input.length === 0 ||
    input.length > MAX_INPUT_BYTES ||
    !Number.isSafeInteger(timeoutMs) ||
    timeoutMs < 1 ||
    timeoutMs > 60_000 ||
    !Number.isSafeInteger(maxOutputBytes) ||
    maxOutputBytes < 1 ||
    maxOutputBytes > MAX_RESPONSE_BYTES
  ) {
    return Promise.reject(new ProcessorProtocolError('invalid_request'));
  }

  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [workerPath], {
      cwd: __dirname,
      detached: process.platform !== 'win32',
      env: { NODE_ENV: 'production' },
      stdio: ['pipe', 'pipe', 'pipe'],
    });
    const stdout = [];
    let stdoutBytes = 0;
    let settled = false;

    function finishError(code) {
      if (settled) {
        return;
      }

      settled = true;
      reject(new ProcessorProtocolError(code));
    }

    const timer = setTimeout(() => {
      terminateWorker(child);
      finishError('processor_timeout');
    }, timeoutMs);

    child.stdout.on('data', (chunk) => {
      stdoutBytes += chunk.length;

      if (stdoutBytes > maxOutputBytes) {
        terminateWorker(child);
        finishError('processor_output_limit_exceeded');

        return;
      }

      stdout.push(chunk);
    });
    child.stderr.on('data', () => {});
    child.on('error', () => finishError('processor_unavailable'));
    child.on('close', (code, signal) => {
      clearTimeout(timer);

      if (settled) {
        return;
      }

      if (signal !== null || ![0, 2].includes(code)) {
        finishError('processor_unavailable');

        return;
      }

      let decoded;

      try {
        decoded = JSON.parse(Buffer.concat(stdout, stdoutBytes).toString('utf8'));
      } catch {
        finishError('processor_protocol_error');

        return;
      }

      try {
        const result = validateWorkerResult(decoded);
        settled = true;
        resolve(result);
      } catch (error) {
        settled = true;
        reject(error);
      }
    });
    child.stdin.on('error', () => {});
    child.stdin.end(input);
  });
}

function successfulResponse({ manifest, packageFacts, request, secret }) {
  const envelope = {
    schema: RESPONSE_SCHEMA,
    profile: PROCESSOR_PROFILE,
    engine: { name: ENGINE_NAME, version: ENGINE_VERSION },
    input: { bytes: request.inputBytes, sha256: request.inputSha256 },
    package: packageFacts,
    manifest,
  };
  const body = Buffer.from(JSON.stringify(envelope), 'utf8');

  if (body.length > MAX_RESPONSE_BYTES) {
    fail('processor_output_limit_exceeded');
  }

  return {
    body,
    headers: {
      'Cache-Control': 'no-store',
      'Content-Length': String(body.length),
      'Content-Type': MANIFEST_MEDIA_TYPE,
      'X-ArtifactFlow-Input-Bytes': String(request.inputBytes),
      'X-ArtifactFlow-Input-SHA256': request.inputSha256,
      'X-ArtifactFlow-Processor-Engine': ENGINE_NAME,
      'X-ArtifactFlow-Processor-Engine-Version': ENGINE_VERSION,
      'X-ArtifactFlow-Processor-Nonce': request.nonce,
      'X-ArtifactFlow-Processor-Profile': PROCESSOR_PROFILE,
      'X-ArtifactFlow-Processor-Schema': RESPONSE_SCHEMA,
      'X-ArtifactFlow-Response-SHA256': sha256(body),
      'X-ArtifactFlow-Processor-Signature': responseSignature({
        inputBytes: request.inputBytes,
        inputSha256: request.inputSha256,
        nonce: request.nonce,
        responseBody: body,
        secret,
      }),
      'X-Content-Type-Options': 'nosniff',
    },
  };
}

function errorStatus(code) {
  if (['clock_skew', 'replayed_request', 'unauthenticated'].includes(code)) {
    return 401;
  }

  if (['invalid_request', 'xlsx_rejected'].includes(code)) {
    return 422;
  }

  return 503;
}

function errorBody(code) {
  if (['clock_skew', 'replayed_request', 'unauthenticated'].includes(code)) {
    return { error: 'unauthenticated' };
  }

  if (['invalid_request', 'xlsx_rejected'].includes(code)) {
    return { error: 'xlsx_rejected' };
  }

  return { error: 'service_unavailable' };
}

function sendJson(response, statusCode, value, headers = {}) {
  const body = Buffer.from(JSON.stringify(value), 'utf8');
  response.writeHead(statusCode, {
    'Cache-Control': 'no-store',
    'Content-Length': String(body.length),
    'Content-Type': 'application/json; charset=utf-8',
    'X-Content-Type-Options': 'nosniff',
    ...headers,
  });
  response.end(body);
}

function readRequestBody(request) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let bytes = 0;

    request.on('data', (chunk) => {
      bytes += chunk.length;

      if (bytes > MAX_INPUT_BYTES) {
        reject(new ProcessorProtocolError('invalid_request'));
        request.destroy();

        return;
      }

      chunks.push(chunk);
    });
    request.on('end', () => resolve(Buffer.concat(chunks, bytes)));
    request.on('aborted', () => reject(new ProcessorProtocolError('invalid_request')));
    request.on('error', () => reject(new ProcessorProtocolError('invalid_request')));
  });
}

function createProcessorServer({
  containmentCheck = hasOnlyLoopbackInterfaces,
  diagnosticLogger = console.error,
  nowSeconds = () => Math.floor(Date.now() / 1_000),
  replayCache = new ReplayCache(),
  secret,
  workerRunner = runProjectionWorker,
}) {
  validateSecret(secret);
  if (typeof diagnosticLogger !== 'function') {
    fail('configuration_error');
  }
  let processing = false;

  const server = http.createServer(async (request, response) => {
    if (request.method === 'GET' && request.url === '/health') {
      request.resume();
      try {
        const authenticated = authenticateHealthRequest({
          headers: request.headers,
          nowSeconds: nowSeconds(),
          replayCache,
          secret,
        });
        if (!containmentCheck()) {
          sendJson(response, 503, { error: 'service_unavailable' });

          return;
        }
        const health = {
          containment: 'network-isolated',
          engine: ENGINE_NAME,
          engineVersion: ENGINE_VERSION,
          profile: PROCESSOR_PROFILE,
          schema: RESPONSE_SCHEMA,
          status: 'ok',
        };
        const healthBody = Buffer.from(JSON.stringify(health), 'utf8');
        sendJson(response, 200, health, {
          'X-ArtifactFlow-Processor-Nonce': authenticated.nonce,
          'X-ArtifactFlow-Processor-Signature': healthResponseSignature({
            nonce: authenticated.nonce,
            responseBody: healthBody,
            secret,
          }),
        });
      } catch {
        sendJson(response, 401, { error: 'unauthenticated' });
      }

      return;
    }

    if (request.method !== 'POST' || request.url !== '/v1/xlsx/manifests') {
      request.resume();
      sendJson(response, 404, { error: 'not_found' });

      return;
    }

    if (
      processing ||
      request.headers['transfer-encoding'] !== undefined ||
      !/^[1-9][0-9]{0,7}$/u.test(headerValue(request.headers, 'content-length')) ||
      Number(request.headers['content-length']) > MAX_INPUT_BYTES
    ) {
      request.resume();
      sendJson(response, processing ? 503 : 422, {
        error: processing ? 'service_unavailable' : 'xlsx_rejected',
      });

      return;
    }

    processing = true;

    try {
      const body = await readRequestBody(request);
      const authenticated = authenticateRequest({
        body,
        headers: request.headers,
        nowSeconds: nowSeconds(),
        replayCache,
        secret,
      });
      const projected = await workerRunner({ input: body });
      const success = successfulResponse({
        manifest: projected.manifest,
        packageFacts: projected.package,
        request: authenticated,
        secret,
      });
      response.writeHead(200, success.headers);
      response.end(success.body);
    } catch (error) {
      const code = error instanceof ProcessorProtocolError ? error.code : 'processor_unavailable';
      if (
        error instanceof ProcessorProtocolError &&
        code === 'xlsx_rejected' &&
        typeof error.diagnosticCode === 'string' &&
        /^[a-f0-9]{12}$/u.test(error.diagnosticCode)
      ) {
        diagnosticLogger(`ArtifactFlow XLSX rejection ${error.diagnosticCode}`);
      }
      sendJson(response, errorStatus(code), errorBody(code));
    } finally {
      processing = false;
    }
  });

  server.headersTimeout = 5_000;
  server.requestTimeout = 20_000;
  server.keepAliveTimeout = 1_000;

  return server;
}

module.exports = {
  INPUT_MEDIA_TYPE,
  MANIFEST_MEDIA_TYPE,
  PROCESSOR_PROFILE,
  ProcessorProtocolError,
  ReplayCache,
  authenticateRequest,
  createProcessorServer,
  healthSignature,
  healthResponseSignature,
  requestSignature,
  responseSignature,
  runProjectionWorker,
  secretFromEnvironment,
};
