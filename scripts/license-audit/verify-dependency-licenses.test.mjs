import assert from 'node:assert/strict';
import test from 'node:test';
import { DependencyLicenseAudit } from './verify-dependency-licenses.mjs';

test('accepts approved alternatives and exact reviewed metadata overrides', () => {
  const audit = new DependencyLicenseAudit({
    approvedLicenses: ['Apache-2.0', 'MIT'],
    npmMetadataOverrides: {
      'metadata-gap@1.0.0': {
        license: 'MIT',
        source: 'https://example.test/metadata-gap/license',
      },
    },
  });

  assert.deepEqual(
    audit.auditComposerPackages(
      [{ name: 'safe/alternative', version: '1.0.0', license: ['GPL-3.0-only', 'MIT'] }],
      'composer.lock',
    ),
    [],
  );
  assert.deepEqual(
    audit.auditNpmPackages(
      { 'node_modules/metadata-gap': { version: '1.0.0' } },
      'package-lock.json',
    ),
    [],
  );
});

test('fails closed for changed, strong-copyleft, missing, and unparsable licenses', () => {
  const audit = new DependencyLicenseAudit({
    approvedLicenses: ['Apache-2.0', 'MIT'],
    npmMetadataOverrides: {},
  });
  const composerIssues = audit.auditComposerPackages(
    [
      { name: 'changed/to-proprietary', version: '2.0.0', license: ['SEE LICENSE IN EULA'] },
      { name: 'missing/license', version: '3.0.0' },
      { name: 'compound/license', version: '4.0.0', license: ['MIT AND GPL-3.0-only'] },
    ],
    'composer.lock',
  );
  const npmIssues = audit.auditNpmPackages(
    {
      'node_modules/metadata-gap': { version: '2.0.0' },
      'node_modules/strong-copyleft': { version: '1.0.0', license: 'AGPL-3.0-only' },
    },
    'package-lock.json',
  );

  assert.equal(composerIssues.length, 3);
  assert.match(composerIssues[0], /changed\/to-proprietary@2\.0\.0/u);
  assert.match(composerIssues[1], /missing\/license@3\.0\.0/u);
  assert.match(composerIssues[2], /compound\/license@4\.0\.0/u);
  assert.equal(npmIssues.length, 2);
  assert.match(npmIssues[0], /metadata-gap@2\.0\.0/u);
  assert.match(npmIssues[1], /strong-copyleft@1\.0\.0/u);
});
