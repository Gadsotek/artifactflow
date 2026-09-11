import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, writeFileSync, unlinkSync, rmdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const self = fileURLToPath(import.meta.url);
const root = dirname(dirname(self));
const imageVariables = [
  'PRODUCTION_IMAGE', 'IMAGE_PARSER_IMAGE', 'PDF_PROCESSOR_SERVICE_IMAGE',
  'PDF_PROCESSOR_PRIVATE_SERVICE_IMAGE', 'XLSX_PROCESSOR_SERVICE_IMAGE', 'DOCX_PROCESSOR_IMAGE',
];
const protectedImages = ['bbx3/is-docker:latest', 'todolist-app:dev', 'artifactflow-app:production'];

// The fake commands are deliberately closed: an unexpected Docker operation
// fails the test. No branch can invoke a real Docker daemon or database.
function fixture() {
  const [tool, ...args] = process.argv.slice(3);
  const file = process.env.QUALITY_FIXTURE_STATE;
  const state = JSON.parse(readFileSync(file, 'utf8'));
  state.calls.push({ tool, args });
  let status = 0;
  let output = '';
  if (tool === 'make') {
    const [target, ...options] = args;
    if (['quality', 'audit', 'build-assets', 'verify-reverb-origin', 'e2e'].includes(target)) {
      writeFileSync(file, JSON.stringify(state));
      process.exit(0);
    }
    const vars = Object.fromEntries(options.map(value => {
      const separator = value.indexOf('=');
      return [value.slice(0, separator), value.slice(separator + 1)];
    }));
    const images = imageVariables.map(name => vars[name]);
    assert.ok(images.every(value => /^artifactflow-quality-[a-f0-9]+:[a-z-]+$/.test(value)));
    assert.match(vars.DOCKER_BUILD, /^docker buildx build --builder artifactflow-quality-[a-f0-9]+ --load$/);
    assert.equal(vars.DOCKER_BUILD_CACHE_ARGS, '');
    if (target === 'build-prod') {
      const built = state.mode === 'build-failure' ? images.slice(0, 3) : images;
      // Shared image IDs must not lead to removal of another tag.
      for (const image of built) state.images[image] = state.images[protectedImages[0]];
      if (state.mode === 'build-failure') status = 17;
      if (state.mode === 'interrupt') process.kill(process.ppid, 'SIGTERM');
    } else if (target === 'scan-image') {
      assert.ok(images.every(image => image in state.images));
      if (state.mode === 'scan-failure' || state.mode === 'double-failure') status = 23;
    } else {
      assert.fail(`Unexpected make target: ${target}`);
    }
  } else if (tool === 'docker' && args[0] === 'buildx' && args[1] === 'create') {
    const name = args[args.indexOf('--name') + 1];
    assert.match(name, /^artifactflow-quality-[a-f0-9]+$/);
    assert.equal(args[args.indexOf('--driver') + 1], 'docker-container');
    assert.ok(!args.includes('--use'));
    assert.ok(!state.builders.includes(name));
    if (state.mode === 'create-failure') status = 31;
    else { state.builders.push(name); output = name; }
  } else if (tool === 'docker' && args[0] === 'buildx' && args[1] === 'rm') {
    assert.equal(args.length, 3);
    assert.ok(state.builders.includes(args[2]));
    if (state.mode === 'builder-cleanup-failure') status = 41;
    else state.builders = state.builders.filter(name => name !== args[2]);
  } else if (tool === 'docker' && args[0] === 'image' && args[1] === 'ls') {
    assert.deepEqual(args.slice(2, 5), ['--quiet', '--no-trunc', '--filter']);
    assert.equal(args.length, 6);
    assert.ok(args[5].startsWith('reference=artifactflow-quality-'));
    if (state.mode === 'inspect-failure') status = 43;
    else output = state.images[args[5].slice('reference='.length)] || '';
  } else if (tool === 'docker' && args[0] === 'image' && args[1] === 'rm') {
    assert.equal(args.length, 4);
    assert.equal(args[2], '--no-prune');
    assert.match(args[3], /^artifactflow-quality-[a-f0-9]+:[a-z-]+$/);
    assert.ok(args[3] in state.images);
    if (state.mode === 'image-cleanup-failure' || state.mode === 'double-failure') status = 47;
    else delete state.images[args[3]];
  } else {
    assert.fail(`Unexpected command: ${tool} ${args.join(' ')}`);
  }
  writeFileSync(file, JSON.stringify(state));
  if (output) process.stdout.write(`${output}\n`);
  process.exit(status);
}

function run(t, mode = 'success') {
  const directory = mkdtempSync(join(tmpdir(), 'artifactflow-quality-test-'));
  const stateFile = join(directory, 'state.json');
  const paths = [stateFile, join(directory, 'docker'), join(directory, 'make')];
  t.after(() => { for (const file of paths) unlinkSync(file); rmdirSync(directory); });
  const quote = value => `'${value.replaceAll("'", "'\\''")}'`;
  for (const tool of ['docker', 'make']) {
    writeFileSync(join(directory, tool), `#!/bin/sh\nexec ${quote(process.execPath)} ${quote(self)} fixture ${tool} "$@"\n`, { mode: 0o755 });
  }
  writeFileSync(stateFile, JSON.stringify({
    mode, calls: [], images: Object.fromEntries(protectedImages.map((image, i) => [image, `protected-${i}`])),
    builders: ['existing-builder'], volumes: ['bbx3-db', 'todolist-db', 'anonymous-database'],
    containers: ['running-app', 'stopped-before-reboot'], networks: ['existing-network'],
  }));
  const command = mode === 'integration' ? '/usr/bin/make' : 'bash';
  const args = mode === 'integration'
    ? ['--no-print-directory', '-f', join(root, 'Makefile'), 'quality-full', `MAKE=${join(directory, 'make')}`]
    : [join(root, 'scripts/quality-images.sh'), join(directory, 'make')];
  const result = spawnSync(command, args, {
    cwd: root, encoding: 'utf8', timeout: 10000,
    env: {
      ...process.env, PATH: `${directory}:${process.env.PATH}`, QUALITY_FIXTURE_STATE: stateFile,
      MAKEFLAGS: '', MFLAGS: '', PRODUCTION_IMAGE: protectedImages[0], DOCKER_BUILD: 'do-not-use',
    },
  });
  assert.ifError(result.error);
  const state = JSON.parse(readFileSync(stateFile, 'utf8'));
  for (const [i, image] of protectedImages.entries()) assert.equal(state.images[image], `protected-${i}`);
  assert.deepEqual(state.volumes, ['bbx3-db', 'todolist-db', 'anonymous-database']);
  assert.deepEqual(state.containers, ['running-app', 'stopped-before-reboot']);
  assert.deepEqual(state.networks, ['existing-network']);
  assert.ok(state.builders.includes('existing-builder'));
  return { ...result, state };
}

if (process.argv[2] === 'fixture') {
  fixture();
} else {
  test('quality-full preserves gate order and uses the temporary image lifecycle', t => {
    const result = run(t, 'integration');
    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(result.state.calls.filter(call => call.tool === 'make').map(call => call.args[0]), [
      'quality', 'audit', 'build-assets', 'verify-reverb-origin', 'e2e', 'build-prod', 'scan-image',
    ]);
    assert.deepEqual(Object.keys(result.state.images).sort(), [...protectedImages].sort());
    assert.deepEqual(result.state.builders, ['existing-builder']);
    assert.ok(result.state.calls.some(call => call.args[0] === 'buildx' && call.args[1] === 'create'));
  });

  test('builds and scans the same six temporary images, then removes only owned resources', t => {
    const result = run(t);
    assert.equal(result.status, 0, result.stderr);
    const makes = result.state.calls.filter(call => call.tool === 'make');
    assert.deepEqual(makes.map(call => call.args[0]), ['build-prod', 'scan-image']);
    assert.deepEqual(makes[0].args.slice(1), makes[1].args.slice(1));
    assert.deepEqual(Object.keys(result.state.images).sort(), [...protectedImages].sort());
    assert.deepEqual(result.state.builders, ['existing-builder']);
    assert.equal(result.state.calls.filter(call => call.args[0] === 'image' && call.args[1] === 'rm').length, 6);
  });

  for (const [mode, expectedStatus] of [['build-failure', 17], ['scan-failure', 23], ['interrupt', 143]]) {
    test(`cleans partial output and retains failure status on ${mode}`, t => {
      const result = run(t, mode);
      assert.equal(result.status, expectedStatus, result.stderr);
      assert.deepEqual(Object.keys(result.state.images).sort(), [...protectedImages].sort());
      assert.deepEqual(result.state.builders, ['existing-builder']);
      if (mode !== 'scan-failure') assert.ok(!result.state.calls.some(call => call.args[0] === 'scan-image'));
    });
  }

  test('does not clean resources when builder creation failed', t => {
    const result = run(t, 'create-failure');
    assert.equal(result.status, 31, result.stderr);
    assert.equal(result.state.calls.length, 1);
    assert.deepEqual(result.state.builders, ['existing-builder']);
  });

  for (const mode of ['image-cleanup-failure', 'builder-cleanup-failure', 'inspect-failure']) {
    test(`reports ${mode} as failure and still attempts remaining cleanup`, t => {
      const result = run(t, mode);
      assert.equal(result.status, 1, result.stderr);
      assert.match(result.stderr, /cleanup/i);
      assert.ok(result.state.calls.some(call => call.args[0] === 'buildx' && call.args[1] === 'rm'));
    });
  }

  test('a cleanup failure does not mask a failed security scan', t => {
    const result = run(t, 'double-failure');
    assert.equal(result.status, 23, result.stderr);
    assert.match(result.stderr, /cleanup/i);
  });

  test('separate runs do not reuse image tags or builder names', t => {
    const first = run(t);
    const second = run(t);
    assert.equal(first.status, 0, first.stderr);
    assert.equal(second.status, 0, second.stderr);
    const name = result => result.state.calls.find(call => call.args[1] === 'create').args;
    assert.notDeepEqual(name(first), name(second));
  });
}
