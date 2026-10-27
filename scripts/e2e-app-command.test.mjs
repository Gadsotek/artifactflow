import assert from 'node:assert/strict';
import childProcess from 'node:child_process';
import { syncBuiltinESMExports } from 'node:module';
import { test } from 'node:test';
import { runAppCommand } from '../tests/e2e/helpers/app-command.ts';

function mockCommand(t, run) {
  t.mock.method(childProcess, 'execFileSync', run);
  syncBuiltinESMExports();
  const originalTarget = process.env.E2E_APP_COMMAND_TARGET;
  delete process.env.E2E_APP_COMMAND_TARGET;
  t.after(() => {
    t.mock.restoreAll();
    syncBuiltinESMExports();
    if (originalTarget === undefined) delete process.env.E2E_APP_COMMAND_TARGET;
    else process.env.E2E_APP_COMMAND_TARGET = originalTarget;
  });
}

test('e2e setup captures output privately and uses the isolated Make target', (t) => {
  mockCommand(t, (program, args, options) => {
    assert.equal(program, 'make');
    assert.deepEqual(args, ['run-e2e-app-cmd', 'APP_CMD=fixture command']);
    assert.deepEqual(options.stdio, ['ignore', 'pipe', 'pipe']);
    assert.ok(options.cwd.endsWith('/'));
    return Buffer.from('private fixture output');
  });
  assert.equal(runAppCommand('fixture command', 'Setup failed'), undefined);
});

for (const [name, fields, expected] of [
  [
    'make child failure',
    {
      status: 2,
      stderr: Buffer.from('make[2]: *** [run-e2e-app-cmd] Error 139\n'),
    },
    'child exit=139',
  ],
  [
    'GNU Make child failure',
    {
      status: 2,
      stderr: Buffer.from('make[2]: *** [Makefile:184: run-e2e-app-cmd] Error 137\n'),
    },
    'child exit=137',
  ],
  [
    'database failure',
    {
      status: 2,
      stdout: Buffer.from('SQLSTATE[08006]: private connection information'),
    },
    'SQLSTATE=08006',
  ],
  ['process signal', { status: null, signal: 'SIGTERM' }, 'signal=SIGTERM'],
  ['spawn failure', { status: null, code: 'ENOENT' }, 'spawn=ENOENT'],
  ['buffer exhaustion', { status: null, code: 'ENOBUFS' }, 'spawn=ENOBUFS'],
  [
    'PHP memory failure',
    {
      status: 2,
      stderr: Buffer.from('PHP Fatal error: Allowed memory size of 134217728 bytes exhausted'),
    },
    'PHP memory exhausted',
  ],
  [
    'Docker exec failure',
    {
      status: 2,
      stderr: Buffer.from('OCI runtime exec failed: private details'),
    },
    'container exec failed',
  ],
]) {
  test(`e2e setup preserves safe diagnostics for ${name}`, (t) => {
    mockCommand(t, () => {
      throw Object.assign(new Error('private command and password'), fields);
    });
    assert.throws(
      () => runAppCommand('private command', 'Schema preflight failed'),
      (error) => {
        assert.ok(error.message.includes(expected), error.message);
        assert.ok(!error.message.includes('private'));
        assert.deepEqual(Object.keys(error), []);
        assert.equal(error.cause, undefined);
        return true;
      },
    );
  });
}

test('unknown errors never leak command arguments, output, or arbitrary metadata', (t) => {
  mockCommand(t, () => {
    throw Object.assign(new Error('password=private'), {
      status: 'private',
      signal: 'private',
      code: 'private',
      stdout: Buffer.from('private'),
      stderr: Buffer.from('private'),
      output: [null, Buffer.from('private'), Buffer.from('private')],
    });
  });
  assert.throws(
    () => runAppCommand('password=private', 'Schema preflight failed'),
    (error) => {
      assert.equal(error.message, 'Schema preflight failed (make exit=unknown).');
      assert.deepEqual(Object.keys(error), []);
      assert.equal(error.cause, undefined);
      return true;
    },
  );
});

test('unapproved Make targets are rejected before spawning', (t) => {
  mockCommand(t, () => assert.fail('Must not execute an unapproved target.'));
  process.env.E2E_APP_COMMAND_TARGET = 'migrate';
  assert.throws(() => runAppCommand('fixture command', 'Setup failed'), /Unsupported e2e/);
});
