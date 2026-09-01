export const MAX_ROWS = 20_000;
export const MAX_COLUMNS = 256;
export const MAX_CELLS = 100_000;

const MAX_SHEETS = 20;
const MAX_FORMULA_BYTES = 8_192;
const MAX_MERGES = 1_000;
const MAX_STRING_BYTES = 1_048_576;
const MAX_SEARCH_TEXT_BYTES = 8_388_608;
const CELL_KINDS = new Set(['blank', 'boolean', 'date', 'error', 'formula', 'number', 'string']);
const CELL_KEYS = new Set([
  'cachedResultAvailable',
  'coordinate',
  'display',
  'formula',
  'kind',
  'link',
  'value',
]);
const MANIFEST_KEYS = new Set(['profile', 'schema', 'searchText', 'sheets', 'workbook']);
const WORKBOOK_KEYS = new Set([
  'cellCount',
  'formulaCount',
  'formulasWithoutCachedResultCount',
  'linkCount',
  'mergeCount',
  'omittedHiddenSheetCount',
  'truncated',
  'visibleSheetCount',
]);
const SHEET_KEYS = new Set([
  'cells',
  'columnExtent',
  'merges',
  'name',
  'omittedHiddenColumnCount',
  'omittedHiddenRowCount',
  'rowExtent',
]);

function utf8Bytes(value) {
  return new TextEncoder().encode(value).byteLength;
}

function isBoundedInteger(value, maximum) {
  return Number.isInteger(value) && value >= 0 && value <= maximum;
}

function columnIndex(label) {
  let result = 0;

  for (const character of label) {
    result = result * 26 + character.charCodeAt(0) - 64;
  }

  return result - 1;
}

export function columnLabel(index) {
  if (!Number.isInteger(index) || index < 0 || index >= MAX_COLUMNS) {
    throw new Error('Invalid column coordinate.');
  }

  let current = index + 1;
  let label = '';

  while (current > 0) {
    const remainder = (current - 1) % 26;
    label = String.fromCharCode(65 + remainder) + label;
    current = Math.floor((current - 1) / 26);
  }

  return label;
}

export function decodedCoordinate(coordinate) {
  if (typeof coordinate !== 'string') {
    throw new Error('Invalid cell coordinate.');
  }

  const match = /^([A-Z]{1,3})([1-9][0-9]{0,4})$/u.exec(coordinate);

  if (!match) {
    throw new Error('Invalid cell coordinate.');
  }

  const column = columnIndex(match[1]);
  const row = Number.parseInt(match[2], 10) - 1;

  if (column >= MAX_COLUMNS || row >= MAX_ROWS) {
    throw new Error('Cell coordinate exceeds the viewer profile.');
  }

  return { column, row };
}

export function safeExternalTarget(target) {
  if (
    typeof target !== 'string' ||
    Array.from(target).some((character) => {
      const codePoint = character.codePointAt(0);

      return codePoint !== undefined && (codePoint <= 31 || codePoint === 127);
    })
  ) {
    return null;
  }

  try {
    const url = new URL(target);

    if (
      !['http:', 'https:', 'mailto:'].includes(url.protocol) ||
      url.username !== '' ||
      url.password !== ''
    ) {
      return null;
    }

    return url.href;
  } catch {
    return null;
  }
}

function validateCellValue(cell) {
  const kindMatchesValue =
    (cell.kind === 'blank' && cell.value === null) ||
    (cell.kind === 'boolean' && typeof cell.value === 'boolean') ||
    (cell.kind === 'number' && typeof cell.value === 'number') ||
    (['date', 'error', 'string'].includes(cell.kind) && typeof cell.value === 'string') ||
    (cell.kind === 'formula' && cell.value === null);

  if (
    !CELL_KINDS.has(cell.kind) ||
    !kindMatchesValue ||
    ![null, 'boolean', 'number', 'string'].includes(
      cell.value === null ? null : typeof cell.value,
    ) ||
    (typeof cell.value === 'number' && !Number.isFinite(cell.value)) ||
    ![null, 'string'].includes(cell.display === null ? null : typeof cell.display) ||
    (typeof cell.value === 'string' && utf8Bytes(cell.value) > MAX_STRING_BYTES) ||
    (typeof cell.display === 'string' && utf8Bytes(cell.display) > MAX_STRING_BYTES)
  ) {
    throw new Error('Invalid cell manifest.');
  }

  if (cell.formula === undefined) {
    if (cell.cachedResultAvailable !== undefined || cell.kind === 'formula') {
      throw new Error('Invalid cell manifest.');
    }

    return false;
  }

  if (
    typeof cell.formula !== 'string' ||
    utf8Bytes(cell.formula) > MAX_FORMULA_BYTES ||
    typeof cell.cachedResultAvailable !== 'boolean' ||
    (cell.cachedResultAvailable === false &&
      (cell.kind !== 'formula' || cell.value !== null || cell.display !== null)) ||
    (cell.cachedResultAvailable === true && cell.kind === 'formula')
  ) {
    throw new Error('Invalid formula cell manifest.');
  }

  return true;
}

function validateLinkShape(link) {
  if (link?.kind === 'external') {
    if (
      Object.keys(link).some((key) => !['kind', 'target'].includes(key)) ||
      safeExternalTarget(link.target) === null
    ) {
      throw new Error('Invalid hyperlink manifest.');
    }

    return;
  }

  if (
    link?.kind !== 'internal' ||
    Object.keys(link).some((key) => !['coordinate', 'kind', 'sheet'].includes(key)) ||
    typeof link.sheet !== 'string' ||
    typeof link.coordinate !== 'string'
  ) {
    throw new Error('Invalid hyperlink manifest.');
  }
}

export function validatedManifest(candidate) {
  if (
    candidate === null ||
    typeof candidate !== 'object' ||
    Array.isArray(candidate) ||
    Object.keys(candidate).some((key) => !MANIFEST_KEYS.has(key)) ||
    candidate?.schema !== 'xlsx-view-manifest-v1' ||
    candidate?.profile !== 'xlsx-typed-view-v1' ||
    !Array.isArray(candidate.sheets) ||
    candidate.sheets.length === 0 ||
    candidate.sheets.length > MAX_SHEETS ||
    typeof candidate.searchText !== 'string' ||
    utf8Bytes(candidate.searchText) > MAX_SEARCH_TEXT_BYTES
  ) {
    throw new Error('Invalid workbook manifest.');
  }

  const workbook = candidate.workbook;

  if (
    workbook === null ||
    typeof workbook !== 'object' ||
    Array.isArray(workbook) ||
    Object.keys(workbook).some((key) => !WORKBOOK_KEYS.has(key)) ||
    workbook.visibleSheetCount !== candidate.sheets.length ||
    !isBoundedInteger(workbook.omittedHiddenSheetCount, MAX_SHEETS) ||
    candidate.sheets.length + workbook.omittedHiddenSheetCount > MAX_SHEETS ||
    !isBoundedInteger(workbook.cellCount, MAX_CELLS) ||
    !isBoundedInteger(workbook.formulaCount, MAX_CELLS) ||
    !isBoundedInteger(workbook.formulasWithoutCachedResultCount, MAX_CELLS) ||
    workbook.formulasWithoutCachedResultCount > workbook.formulaCount ||
    !isBoundedInteger(workbook.linkCount, MAX_CELLS) ||
    !isBoundedInteger(workbook.mergeCount, MAX_MERGES) ||
    workbook.truncated !== false
  ) {
    throw new Error('Invalid workbook facts.');
  }

  let cellCount = 0;
  let formulaCount = 0;
  let formulasWithoutCachedResultCount = 0;
  let linkCount = 0;
  let mergeCount = 0;
  const sheetsByName = new Map();
  const internalLinks = [];

  for (const sheet of candidate.sheets) {
    if (
      sheet === null ||
      typeof sheet !== 'object' ||
      Array.isArray(sheet) ||
      Object.keys(sheet).some((key) => !SHEET_KEYS.has(key)) ||
      typeof sheet?.name !== 'string' ||
      sheet.name.length === 0 ||
      utf8Bytes(sheet.name) > 512 ||
      sheetsByName.has(sheet.name) ||
      !isBoundedInteger(sheet.rowExtent, MAX_ROWS) ||
      !isBoundedInteger(sheet.columnExtent, MAX_COLUMNS) ||
      !isBoundedInteger(sheet.omittedHiddenRowCount, MAX_ROWS) ||
      !isBoundedInteger(sheet.omittedHiddenColumnCount, MAX_COLUMNS) ||
      !Array.isArray(sheet.cells) ||
      !Array.isArray(sheet.merges) ||
      sheet.merges.length > MAX_MERGES
    ) {
      throw new Error('Invalid worksheet manifest.');
    }

    const coordinates = new Set();
    let previousColumn = -1;
    let previousRow = -1;

    for (const cell of sheet.cells) {
      if (
        cell === null ||
        typeof cell !== 'object' ||
        Object.keys(cell).some((key) => !CELL_KEYS.has(key)) ||
        coordinates.has(cell.coordinate)
      ) {
        throw new Error('Invalid worksheet manifest.');
      }

      const decoded = decodedCoordinate(cell.coordinate);

      if (
        decoded.row >= sheet.rowExtent ||
        decoded.column >= sheet.columnExtent ||
        decoded.row < previousRow ||
        (decoded.row === previousRow && decoded.column <= previousColumn)
      ) {
        throw new Error('Invalid worksheet coordinate order or extent.');
      }

      previousColumn = decoded.column;
      previousRow = decoded.row;
      coordinates.add(cell.coordinate);
      cellCount += 1;

      if (cellCount > MAX_CELLS) {
        throw new Error('Workbook cell limit exceeded.');
      }

      if (validateCellValue(cell)) {
        formulaCount += 1;
        if (cell.cachedResultAvailable === false) {
          formulasWithoutCachedResultCount += 1;
        }
      }

      if (cell.link !== undefined) {
        validateLinkShape(cell.link);
        linkCount += 1;

        if (cell.link.kind === 'internal') {
          internalLinks.push(cell.link);
        }
      }
    }

    const decodedMerges = [];

    for (const merge of sheet.merges) {
      if (
        merge === null ||
        typeof merge !== 'object' ||
        Array.isArray(merge) ||
        Object.keys(merge).sort().join(',') !== 'end,start'
      ) {
        throw new Error('Invalid merged range manifest.');
      }

      const start = decodedCoordinate(merge.start);
      const end = decodedCoordinate(merge.end);

      if (
        start.row > end.row ||
        start.column > end.column ||
        end.row >= sheet.rowExtent ||
        end.column >= sheet.columnExtent
      ) {
        throw new Error('Invalid merged range manifest.');
      }

      for (const accepted of decodedMerges) {
        const separated =
          end.row < accepted.start.row ||
          start.row > accepted.end.row ||
          end.column < accepted.start.column ||
          start.column > accepted.end.column;

        if (!separated) {
          throw new Error('Overlapping merged range manifest.');
        }
      }

      decodedMerges.push({ start, end });
      mergeCount += 1;
    }

    sheetsByName.set(sheet.name, sheet);
  }

  for (const link of internalLinks) {
    const destinationSheet = sheetsByName.get(link.sheet);

    if (!destinationSheet) {
      throw new Error('Invalid hyperlink manifest.');
    }

    const destination = decodedCoordinate(link.coordinate);

    if (
      destination.row >= destinationSheet.rowExtent ||
      destination.column >= destinationSheet.columnExtent
    ) {
      throw new Error('Invalid hyperlink manifest.');
    }
  }

  if (
    cellCount !== workbook.cellCount ||
    formulaCount !== workbook.formulaCount ||
    formulasWithoutCachedResultCount !== workbook.formulasWithoutCachedResultCount ||
    linkCount !== workbook.linkCount ||
    mergeCount !== workbook.mergeCount
  ) {
    throw new Error('Workbook facts do not match the worksheet manifest.');
  }

  return candidate;
}
