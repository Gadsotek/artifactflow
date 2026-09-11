import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  mkdtempSync,
  mkdirSync,
  readFileSync,
  writeFileSync,
  existsSync,
  unlinkSync,
  rmdirSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import test from 'node:test';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const publicDocs = execFileSync('git', ['ls-files', '--', 'docs'], { cwd: root, encoding: 'utf8' })
  .trim()
  .split('\n');

function fixture(t, { allowSql = true, hidePrivate = true, unexpectedDoc = false } = {}) {
  const directory = mkdtempSync(join(tmpdir(), 'artifactflow-publish-test-'));
  const files = [];
  const directories = new Set([directory]);
  const write = (path, content) => {
    const target = join(directory, path);
    let parent = dirname(target);
    while (parent !== directory) {
      directories.add(parent);
      parent = dirname(parent);
    }
    mkdirSync(dirname(target), { recursive: true });
    writeFileSync(target, content);
    files.push(target);
  };
  t.after(() => {
    // Remove only the fixture files and the known empty directories we created.
    for (const path of [...files, join(directory, '.git/HEAD'), join(directory, '.git/config')]) {
      if (existsSync(path)) unlinkSync(path);
    }
    for (const path of [
      'objects/info',
      'objects/pack',
      'objects',
      'refs/heads',
      'refs/tags',
      'refs',
      '',
    ]) {
      const target = join(directory, '.git', path);
      if (existsSync(target)) rmdirSync(target);
    }
    for (const path of [...directories].sort((a, b) => b.length - a.length)) rmdirSync(path);
  });
  execFileSync('git', ['init', '--quiet', '--template='], { cwd: directory });
  write(
    '.gitignore',
    `${hidePrivate ? '/docs/internal/\n' : ''}*.sql\n${allowSql ? '!docs/operations/artifact-host-database-grants.sql\n' : ''}`,
  );
  write('README.md', readFileSync(join(root, 'README.md'), 'utf8'));
  write('THREAT-MODEL.md', '# Public threat model fixture\n');
  for (const path of publicDocs) write(path, '# Public documentation fixture\n');
  write('docs/internal/private.md', '# Private working note fixture\n');
  if (unexpectedDoc) write('docs/unreviewed.md', '# Unexpected public document\n');
  return spawnSync('bash', [join(root, 'scripts/publish-guard.sh')], {
    cwd: directory,
    encoding: 'utf8',
  });
}

test('publish guard allows an explicitly unignored public SQL reference', (t) => {
  const result = fixture(t);
  assert.equal(result.status, 0, result.stderr);
});

test('publish guard still rejects an ignored public SQL reference', (t) => {
  const result = fixture(t, { allowSql: false });
  assert.equal(result.status, 1);
  assert.match(result.stderr, /expected public path to be visible/u);
});

test('publish guard still rejects visible private working documents', (t) => {
  const result = fixture(t, { hidePrivate: false });
  assert.equal(result.status, 1);
  assert.match(result.stderr, /private working bucket.*must be git-ignored/u);
});

test('publish guard still rejects additional public documents', (t) => {
  const result = fixture(t, { unexpectedDoc: true });
  assert.equal(result.status, 1);
  assert.match(result.stderr, /unexpected public docs set/u);
});
