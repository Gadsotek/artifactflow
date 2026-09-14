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

const elkApproval = {
  version: '0.12.0',
  license: 'EPL-2.0 OR GPL-3.0-or-later',
  integrity:
    'sha512-YZcKynxVxYoKIOEpywEPwCFdg+BTbxQRNf3pbwdDCvc8O3kQD8bmIwSxKU1eOTVc4Xo+VG9Te+575mlfvOrhEQ==',
  resolved: 'https://registry.npmjs.org/elkjs/-/elkjs-0.12.0.tgz',
  source: 'package-lock.json',
  packagePath: 'node_modules/elkjs',
};

function elkAudit(approval = elkApproval) {
  return new DependencyLicenseAudit({
    approvedLicenses: ['MIT'],
    npmMetadataOverrides: {},
    npmPackageApprovals: { elkjs: approval },
  });
}

test('accepts only the reviewed ELK release without generally allowing either copyleft license', () => {
  const audit = elkAudit();
  assert.deepEqual(
    audit.auditNpmPackages({ 'node_modules/elkjs': elkApproval }, 'package-lock.json'),
    [],
  );
  assert.equal(audit.isCompatible('EPL-2.0'), false);
  assert.equal(audit.isCompatible('GPL-3.0-or-later'), false);
  assert.equal(
    audit.auditNpmPackages({ 'node_modules/unreviewed': elkApproval }, 'package-lock.json').length,
    1,
  );
  assert.equal(
    audit.auditComposerPackages(
      [{ name: 'elkjs', version: '0.12.0', license: [elkApproval.license] }],
      'composer.lock',
    ).length,
    1,
  );
});

for (const [label, change] of Object.entries({
  'older EPL-only release': { version: '0.9.3', license: 'EPL-2.0' },
  'new release': { version: '0.13.0' },
  'missing version': { version: undefined },
  'changed integrity': { integrity: 'sha512-unreviewed' },
  'missing integrity': { integrity: undefined },
  'changed tarball': { resolved: 'https://example.test/elkjs.tgz' },
  'missing tarball': { resolved: undefined },
  'changed license': { license: 'MIT' },
  'missing license': { license: undefined },
  'EPL without secondary grant': { license: 'EPL-2.0' },
  'npm alias': { name: 'another-package' },
})) {
  test('rejects ELK approval drift: ' + label, () => {
    assert.equal(
      elkAudit().auditNpmPackages(
        {
          'node_modules/elkjs': { ...elkApproval, ...change },
        },
        'package-lock.json',
      ).length,
      1,
    );
  });
}

test('restricts the ELK approval to the reviewed lockfile and package path', () => {
  assert.equal(
    elkAudit().auditNpmPackages({ 'node_modules/elkjs': elkApproval }, 'another/package-lock.json')
      .length,
    1,
  );
  assert.equal(
    elkAudit().auditNpmPackages(
      { 'node_modules/mermaid/node_modules/elkjs': elkApproval },
      'package-lock.json',
    ).length,
    1,
  );
});

test('does not approve an incomplete package policy by comparing absent metadata', () => {
  for (const field of ['version', 'license', 'integrity', 'resolved', 'source', 'packagePath']) {
    const approval = { ...elkApproval, [field]: undefined };
    assert.equal(
      elkAudit(approval).auditNpmPackages(
        { 'node_modules/elkjs': elkApproval },
        'package-lock.json',
      ).length,
      1,
    );
  }
});

test('ships ELK notices and license texts next to the chunks that contain its code', async () => {
  const { retainMermaidLicenses } = await import('../../resources/build/mermaid-licenses.js');
  const plugin = retainMermaidLicenses();
  const assets = [];
  const bundle = {
    elk: {
      type: 'chunk',
      code: 'ELK_CODE',
      modules: { '/app/node_modules/elkjs/lib/elk.bundled.js': {} },
    },
    app: { type: 'chunk', code: 'APP_CODE', modules: { '/app/resources/js/app.js': {} } },
  };
  plugin.generateBundle.call({ emitFile: (asset) => assets.push(asset) }, {}, bundle);
  assert.match(bundle.elk.code, /ELK 0.12.0/u);
  assert.ok(bundle.elk.code.includes('licenses/elkjs-0.12.0/NOTICE.txt'));
  assert.equal(bundle.app.code, 'APP_CODE');
  assert.equal(assets.length, 3);
  const notice = assets.find((asset) => asset.fileName.endsWith('/NOTICE.txt'));
  assert.match(notice.source, /ff5771d7165445c42c408bb8a090c8035272218c/u);
  assert.ok(notice.source.includes('eclipse-elk/elk'));
  assert.match(
    assets.find((asset) => asset.fileName.endsWith('/EPL-2.0.txt')).source,
    /Secondary Licenses/u,
  );
  assert.match(
    assets.find((asset) => asset.fileName.endsWith('/GPL-3.0.txt')).source,
    /GNU GENERAL PUBLIC LICENSE/u,
  );
});
