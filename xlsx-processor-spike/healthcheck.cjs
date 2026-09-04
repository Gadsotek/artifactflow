'use strict';

const crypto = require('node:crypto');
const http = require('node:http');
const path = require('node:path');
const {
  healthResponseSignature,
  healthSignature,
  secretFromEnvironment,
} = require('./src/processor-protocol.cjs');

const socketPath = process.env.XLSX_PROCESSOR_SOCKET_PATH ?? '';
const secret = secretFromEnvironment(process.env.XLSX_PROCESSOR_SHARED_SECRET ?? '');

if (!path.isAbsolute(socketPath) || !socketPath.endsWith('.sock')) {
  process.exit(1);
}

const timestamp = String(Math.floor(Date.now() / 1_000));
const nonce = crypto.randomBytes(16).toString('hex');
const request = http.get(
  {
    headers: {
      'X-ArtifactFlow-Processor-Nonce': nonce,
      'X-ArtifactFlow-Processor-Signature': healthSignature({ nonce, secret, timestamp }),
      'X-ArtifactFlow-Processor-Timestamp': timestamp,
    },
    path: '/health',
    socketPath,
    timeout: 2_000,
  },
  (response) => {
    let body = '';

    response.setEncoding('utf8');
    response.on('data', (chunk) => {
      body += chunk;

      if (body.length > 512) {
        request.destroy();
      }
    });
    response.on('end', () => {
      try {
        const decoded = JSON.parse(body);
        const responseBody = Buffer.from(body, 'utf8');
        const responseNonce = response.headers['x-artifactflow-processor-nonce'] ?? '';
        const responseSignature = response.headers['x-artifactflow-processor-signature'] ?? '';
        process.exit(
          response.statusCode === 200 &&
            responseNonce === nonce &&
            responseSignature === healthResponseSignature({ nonce, responseBody, secret }) &&
            decoded.engine === 'sheetjs-ce' &&
            decoded.engineVersion === '0.20.3' &&
            decoded.profile === 'xlsx-typed-view-v1' &&
            decoded.schema === 'xlsx-processor-response-v1' &&
            decoded.containment === 'network-isolated' &&
            decoded.status === 'ok'
            ? 0
            : 1,
        );
      } catch {
        process.exit(1);
      }
    });
  },
);

request.on('error', () => process.exit(1));
request.on('timeout', () => request.destroy());
