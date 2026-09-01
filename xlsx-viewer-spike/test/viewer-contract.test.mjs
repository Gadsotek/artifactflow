import assert from 'node:assert/strict';
import test from 'node:test';

import {
  columnLabel,
  decodedCoordinate,
  safeExternalTarget,
  validatedManifest,
} from '../../resources/js/xlsx-viewer-contract.js';
import { manifestFixture, stressManifest } from '../fixture-manifest.js';

test('decodes only coordinates inside the viewer profile', () => {
  assert.deepEqual(decodedCoordinate('A1'), { column: 0, row: 0 });
  assert.deepEqual(decodedCoordinate('IV20000'), { column: 255, row: 19_999 });
  assert.equal(columnLabel(0), 'A');
  assert.equal(columnLabel(255), 'IV');

  for (const coordinate of ['A0', 'a1', 'IW1', 'A20001', 'A1:A2', '__proto__']) {
    assert.throws(() => decodedCoordinate(coordinate), /coordinate/u);
  }
});

test('accepts only normalized click-driven external schemes', () => {
  assert.equal(safeExternalTarget('https://example.com/a?q=1'), 'https://example.com/a?q=1');
  assert.equal(safeExternalTarget('https://example.com'), 'https://example.com/');
  assert.equal(safeExternalTarget('http://example.com/'), 'http://example.com/');
  assert.equal(safeExternalTarget('mailto:person@example.com'), 'mailto:person@example.com');

  for (const target of [
    'javascript:alert(1)',
    'file:///etc/passwd',
    'ftp://example.com/',
    'https://user:password@example.com/',
    'relative/path',
    'https://example.com/\u0000bad',
  ]) {
    assert.equal(safeExternalTarget(target), null);
  }
});

test('accepts the canonical fixture and rejects malformed manifest authority', () => {
  assert.equal(validatedManifest(structuredClone(manifestFixture)).profile, 'xlsx-typed-view-v1');

  const missingSchema = structuredClone(manifestFixture);
  delete missingSchema.schema;
  assert.throws(() => validatedManifest(missingSchema), /manifest/u);

  const duplicateCoordinate = structuredClone(manifestFixture);
  duplicateCoordinate.sheets[0].cells.push({ ...duplicateCoordinate.sheets[0].cells[0] });
  assert.throws(() => validatedManifest(duplicateCoordinate), /worksheet manifest/u);

  const unsafeLink = structuredClone(manifestFixture);
  unsafeLink.sheets[0].cells[2].link.target = 'javascript:alert(1)';
  assert.throws(() => validatedManifest(unsafeLink), /hyperlink/u);

  const normalizableLink = structuredClone(manifestFixture);
  normalizableLink.sheets[0].cells[2].link.target = 'https://example.com';
  assert.equal(
    validatedManifest(normalizableLink).sheets[0].cells[2].link.target,
    'https://example.com',
  );

  const hiddenDestination = structuredClone(manifestFixture);
  hiddenDestination.sheets[0].cells[3].link.sheet = 'Missing';
  assert.throws(() => validatedManifest(hiddenDestination), /hyperlink/u);

  const markupKind = structuredClone(manifestFixture);
  markupKind.sheets[0].cells[0].kind = 'html';
  assert.throws(() => validatedManifest(markupKind), /cell/u);

  for (const mutate of [
    (manifest) => {
      manifest.untrusted = { renderer: 'future-authority' };
    },
    (manifest) => {
      manifest.workbook.untrusted = true;
    },
    (manifest) => {
      manifest.sheets[0].untrusted = ['future-authority'];
    },
  ]) {
    const unexpectedAuthority = structuredClone(manifestFixture);
    mutate(unexpectedAuthority);
    assert.throws(() => validatedManifest(unexpectedAuthority), /manifest|facts|worksheet/u);
  }

  const inconsistentKind = structuredClone(manifestFixture);
  inconsistentKind.sheets[0].cells[0].kind = 'number';
  inconsistentKind.sheets[0].cells[0].value = 'not-a-number';
  assert.throws(() => validatedManifest(inconsistentKind), /cell/u);

  const missingTypedValue = structuredClone(manifestFixture);
  missingTypedValue.sheets[0].cells[4].value = null;
  assert.throws(() => validatedManifest(missingTypedValue), /cell/u);

  const impossibleSheetCount = structuredClone(manifestFixture);
  impossibleSheetCount.workbook.omittedHiddenSheetCount = 20;
  assert.throws(() => validatedManifest(impossibleSheetCount), /facts/u);

  const nonCanonicalOrder = structuredClone(manifestFixture);
  nonCanonicalOrder.sheets[0].cells.reverse();
  assert.throws(() => validatedManifest(nonCanonicalOrder), /worksheet/u);

  const overlappingMerge = structuredClone(manifestFixture);
  overlappingMerge.sheets[0].merges.push({ start: 'B2', end: 'C2' });
  overlappingMerge.workbook.mergeCount = 2;
  assert.throws(() => validatedManifest(overlappingMerge), /merge/u);

  const dishonestCellCount = structuredClone(manifestFixture);
  dishonestCellCount.workbook.cellCount += 1;
  assert.throws(() => validatedManifest(dishonestCellCount), /facts/u);
});

test('builds a self-consistent maximum-row browser stress manifest', () => {
  const manifest = stressManifest();

  assert.equal(manifest.workbook.cellCount, 20_000);
  assert.equal(manifest.sheets[0].rowExtent, 20_000);
  assert.equal(manifest.sheets[0].cells.length, 20_000);
  assert.equal(validatedManifest(manifest).workbook.cellCount, 20_000);
});
