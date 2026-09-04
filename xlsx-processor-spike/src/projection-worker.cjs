'use strict';

const crypto = require('node:crypto');
const { projectXlsxWithFacts } = require('./project-xlsx.cjs');

const MAX_INPUT_BYTES = 16 * 1024 * 1024;
const chunks = [];
let inputBytes = 0;
let rejected = false;

function respond(value) {
  process.stdout.write(JSON.stringify(value));
}

function reject(code, message) {
  const diagnostic = crypto
    .createHash('sha256')
    .update(`${code}\n${message}`)
    .digest('hex')
    .slice(0, 12);
  respond({ code, diagnostic, ok: false });
}

process.stdin.on('data', (chunk) => {
  inputBytes += chunk.length;

  if (inputBytes > MAX_INPUT_BYTES) {
    rejected = true;
    process.stdin.pause();
    reject('input_size_limit_exceeded', 'The XLSX worker input exceeds its byte limit.');
    process.exitCode = 2;

    return;
  }

  chunks.push(chunk);
});

process.stdin.on('end', () => {
  if (rejected) {
    return;
  }

  try {
    const result = projectXlsxWithFacts(Buffer.concat(chunks, inputBytes));
    respond({ manifest: result.manifest, ok: true, package: result.package });
  } catch (error) {
    const code =
      typeof error?.code === 'string' && /^[a-z_]{1,64}$/u.test(error.code)
        ? error.code
        : 'malformed_xlsx';
    reject(code, typeof error?.message === 'string' ? error.message : 'The workbook is malformed.');
    process.exitCode = 2;
  }
});

process.stdin.on('error', () => {
  process.exitCode = 3;
});
