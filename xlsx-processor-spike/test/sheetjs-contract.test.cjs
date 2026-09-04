'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const XLSX = require('xlsx');

const { XlsxProjectionError, projectXlsx } = require('../src/project-xlsx.cjs');

function writeWorkbook(workbook, compression = true) {
  return XLSX.write(workbook, {
    bookType: 'xlsx',
    type: 'buffer',
    compression,
  });
}

function replaceZipEntry(input, path, replace) {
  const container = XLSX.CFB.read(input, { type: 'buffer' });
  const entry = XLSX.CFB.find(container, path);

  assert.ok(entry?.content, `missing fixture entry: ${path}`);
  entry.content = Buffer.from(replace(entry.content.toString('utf8')), 'utf8');
  entry.size = entry.content.length;

  return XLSX.CFB.write(container, {
    fileType: 'zip',
    type: 'buffer',
    compression: true,
  });
}

function addZipEntry(input, path, content) {
  const container = XLSX.CFB.read(input, { type: 'buffer' });

  XLSX.CFB.utils.cfb_add(container, path, Buffer.from(content));

  return XLSX.CFB.write(container, {
    fileType: 'zip',
    type: 'buffer',
    compression: true,
  });
}

function centralDirectoryEntries(input) {
  let endOffset = -1;

  for (let offset = input.length - 22; offset >= Math.max(0, input.length - 65_557); offset -= 1) {
    if (
      input.readUInt32LE(offset) === 0x06054b50 &&
      offset + 22 + input.readUInt16LE(offset + 20) === input.length
    ) {
      endOffset = offset;
      break;
    }
  }

  assert.notEqual(endOffset, -1, 'fixture ZIP must contain an end-of-directory record');

  const count = input.readUInt16LE(endOffset + 10);
  let offset = input.readUInt32LE(endOffset + 16);
  const entries = [];

  for (let index = 0; index < count; index += 1) {
    assert.equal(input.readUInt32LE(offset), 0x02014b50);

    const nameLength = input.readUInt16LE(offset + 28);
    const extraLength = input.readUInt16LE(offset + 30);
    const commentLength = input.readUInt16LE(offset + 32);

    entries.push({
      centralOffset: offset,
      compressedBytes: input.readUInt32LE(offset + 20),
      crc32: input.readUInt32LE(offset + 16),
      expandedBytes: input.readUInt32LE(offset + 24),
      localOffset: input.readUInt32LE(offset + 42),
      name: input.subarray(offset + 46, offset + 46 + nameLength).toString('utf8'),
    });
    offset += 46 + nameLength + extraLength + commentLength;
  }

  return entries;
}

function withDataDescriptor(input, includeSignature, corruptDescriptor = false) {
  const entries = centralDirectoryEntries(input);
  const entry = entries.reduce((last, candidate) =>
    candidate.localOffset > last.localOffset ? candidate : last,
  );
  const endOffset = input.length - 22 - input.readUInt16LE(input.length - 2);
  const centralOffset = input.readUInt32LE(endOffset + 16);
  const localNameLength = input.readUInt16LE(entry.localOffset + 26);
  const localExtraLength = input.readUInt16LE(entry.localOffset + 28);
  const dataOffset = entry.localOffset + 30 + localNameLength + localExtraLength;

  assert.equal(dataOffset + entry.compressedBytes, centralOffset);

  const descriptor = Buffer.alloc(includeSignature ? 16 : 12);
  const valueOffset = includeSignature ? 4 : 0;
  if (includeSignature) {
    descriptor.writeUInt32LE(0x08074b50, 0);
  }
  descriptor.writeUInt32LE(corruptDescriptor ? entry.crc32 ^ 1 : entry.crc32, valueOffset);
  descriptor.writeUInt32LE(entry.compressedBytes, valueOffset + 4);
  descriptor.writeUInt32LE(entry.expandedBytes, valueOffset + 8);

  const output = Buffer.concat([
    input.subarray(0, centralOffset),
    descriptor,
    input.subarray(centralOffset),
  ]);
  const shiftedCentralEntry = entry.centralOffset + descriptor.length;
  const shiftedEnd = endOffset + descriptor.length;

  output.writeUInt16LE(output.readUInt16LE(entry.localOffset + 6) | 0x0008, entry.localOffset + 6);
  output.writeUInt32LE(0, entry.localOffset + 14);
  output.writeUInt32LE(0, entry.localOffset + 18);
  output.writeUInt32LE(0, entry.localOffset + 22);
  output.writeUInt16LE(
    output.readUInt16LE(shiftedCentralEntry + 8) | 0x0008,
    shiftedCentralEntry + 8,
  );
  output.writeUInt32LE(centralOffset + descriptor.length, shiftedEnd + 16);

  return output;
}

function forgeDeclaredExpandedSize(input, path, declaredBytes) {
  const forged = Buffer.from(input);
  const entry = centralDirectoryEntries(forged).find((candidate) => candidate.name === path);

  assert.ok(entry, `missing fixture entry: ${path}`);
  forged.writeUInt32LE(declaredBytes, entry.centralOffset + 24);
  forged.writeUInt32LE(declaredBytes, entry.localOffset + 22);

  return forged;
}

function basicWorkbook() {
  const workbook = XLSX.utils.book_new();
  const visible = XLSX.utils.aoa_to_sheet([
    ['Label', 'Formula', 'Website', 'Jump'],
    ['First', { t: 'n', f: 'A3+A4', v: 3 }, 'Open', 'Go'],
    [1],
    [2],
  ]);

  visible.C2.l = { Target: 'https://example.com/report?q=1#summary' };
  visible.D2.l = { Target: "#'Visible data'!A3" };
  visible.A4.l = { Target: "#'Visible data'!A1" };
  visible['!rows'] = [{}, {}, { hidden: true }, {}];
  visible['!cols'] = [{}, {}, {}, { hidden: true }];

  XLSX.utils.book_append_sheet(workbook, visible, 'Visible data');
  XLSX.utils.book_append_sheet(
    workbook,
    XLSX.utils.aoa_to_sheet([['hidden secret']]),
    'Hidden data',
  );
  XLSX.utils.book_append_sheet(
    workbook,
    XLSX.utils.aoa_to_sheet([['very hidden secret']]),
    'Very hidden data',
  );

  workbook.Workbook = {
    Sheets: [
      { name: 'Visible data', Hidden: 0 },
      { name: 'Hidden data', Hidden: 1 },
      { name: 'Very hidden data', Hidden: 2 },
    ],
  };

  return workbook;
}

function workbookWithWorksheetCustomProperty() {
  let input = writeWorkbook(basicWorkbook());
  input = replaceZipEntry(input, '/[Content_Types].xml', (source) =>
    source.replace(
      '</Types>',
      '<Override PartName="/xl/customProperty1.bin" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.customProperty"/></Types>',
    ),
  );
  input = replaceZipEntry(input, '/xl/worksheets/sheet1.xml', (source) =>
    source.replace(
      '</worksheet>',
      '<customProperties><customPr name="ArtifactFlow fixture property" r:id="rIdCustomProperty"/></customProperties></worksheet>',
    ),
  );
  input = replaceZipEntry(input, '/xl/worksheets/_rels/sheet1.xml.rels', (source) =>
    source.replace(
      '</Relationships>',
      '<Relationship Id="rIdCustomProperty" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customProperty" Target="../customProperty1.bin"/></Relationships>',
    ),
  );

  return addZipEntry(
    input,
    '/xl/customProperty1.bin',
    Buffer.from('PRIVATE_CUSTOM_PROPERTY_MARKER', 'utf8'),
  );
}

function expectProjectionError(callback, code) {
  assert.throws(callback, (error) => {
    assert.ok(error instanceof XlsxProjectionError);
    assert.equal(error.code, code);

    return true;
  });
}

test('rejects non-XLSX input before SheetJS format guessing', () => {
  const csv = Buffer.from('name,value\nalpha,1\n', 'utf8');
  const guessed = XLSX.read(csv, { type: 'buffer' });

  assert.deepEqual(guessed.SheetNames, ['Sheet1']);
  expectProjectionError(() => projectXlsx(csv), 'not_xlsx_zip');
});

test('projects visible cells without recalculating formulas or exposing hidden content', () => {
  const projected = projectXlsx(writeWorkbook(basicWorkbook()));

  assert.equal(projected.schema, 'xlsx-view-manifest-v1');
  assert.equal(projected.profile, 'xlsx-typed-view-v1');
  assert.deepEqual(projected.workbook, {
    visibleSheetCount: 1,
    omittedHiddenSheetCount: 2,
    cellCount: 7,
    formulaCount: 1,
    formulasWithoutCachedResultCount: 0,
    linkCount: 2,
    mergeCount: 0,
    truncated: false,
  });
  assert.equal(projected.sheets.length, 1);
  assert.equal(projected.sheets[0].name, 'Visible data');
  assert.equal(projected.sheets[0].rowExtent, 4);
  assert.equal(projected.sheets[0].columnExtent, 3);
  assert.equal(projected.sheets[0].omittedHiddenRowCount, 1);
  assert.equal(projected.sheets[0].omittedHiddenColumnCount, 1);

  const formula = projected.sheets[0].cells.find((cell) => cell.coordinate === 'B2');
  assert.deepEqual(formula, {
    coordinate: 'B2',
    kind: 'number',
    display: '3',
    value: 3,
    formula: 'A3+A4',
    cachedResultAvailable: true,
  });

  const link = projected.sheets[0].cells.find((cell) => cell.coordinate === 'C2');
  assert.deepEqual(link.link, {
    kind: 'external',
    target: 'https://example.com/report?q=1#summary',
  });

  const internalLink = projected.sheets[0].cells.find((cell) => cell.coordinate === 'A4');
  assert.deepEqual(internalLink.link, {
    kind: 'internal',
    sheet: 'Visible data',
    coordinate: 'A1',
  });

  assert.equal(
    projected.sheets[0].cells.some((cell) => cell.coordinate === 'A3'),
    false,
  );
  assert.equal(
    projected.sheets[0].cells.some((cell) => cell.coordinate === 'D2'),
    false,
  );
  assert.doesNotMatch(projected.searchText, /hidden secret/i);
  assert.doesNotMatch(projected.searchText, /example\.com/i);
});

test('projects ordinary workbooks with bounded passive table, drawing, and image parts', () => {
  let input = writeWorkbook(basicWorkbook());
  input = replaceZipEntry(input, '/[Content_Types].xml', (source) => {
    const pngDefault = source.includes('Extension="png"')
      ? ''
      : '<Default Extension="png" ContentType="image/png"/>';

    return source.replace(
      '</Types>',
      pngDefault +
        '<Override PartName="/xl/tables/table1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>' +
        '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' +
        '</Types>',
    );
  });
  input = replaceZipEntry(input, '/xl/worksheets/_rels/sheet1.xml.rels', (source) =>
    source.replace(
      '</Relationships>',
      '<Relationship Id="rIdTable" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/>' +
        '<Relationship Id="rIdDrawing" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>' +
        '</Relationships>',
    ),
  );
  input = addZipEntry(
    input,
    '/xl/tables/table1.xml',
    '<?xml version="1.0" encoding="UTF-8"?><table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="Table1" displayName="Table1" ref="A1:B2" totalsRowShown="0"><autoFilter ref="A1:B2"/><tableColumns count="2"><tableColumn id="1" name="Label"/><tableColumn id="2" name="Formula"/></tableColumns></table>',
  );
  input = addZipEntry(
    input,
    '/xl/drawings/drawing1.xml',
    '<?xml version="1.0" encoding="UTF-8"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"/>',
  );
  input = addZipEntry(
    input,
    '/xl/drawings/_rels/drawing1.xml.rels',
    '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdImage" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.png"/></Relationships>',
  );
  input = addZipEntry(
    input,
    '/xl/media/image1.png',
    Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
      'base64',
    ),
  );

  const projected = projectXlsx(input);

  assert.equal(projected.sheets[0].name, 'Visible data');
  assert.match(projected.searchText, /First/u);
});

test('omits passive comments, custom XML, VML, and package thumbnails from the typed projection', () => {
  let input = writeWorkbook(basicWorkbook());
  input = replaceZipEntry(input, '/[Content_Types].xml', (source) => {
    const vmlDefault = source.includes('Extension="vml"')
      ? ''
      : '<Default Extension="vml" ContentType="application/vnd.openxmlformats-officedocument.vmlDrawing"/>';
    const jpegDefault = source.includes('Extension="jpeg"')
      ? ''
      : '<Default Extension="jpeg" ContentType="image/jpeg"/>';

    return source.replace(
      '</Types>',
      vmlDefault +
        jpegDefault +
        '<Override PartName="/xl/comments1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.comments+xml"/>' +
        '<Override PartName="/customXml/itemProps1.xml" ContentType="application/vnd.openxmlformats-officedocument.customXmlProperties+xml"/>' +
        '</Types>',
    );
  });
  input = replaceZipEntry(input, '/_rels/.rels', (source) =>
    source.replace(
      '</Relationships>',
      '<Relationship Id="rIdThumbnail" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/thumbnail" Target="docProps/thumbnail.jpeg"/></Relationships>',
    ),
  );
  input = replaceZipEntry(input, '/xl/_rels/workbook.xml.rels', (source) =>
    source.replace(
      '</Relationships>',
      '<Relationship Id="rIdCustomXml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXml" Target="../customXml/item1.xml"/></Relationships>',
    ),
  );
  input = replaceZipEntry(input, '/xl/worksheets/_rels/sheet1.xml.rels', (source) =>
    source.replace(
      '</Relationships>',
      '<Relationship Id="rIdComments" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments" Target="../comments1.xml"/>' +
        '<Relationship Id="rIdVml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/vmlDrawing" Target="../drawings/vmlDrawing1.vml"/>' +
        '</Relationships>',
    ),
  );
  input = addZipEntry(
    input,
    '/xl/comments1.xml',
    '<?xml version="1.0" encoding="UTF-8"?><comments xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><authors><author>Reviewer</author></authors><commentList><comment ref="A2" authorId="0"><text><t>Private review note</t></text></comment></commentList></comments>',
  );
  input = addZipEntry(
    input,
    '/xl/drawings/vmlDrawing1.vml',
    '<?xml version="1.0" encoding="UTF-8"?><xml xmlns:v="urn:schemas-microsoft-com:vml"><v:shape id="comment-shape"/></xml>',
  );
  input = addZipEntry(
    input,
    '/customXml/item1.xml',
    '<?xml version="1.0" encoding="UTF-8"?><root><value>Private mapped value</value></root>',
  );
  input = addZipEntry(
    input,
    '/customXml/_rels/item1.xml.rels',
    '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdProps" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXmlProps" Target="itemProps1.xml"/></Relationships>',
  );
  input = addZipEntry(
    input,
    '/customXml/itemProps1.xml',
    '<?xml version="1.0" encoding="UTF-8"?><ds:datastoreItem ds:itemID="{00112233-4455-6677-8899-AABBCCDDEEFF}" xmlns:ds="http://schemas.openxmlformats.org/officeDocument/2006/customXml"><ds:schemaRefs/></ds:datastoreItem>',
  );
  input = addZipEntry(input, '/docProps/thumbnail.jpeg', Buffer.from([0xff, 0xd8, 0xff, 0xd9]));

  const projected = projectXlsx(input);

  assert.match(projected.searchText, /First/u);
  assert.doesNotMatch(projected.searchText, /Private review note|Private mapped value/u);

  const dtdVml = replaceZipEntry(input, '/xl/drawings/vmlDrawing1.vml', (source) =>
    source.replace('<xml ', '<!DOCTYPE xml [<!ENTITY x SYSTEM "file:///etc/passwd">]><xml '),
  );
  expectProjectionError(() => projectXlsx(dtdVml), 'unsupported_xlsx_profile');
});

test('omits a bounded worksheet custom-property binary from the typed projection', () => {
  const input = workbookWithWorksheetCustomProperty();
  const projected = projectXlsx(input);

  assert.equal(projected.sheets[0].name, 'Visible data');
  assert.match(projected.searchText, /First/u);
  assert.doesNotMatch(projected.searchText, /PRIVATE_CUSTOM_PROPERTY_MARKER/u);

  const genericBinary = replaceZipEntry(input, '/[Content_Types].xml', (source) =>
    source.replace(
      'application/vnd.openxmlformats-officedocument.spreadsheetml.customProperty',
      'application/octet-stream',
    ),
  );
  const externalBinary = replaceZipEntry(input, '/xl/worksheets/_rels/sheet1.xml.rels', (source) =>
    source.replace(
      'Target="../customProperty1.bin"',
      'Target="https://example.com/customProperty1.bin" TargetMode="External"',
    ),
  );
  const spoofedRelationship = replaceZipEntry(
    input,
    '/xl/worksheets/_rels/sheet1.xml.rels',
    (source) =>
      source.replace(
        'relationships/customProperty" Target="../customProperty1.bin"',
        'relationships/chart" Target="../customProperty1.bin"',
      ),
  );
  let wrongOwner = replaceZipEntry(input, '/xl/worksheets/_rels/sheet1.xml.rels', (source) =>
    source.replace(
      '<Relationship Id="rIdCustomProperty" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customProperty" Target="../customProperty1.bin"/>',
      '',
    ),
  );
  wrongOwner = replaceZipEntry(wrongOwner, '/xl/_rels/workbook.xml.rels', (source) =>
    source.replace(
      '</Relationships>',
      '<Relationship Id="rIdCustomProperty" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customProperty" Target="customProperty1.bin"/></Relationships>',
    ),
  );

  expectProjectionError(() => projectXlsx(genericBinary), 'unsupported_xlsx_profile');
  expectProjectionError(() => projectXlsx(externalBinary), 'unsupported_xlsx_profile');
  expectProjectionError(() => projectXlsx(spoofedRelationship), 'unsupported_xlsx_profile');
  expectProjectionError(() => projectXlsx(wrongOwner), 'unsupported_xlsx_profile');
});

test('keeps active, external, and attacker-defined optional workbook parts outside the profile', () => {
  const base = writeWorkbook(basicWorkbook());
  let embedded = replaceZipEntry(base, '/[Content_Types].xml', (source) =>
    source.replace(
      '</Types>',
      '<Override PartName="/xl/embeddings/object1.bin" ContentType="application/vnd.openxmlformats-officedocument.oleObject"/></Types>',
    ),
  );
  embedded = replaceZipEntry(embedded, '/xl/worksheets/_rels/sheet1.xml.rels', (source) =>
    source.replace(
      '</Relationships>',
      '<Relationship Id="rIdObject" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/oleObject" Target="../embeddings/object1.bin"/></Relationships>',
    ),
  );
  embedded = addZipEntry(embedded, '/xl/embeddings/object1.bin', Buffer.from('embedded'));

  const externalImage = replaceZipEntry(base, '/xl/worksheets/_rels/sheet1.xml.rels', (source) =>
    source.replace(
      '</Relationships>',
      '<Relationship Id="rIdImage" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="https://example.com/pixel.png" TargetMode="External"/></Relationships>',
    ),
  );
  let attackerRelationship = addZipEntry(
    base,
    '/xl/attacker.xml',
    '<?xml version="1.0" encoding="UTF-8"?><attacker/>',
  );
  attackerRelationship = replaceZipEntry(attackerRelationship, '/[Content_Types].xml', (source) =>
    source.replace(
      '</Types>',
      '<Override PartName="/xl/attacker.xml" ContentType="application/xml"/></Types>',
    ),
  );
  attackerRelationship = replaceZipEntry(
    attackerRelationship,
    '/xl/_rels/workbook.xml.rels',
    (source) =>
      source.replace(
        '</Relationships>',
        '<Relationship Id="rIdAttacker" Type="https://attacker.example/relationships/payload" Target="attacker.xml"/></Relationships>',
      ),
  );

  expectProjectionError(() => projectXlsx(embedded), 'unsupported_xlsx_profile');
  expectProjectionError(() => projectXlsx(externalImage), 'unsupported_xlsx_profile');
  expectProjectionError(() => projectXlsx(attackerRelationship), 'unsupported_xlsx_profile');
});

test('rejects duplicate worksheet names before resolving hidden-sheet visibility', () => {
  const duplicateName = replaceZipEntry(writeWorkbook(basicWorkbook()), '/xl/workbook.xml', (xml) =>
    xml.replace('name="Hidden data"', 'name="Visible data"'),
  );

  expectProjectionError(() => projectXlsx(duplicateName), 'unsupported_xlsx_profile');
});

test('projects dates and bounded visible merged ranges without style authority', () => {
  const workbook = XLSX.utils.book_new();
  const sheet = XLSX.utils.aoa_to_sheet(
    [['Period', new Date(2026, 7, 30)], ['Merged heading'], []],
    { cellDates: true },
  );
  sheet['!merges'] = [XLSX.utils.decode_range('A2:C3')];
  XLSX.utils.book_append_sheet(workbook, sheet, 'Summary');

  const projected = projectXlsx(writeWorkbook(workbook));
  const date = projected.sheets[0].cells.find((cell) => cell.coordinate === 'B1');

  assert.deepEqual(date, {
    coordinate: 'B1',
    kind: 'date',
    display: '8/30/26',
    value: '2026-08-30T00:00:00.000Z',
  });
  assert.deepEqual(projected.sheets[0].merges, [{ start: 'A2', end: 'C3' }]);
  assert.equal(projected.sheets[0].rowExtent, 3);
  assert.equal(projected.sheets[0].columnExtent, 3);
  assert.equal(projected.workbook.cellCount, 3);
  assert.equal(projected.workbook.mergeCount, 1);
  assert.equal(projected.workbook.truncated, false);
});

test('rejects merged ranges that cross hidden or unsupported extents', () => {
  const hiddenWorkbook = XLSX.utils.book_new();
  const hiddenSheet = XLSX.utils.aoa_to_sheet([['heading'], [], []]);
  hiddenSheet['!merges'] = [XLSX.utils.decode_range('A1:B3')];
  hiddenSheet['!rows'] = [{}, { hidden: true }, {}];
  XLSX.utils.book_append_sheet(hiddenWorkbook, hiddenSheet, 'Hidden merge');

  expectProjectionError(
    () => projectXlsx(writeWorkbook(hiddenWorkbook)),
    'unsupported_merged_range',
  );

  const farWorkbook = XLSX.utils.book_new();
  const farSheet = XLSX.utils.aoa_to_sheet([['heading']]);
  farSheet['!merges'] = [XLSX.utils.decode_range('A1:IW1')];
  XLSX.utils.book_append_sheet(farWorkbook, farSheet, 'Far merge');

  expectProjectionError(
    () => projectXlsx(writeWorkbook(farWorkbook)),
    'worksheet_extent_limit_exceeded',
  );
});

test('preserves a formula without a cached result as unavailable', () => {
  const workbook = XLSX.utils.book_new();
  const sheet = XLSX.utils.aoa_to_sheet([['Pending']]);
  sheet.B1 = { t: 'n', f: '1+1' };
  sheet['!ref'] = 'A1:B1';
  XLSX.utils.book_append_sheet(workbook, sheet, 'Sheet 1');

  const projected = projectXlsx(writeWorkbook(workbook));
  const formula = projected.sheets[0].cells.find((cell) => cell.coordinate === 'B1');

  assert.deepEqual(formula, {
    coordinate: 'B1',
    kind: 'formula',
    display: null,
    value: null,
    formula: '1+1',
    cachedResultAvailable: false,
  });
  assert.equal(projected.workbook.formulasWithoutCachedResultCount, 1);
});

test('does not expand a hostile declared worksheet range', () => {
  const workbook = XLSX.utils.book_new();
  const sheet = XLSX.utils.aoa_to_sheet([['only cell']]);
  XLSX.utils.book_append_sheet(workbook, sheet, 'Sparse');
  const input = replaceZipEntry(writeWorkbook(workbook), '/xl/worksheets/sheet1.xml', (xml) =>
    xml.replace('<dimension ref="A1"/>', '<dimension ref="A1:XFD1048576"/>'),
  );

  const projected = projectXlsx(input, { maxCells: 10 });

  assert.equal(projected.sheets[0].cells.length, 1);
  assert.equal(projected.sheets[0].cells[0].coordinate, 'A1');
});

test('rejects unsafe hyperlink schemes instead of rendering or indexing targets', () => {
  for (const target of [
    'javascript:alert(document.domain)',
    'file:///etc/passwd',
    'ftp://example.com/report.xlsx',
    'https://user:password@example.com/report.xlsx',
    'mailto://example.com/person',
    'relative/report.xlsx',
  ]) {
    const workbook = XLSX.utils.book_new();
    const sheet = XLSX.utils.aoa_to_sheet([['run']]);
    sheet.A1.l = { Target: target };
    XLSX.utils.book_append_sheet(workbook, sheet, 'Unsafe');

    expectProjectionError(() => projectXlsx(writeWorkbook(workbook)), 'unsupported_hyperlink');
  }
});

test('does not turn a HYPERLINK formula into a clickable target', () => {
  const workbook = XLSX.utils.book_new();
  const sheet = XLSX.utils.aoa_to_sheet([
    [{ t: 'str', f: 'HYPERLINK("https://example.com", "Open")', v: 'Open' }],
  ]);
  XLSX.utils.book_append_sheet(workbook, sheet, 'Formula link');

  const projected = projectXlsx(writeWorkbook(workbook));
  const cell = projected.sheets[0].cells[0];

  assert.equal(cell.link, undefined);
  assert.equal(projected.workbook.linkCount, 0);
  assert.equal(cell.formula, 'HYPERLINK("https://example.com", "Open")');
});

test('rejects macro-enabled and externally rooted package profiles', () => {
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet([['safe']]), 'Sheet 1');
  const input = writeWorkbook(workbook);
  const macroEnabled = replaceZipEntry(input, '/[Content_Types].xml', (xml) =>
    xml.replace(
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
      'application/vnd.ms-excel.sheet.macroEnabled.main+xml',
    ),
  );
  const externalRoot = replaceZipEntry(input, '/_rels/.rels', (xml) =>
    xml.replace(
      'Target="xl/workbook.xml"',
      'Target="https://example.com/workbook.xml" TargetMode="External"',
    ),
  );

  expectProjectionError(() => projectXlsx(macroEnabled), 'unsupported_xlsx_profile');
  expectProjectionError(() => projectXlsx(externalRoot), 'invalid_xlsx_package');
});

test('rejects content-type comment decoys and external workbook relationships', () => {
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet([['safe']]), 'Sheet 1');
  const input = writeWorkbook(workbook);
  const commentDecoy = replaceZipEntry(input, '/[Content_Types].xml', (xml) =>
    xml
      .replace(
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
        'application/vnd.ms-excel.sheet.macroEnabled.main+xml',
      )
      .replace(
        '</Types>',
        '<!-- application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml --></Types>',
      ),
  );
  const externalWorkbook = replaceZipEntry(input, '/xl/_rels/workbook.xml.rels', (xml) =>
    xml.replace(
      '</Relationships>',
      '<Relationship Id="rId999" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/externalLink" Target="https://example.com/external.xlsx" TargetMode="External"/></Relationships>',
    ),
  );
  const traversingRelationship = replaceZipEntry(input, '/xl/_rels/workbook.xml.rels', (xml) =>
    xml.replace('Target="styles.xml"', 'Target="../xl/styles.xml"'),
  );
  const worksheetDoctype = replaceZipEntry(input, '/xl/worksheets/sheet1.xml', (xml) =>
    xml.replace('?>', '?><!DOCTYPE worksheet [<!ENTITY workbook-data "not allowed">]>'),
  );

  expectProjectionError(() => projectXlsx(commentDecoy), 'unsupported_xlsx_profile');
  expectProjectionError(() => projectXlsx(externalWorkbook), 'unsupported_xlsx_profile');
  expectProjectionError(() => projectXlsx(traversingRelationship), 'unsupported_xlsx_profile');
  expectProjectionError(() => projectXlsx(worksheetDoctype), 'unsupported_xlsx_profile');
});

test('counts actual expanded bytes instead of trusting matching forged ZIP sizes', () => {
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet([['safe']]), 'Sheet 1');
  const inflated = replaceZipEntry(writeWorkbook(workbook), '/xl/worksheets/sheet1.xml', (xml) =>
    xml.replace('</worksheet>', `<!--${'A'.repeat(250_000)}--></worksheet>`),
  );
  const forged = forgeDeclaredExpandedSize(inflated, 'xl/worksheets/sheet1.xml', 1);
  const declaredAggregate = centralDirectoryEntries(forged).reduce(
    (total, entry) => total + entry.expandedBytes,
    0,
  );

  expectProjectionError(
    () => projectXlsx(forged, { maxExpandedBytes: declaredAggregate + 1 }),
    'expanded_size_limit_exceeded',
  );
});

test('omits internal links outside the viewer profile or into hidden content', () => {
  const farWorkbook = XLSX.utils.book_new();
  const farSheet = XLSX.utils.aoa_to_sheet([['jump']]);
  farSheet.A1.l = { Target: "#'Visible'!XFD1048576" };
  XLSX.utils.book_append_sheet(farWorkbook, farSheet, 'Visible');

  const farProjection = projectXlsx(writeWorkbook(farWorkbook));
  assert.equal(farProjection.sheets[0].cells[0].link, undefined);

  const hiddenWorkbook = XLSX.utils.book_new();
  const hiddenSheet = XLSX.utils.aoa_to_sheet([['jump'], ['hidden'], ['after']]);
  hiddenSheet.A1.l = { Target: "#'Visible'!A2" };
  hiddenSheet['!rows'] = [{}, { hidden: true }, {}];
  XLSX.utils.book_append_sheet(hiddenWorkbook, hiddenSheet, 'Visible');

  const hiddenProjection = projectXlsx(writeWorkbook(hiddenWorkbook));
  assert.equal(hiddenProjection.sheets[0].cells[0].link, undefined);

  const namedWorkbook = XLSX.utils.book_new();
  const namedSheet = XLSX.utils.aoa_to_sheet([['jump']]);
  namedSheet.A1.l = { Target: '#NamedDestination' };
  XLSX.utils.book_append_sheet(namedWorkbook, namedSheet, 'Visible');

  const namedProjection = projectXlsx(writeWorkbook(namedWorkbook));
  assert.equal(namedProjection.sheets[0].cells[0].link, undefined);
});

test('accepts exact bounded ZIP data descriptors and rejects corrupted descriptors', () => {
  const input = writeWorkbook(basicWorkbook());
  const storedInput = writeWorkbook(basicWorkbook(), false);

  assert.equal(projectXlsx(withDataDescriptor(input, true)).sheets[0].name, 'Visible data');
  assert.equal(projectXlsx(withDataDescriptor(input, false)).sheets[0].name, 'Visible data');
  assert.equal(projectXlsx(withDataDescriptor(storedInput, true)).sheets[0].name, 'Visible data');
  expectProjectionError(
    () => projectXlsx(withDataDescriptor(input, true, true)),
    'invalid_zip_structure',
  );
});

test('keeps a bounded blank internal-link destination inside the derived sheet extent', () => {
  const workbook = XLSX.utils.book_new();
  const source = XLSX.utils.aoa_to_sheet([['jump']]);
  source.A1.l = { Target: "#'Target'!C10" };
  XLSX.utils.book_append_sheet(workbook, source, 'Source');
  XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet([]), 'Target');

  const projected = projectXlsx(writeWorkbook(workbook));
  const target = projected.sheets.find((sheet) => sheet.name === 'Target');

  assert.equal(target.cells.length, 0);
  assert.equal(target.rowExtent, 10);
  assert.equal(target.columnExtent, 3);
});

test('rejects trailing ZIP ambiguity', () => {
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet([['safe']]), 'Sheet 1');
  const input = Buffer.concat([writeWorkbook(workbook), Buffer.from('trailing')]);

  expectProjectionError(() => projectXlsx(input), 'invalid_zip_structure');
});

test('fails closed when the visible non-empty cell limit is exceeded', () => {
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(
    workbook,
    XLSX.utils.aoa_to_sheet([['one'], ['two'], ['three']]),
    'Too many',
  );

  expectProjectionError(
    () => projectXlsx(writeWorkbook(workbook), { maxCells: 2 }),
    'cell_limit_exceeded',
  );
});

test('fails closed when a visible cell exceeds the preview row or column extent', () => {
  const rowWorkbook = XLSX.utils.book_new();
  const rowSheet = { A101: { t: 's', v: 'far row' }, '!ref': 'A101' };
  XLSX.utils.book_append_sheet(rowWorkbook, rowSheet, 'Rows');

  const columnWorkbook = XLSX.utils.book_new();
  const columnSheet = { Z1: { t: 's', v: 'far column' }, '!ref': 'Z1' };
  XLSX.utils.book_append_sheet(columnWorkbook, columnSheet, 'Columns');

  expectProjectionError(
    () => projectXlsx(writeWorkbook(rowWorkbook), { maxRows: 100 }),
    'worksheet_extent_limit_exceeded',
  );
  expectProjectionError(
    () => projectXlsx(writeWorkbook(columnWorkbook), { maxColumns: 25 }),
    'worksheet_extent_limit_exceeded',
  );
});

test('rejects a non-finite cached numeric result instead of emitting a typed null', () => {
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet([[42]]), 'Numbers');
  const input = replaceZipEntry(writeWorkbook(workbook), '/xl/worksheets/sheet1.xml', (xml) =>
    xml.replace('<v>42</v>', '<v>NaN</v>'),
  );

  expectProjectionError(() => projectXlsx(input), 'unsupported_cell_value');
});
