'use strict';

const XLSX = require('xlsx');
const { validateOpcProfile } = require('./opc-profile.cjs');
const { inspectZipPackage } = require('./zip-package.cjs');

const DEFAULT_LIMITS = Object.freeze({
  maxCells: 100_000,
  maxColumns: 256,
  maxCentralDirectoryBytes: 2 * 1024 * 1024,
  maxControlXmlBytes: 2 * 1024 * 1024,
  maxEntries: 2_000,
  maxEntryNameBytes: 1_024,
  maxEntryPathDepth: 16,
  maxExpandedBytes: 64 * 1024 * 1024,
  maxFormulaLength: 8_192,
  maxInputBytes: 16 * 1024 * 1024,
  maxManifestBytes: 16 * 1024 * 1024,
  maxMerges: 1_000,
  maxRows: 20_000,
  maxRelationships: 5_000,
  maxSearchTextBytes: 8 * 1024 * 1024,
  maxSheets: 20,
  maxStringBytes: 1 * 1024 * 1024,
  maxXmlAttributes: 1_000_000,
  maxXmlDepth: 128,
  maxXmlNodes: 1_000_000,
  maxXmlTextBytes: 32 * 1024 * 1024,
});

class XlsxProjectionError extends Error {
  constructor(code, message) {
    super(message);
    this.name = 'XlsxProjectionError';
    this.code = code;
  }
}

function fail(code, message) {
  throw new XlsxProjectionError(code, message);
}

function checkedLimits(overrides) {
  const limits = { ...DEFAULT_LIMITS, ...overrides };

  for (const [name, value] of Object.entries(limits)) {
    if (!Number.isSafeInteger(value) || value <= 0) {
      throw new TypeError(`${name} must be a positive safe integer`);
    }
  }

  return limits;
}

function preflightXlsx(input, limits) {
  const inspected = inspectZipPackage(input, limits, fail);
  validateOpcProfile(inspected.entries, limits, fail);

  return inspected;
}

function validatedSheetVisibility(workbook, limits) {
  const names = workbook.SheetNames;
  const facts = workbook.Workbook?.Sheets;

  if (
    !Array.isArray(names) ||
    names.length === 0 ||
    names.length > limits.maxSheets ||
    !Array.isArray(facts) ||
    facts.length !== names.length
  ) {
    fail('unsupported_xlsx_profile', 'The workbook sheet catalog is invalid.');
  }

  const visibility = new Map();

  for (const [index, name] of names.entries()) {
    const sheet = facts[index];
    const hidden = sheet?.Hidden ?? 0;

    if (
      typeof name !== 'string' ||
      name.length === 0 ||
      Buffer.byteLength(name, 'utf8') > 512 ||
      visibility.has(name) ||
      sheet === null ||
      typeof sheet !== 'object' ||
      Array.isArray(sheet) ||
      sheet.name !== name ||
      ![0, 1, 2].includes(hidden)
    ) {
      fail('unsupported_xlsx_profile', 'The workbook sheet catalog is ambiguous.');
    }

    visibility.set(name, hidden);
  }

  return visibility;
}

function hiddenIndexes(worksheet, limits) {
  const rows = new Set();
  const columns = new Set();

  for (const [index, row] of (worksheet['!rows'] ?? []).entries()) {
    if (row?.hidden !== true) {
      continue;
    }

    if (index >= limits.maxRows) {
      fail('worksheet_extent_limit_exceeded', 'Hidden worksheet rows exceed the preview profile.');
    }

    rows.add(index);
  }

  for (const [index, column] of (worksheet['!cols'] ?? []).entries()) {
    if (column?.hidden !== true) {
      continue;
    }

    if (index >= limits.maxColumns) {
      fail(
        'worksheet_extent_limit_exceeded',
        'Hidden worksheet columns exceed the preview profile.',
      );
    }

    columns.add(index);
  }

  return { columns, rows };
}

function hasIndexInRange(indexes, start, end) {
  for (const index of indexes) {
    if (index > end) {
      return false;
    }

    if (index >= start) {
      return true;
    }
  }

  return false;
}

function normalizedLink(
  target,
  workbook,
  sourceSheet,
  limits,
  hiddenIndexesBySheet,
  visibilityBySheet,
) {
  if (typeof target !== 'string' || target.length === 0 || target.length > 8_192) {
    fail('unsupported_hyperlink', 'The workbook contains an invalid hyperlink.');
  }

  if (/[\u0000-\u001f\u007f]/u.test(target)) {
    fail('unsupported_hyperlink', 'The workbook contains a control character in a hyperlink.');
  }

  if (target.startsWith('#')) {
    const destination = target.slice(1);
    const match =
      /^(?:(?:'((?:[^']|'')+)'|([^'!]+))!)?\$?([A-Z]{1,3})\$?([1-9][0-9]{0,6})(?::\$?[A-Z]{1,3}\$?[1-9][0-9]{0,6})?$/iu.exec(
        destination,
      );

    if (!match) {
      return null;
    }

    const sheetName = match[1]?.replaceAll("''", "'") ?? match[2] ?? sourceSheet;

    if (!workbook.SheetNames.includes(sheetName) || visibilityBySheet.get(sheetName) !== 0) {
      return null;
    }

    const columnName = match[3].toUpperCase();
    const column = XLSX.utils.decode_col(columnName);
    const row = Number.parseInt(match[4], 10) - 1;

    const hidden = hiddenIndexesBySheet.get(sheetName);

    if (
      column >= limits.maxColumns ||
      row >= limits.maxRows ||
      hidden?.columns.has(column) === true ||
      hidden?.rows.has(row) === true
    ) {
      return null;
    }

    return {
      kind: 'internal',
      sheet: sheetName,
      coordinate: `${columnName}${match[4]}`,
    };
  }

  let url;

  try {
    url = new URL(target);
  } catch {
    fail('unsupported_hyperlink', 'The workbook contains a relative or malformed hyperlink.');
  }

  if (
    !['http:', 'https:', 'mailto:'].includes(url.protocol) ||
    url.username !== '' ||
    url.password !== '' ||
    (url.protocol === 'mailto:' && target.slice(0, 9).toLowerCase() === 'mailto://')
  ) {
    fail('unsupported_hyperlink', 'The workbook contains an unsupported hyperlink scheme.');
  }

  return { kind: 'external', target: url.href };
}

function cellValue(cell, limits) {
  const hasCachedValue = cell.v !== undefined && cell.v !== null;
  let kind;
  let value;

  switch (cell.t) {
    case 'b':
      if (hasCachedValue && typeof cell.v !== 'boolean') {
        fail('unsupported_cell_value', 'The workbook contains an invalid boolean cell value.');
      }

      kind = 'boolean';
      value = hasCachedValue ? cell.v : null;
      break;
    case 'd':
      if (hasCachedValue && (!(cell.v instanceof Date) || Number.isNaN(cell.v.getTime()))) {
        fail('unsupported_cell_value', 'The workbook contains an invalid date cell value.');
      }

      kind = 'date';
      value = hasCachedValue ? cell.v.toISOString() : null;
      break;
    case 'e':
      kind = 'error';
      value = hasCachedValue ? String(cell.w ?? cell.v) : null;
      break;
    case 'n':
      if (hasCachedValue && !Number.isFinite(cell.v)) {
        fail('unsupported_cell_value', 'The workbook contains an invalid numeric cell value.');
      }

      kind = 'number';
      value = hasCachedValue ? cell.v : null;
      break;
    case 's':
    case 'str':
      if (hasCachedValue && typeof cell.v !== 'string') {
        fail('unsupported_cell_value', 'The workbook contains an invalid string cell value.');
      }

      kind = 'string';
      value = hasCachedValue ? cell.v : null;
      break;
    case 'z':
      kind = 'blank';
      value = null;
      break;
    default:
      fail('unsupported_cell_type', 'The workbook contains an unsupported cell value type.');
  }

  const display = hasCachedValue ? String(cell.w ?? value ?? '') : null;

  if (
    (typeof value === 'string' && Buffer.byteLength(value, 'utf8') > limits.maxStringBytes) ||
    (display !== null && Buffer.byteLength(display, 'utf8') > limits.maxStringBytes)
  ) {
    fail('string_limit_exceeded', 'The workbook contains an oversized cell string.');
  }

  return { kind, value, display, hasCachedValue };
}

function projectXlsxWithFacts(input, limitOverrides = {}) {
  const limits = checkedLimits(limitOverrides);
  const inspected = preflightXlsx(input, limits);

  let workbook;

  try {
    workbook = XLSX.read(inspected.canonicalBytes, {
      type: 'buffer',
      bookDeps: false,
      bookFiles: false,
      bookProps: false,
      bookSheets: false,
      bookVBA: false,
      cellFormula: true,
      cellDates: true,
      cellHTML: false,
      cellNF: false,
      cellStyles: true,
      cellText: true,
      dense: false,
      raw: true,
      WTF: true,
      xlfn: true,
    });
  } catch {
    fail('malformed_xlsx', 'The workbook could not be parsed under the accepted profile.');
  }

  const visibilityBySheet = validatedSheetVisibility(workbook, limits);
  const visibleSheetNames = workbook.SheetNames.filter((name) => visibilityBySheet.get(name) === 0);

  if (visibleSheetNames.length === 0) {
    fail('unsupported_xlsx_profile', 'The workbook has no visible worksheet.');
  }

  const hiddenIndexesBySheet = new Map(
    visibleSheetNames.map((name) => [name, hiddenIndexes(workbook.Sheets[name] ?? {}, limits)]),
  );

  const omittedHiddenSheetCount = workbook.SheetNames.length - visibleSheetNames.length;
  const sheets = [];
  const searchLines = [];
  let visibleCellCount = 0;
  let formulaCount = 0;
  let formulasWithoutCachedResultCount = 0;
  let linkCount = 0;
  let mergeCount = 0;

  for (const name of visibleSheetNames) {
    const worksheet = workbook.Sheets[name];

    if (!worksheet || Array.isArray(worksheet)) {
      fail('malformed_xlsx', 'The workbook contains an invalid worksheet projection.');
    }

    const hidden = hiddenIndexesBySheet.get(name);
    const hiddenRows = hidden.rows;
    const hiddenColumns = hidden.columns;

    const cells = [];
    const merges = [];
    let rowExtent = 0;
    let columnExtent = 0;
    const coordinates = Object.keys(worksheet)
      .filter((key) => !key.startsWith('!'))
      .sort((left, right) => {
        const a = XLSX.utils.decode_cell(left);
        const b = XLSX.utils.decode_cell(right);

        return a.r === b.r ? a.c - b.c : a.r - b.r;
      });

    for (const coordinate of coordinates) {
      if (!/^[A-Z]{1,3}[1-9][0-9]{0,6}$/u.test(coordinate)) {
        fail('invalid_cell_coordinate', 'The workbook contains an invalid cell coordinate.');
      }

      const decoded = XLSX.utils.decode_cell(coordinate);

      if (decoded.r > 1_048_575 || decoded.c > 16_383) {
        fail('invalid_cell_coordinate', 'The workbook contains an out-of-range cell coordinate.');
      }

      if (decoded.r >= limits.maxRows || decoded.c >= limits.maxColumns) {
        fail(
          'worksheet_extent_limit_exceeded',
          'The workbook exceeds the supported preview row or column extent.',
        );
      }

      if (hiddenRows.has(decoded.r) || hiddenColumns.has(decoded.c)) {
        continue;
      }

      visibleCellCount += 1;
      rowExtent = Math.max(rowExtent, decoded.r + 1);
      columnExtent = Math.max(columnExtent, decoded.c + 1);

      if (visibleCellCount > limits.maxCells) {
        fail('cell_limit_exceeded', 'The workbook contains too many visible cells.');
      }

      const source = worksheet[coordinate];
      const normalized = cellValue(source, limits);

      if (
        normalized.kind !== 'blank' &&
        !normalized.hasCachedValue &&
        typeof source.f !== 'string'
      ) {
        fail('unsupported_cell_value', 'The workbook contains an untyped missing cell value.');
      }

      const cell = {
        coordinate,
        kind:
          typeof source.f === 'string' && !normalized.hasCachedValue ? 'formula' : normalized.kind,
        display: normalized.display,
        value: normalized.value,
      };

      if (typeof source.f === 'string') {
        if (Buffer.byteLength(source.f, 'utf8') > limits.maxFormulaLength) {
          fail('formula_limit_exceeded', 'The workbook contains an oversized formula.');
        }

        formulaCount += 1;
        if (!normalized.hasCachedValue) {
          formulasWithoutCachedResultCount += 1;
        }
        cell.formula = source.f;
        cell.cachedResultAvailable = normalized.hasCachedValue;
      }

      if (source.l?.Target !== undefined) {
        const link = normalizedLink(
          source.l.Target,
          workbook,
          name,
          limits,
          hiddenIndexesBySheet,
          visibilityBySheet,
        );
        if (link !== null) {
          cell.link = link;
          linkCount += 1;
        }
      }

      cells.push(cell);

      if (normalized.display !== null && normalized.display !== '') {
        searchLines.push(`[${name}] ${coordinate} ${normalized.display}`);
      }

      if (cell.formula !== undefined) {
        searchLines.push(`[${name}] ${coordinate} =${cell.formula}`);
      }
    }

    const mergeRanges = worksheet['!merges'] ?? [];

    if (!Array.isArray(mergeRanges) || mergeRanges.length > limits.maxMerges) {
      fail('merge_limit_exceeded', 'The workbook contains too many merged ranges.');
    }

    mergeRanges.sort((left, right) =>
      left.s.r === right.s.r
        ? left.s.c === right.s.c
          ? left.e.r === right.e.r
            ? left.e.c - right.e.c
            : left.e.r - right.e.r
          : left.s.c - right.s.c
        : left.s.r - right.s.r,
    );

    for (const range of mergeRanges) {
      if (
        !Number.isSafeInteger(range?.s?.r) ||
        !Number.isSafeInteger(range?.s?.c) ||
        !Number.isSafeInteger(range?.e?.r) ||
        !Number.isSafeInteger(range?.e?.c) ||
        range.s.r < 0 ||
        range.s.c < 0 ||
        range.e.r < range.s.r ||
        range.e.c < range.s.c
      ) {
        fail('unsupported_merged_range', 'The workbook contains an invalid merged range.');
      }

      if (range.e.r >= limits.maxRows || range.e.c >= limits.maxColumns) {
        fail(
          'worksheet_extent_limit_exceeded',
          'A merged range exceeds the supported preview row or column extent.',
        );
      }

      const intersectsHidden =
        hasIndexInRange(hiddenRows, range.s.r, range.e.r) ||
        hasIndexInRange(hiddenColumns, range.s.c, range.e.c);

      if (intersectsHidden) {
        fail('unsupported_merged_range', 'A merged range crosses hidden preview content.');
      }

      for (const accepted of merges) {
        const prior = accepted.decoded;
        const separated =
          range.e.r < prior.s.r ||
          range.s.r > prior.e.r ||
          range.e.c < prior.s.c ||
          range.s.c > prior.e.c;

        if (!separated) {
          fail('unsupported_merged_range', 'The workbook contains overlapping merged ranges.');
        }
      }

      rowExtent = Math.max(rowExtent, range.e.r + 1);
      columnExtent = Math.max(columnExtent, range.e.c + 1);
      merges.push({
        start: XLSX.utils.encode_cell(range.s),
        end: XLSX.utils.encode_cell(range.e),
        decoded: range,
      });
      mergeCount += 1;

      if (mergeCount > limits.maxMerges) {
        fail('merge_limit_exceeded', 'The workbook contains too many merged ranges.');
      }
    }

    sheets.push({
      name,
      rowExtent,
      columnExtent,
      omittedHiddenRowCount: hiddenRows.size,
      omittedHiddenColumnCount: hiddenColumns.size,
      merges: merges.map(({ start, end }) => ({ start, end })),
      cells,
    });
  }

  const projectedSheetsByName = new Map(sheets.map((sheet) => [sheet.name, sheet]));

  for (const sheet of sheets) {
    for (const cell of sheet.cells) {
      if (cell.link?.kind !== 'internal') {
        continue;
      }

      const destinationSheet = projectedSheetsByName.get(cell.link.sheet);
      const destination = XLSX.utils.decode_cell(cell.link.coordinate);

      destinationSheet.rowExtent = Math.max(destinationSheet.rowExtent, destination.r + 1);
      destinationSheet.columnExtent = Math.max(destinationSheet.columnExtent, destination.c + 1);
    }
  }

  const searchText = searchLines.join('\n');

  if (Buffer.byteLength(searchText, 'utf8') > limits.maxSearchTextBytes) {
    fail('search_text_limit_exceeded', 'The workbook search projection is too large.');
  }

  const manifest = {
    schema: 'xlsx-view-manifest-v1',
    profile: 'xlsx-typed-view-v1',
    workbook: {
      visibleSheetCount: sheets.length,
      omittedHiddenSheetCount,
      cellCount: visibleCellCount,
      formulaCount,
      formulasWithoutCachedResultCount,
      linkCount,
      mergeCount,
      truncated: false,
    },
    sheets,
    searchText,
  };

  if (Buffer.byteLength(JSON.stringify(manifest), 'utf8') > limits.maxManifestBytes) {
    fail('manifest_limit_exceeded', 'The workbook preview manifest is too large.');
  }

  return {
    manifest,
    package: {
      entryCount: inspected.entries.size,
      expandedBytes: inspected.actualExpandedBytes,
    },
  };
}

function projectXlsx(input, limitOverrides = {}) {
  return projectXlsxWithFacts(input, limitOverrides).manifest;
}

module.exports = {
  XlsxProjectionError,
  preflightXlsx,
  projectXlsx,
  projectXlsxWithFacts,
};
