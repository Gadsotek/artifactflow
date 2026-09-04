export const manifestFixture = {
  schema: 'xlsx-view-manifest-v1',
  profile: 'xlsx-typed-view-v1',
  workbook: {
    visibleSheetCount: 2,
    omittedHiddenSheetCount: 1,
    cellCount: 9,
    formulaCount: 2,
    formulasWithoutCachedResultCount: 1,
    linkCount: 2,
    mergeCount: 1,
    truncated: false,
  },
  sheets: [
    {
      name: 'Sales <script>alert(1)</script>',
      rowExtent: 4,
      columnExtent: 4,
      omittedHiddenRowCount: 1,
      omittedHiddenColumnCount: 0,
      merges: [{ start: 'C2', end: 'D2' }],
      cells: [
        {
          coordinate: 'A1',
          kind: 'string',
          display: '<img src=x onerror=window.__xlsxPwned=true>',
          value: '<img src=x onerror=window.__xlsxPwned=true>',
        },
        {
          coordinate: 'B1',
          kind: 'number',
          display: '42',
          value: 42,
          formula: 'SUM(B2:B4)',
          cachedResultAvailable: true,
        },
        {
          coordinate: 'C1',
          kind: 'string',
          display: 'Open report',
          value: 'Open report',
          link: {
            kind: 'external',
            target: 'https://example.com/report',
          },
        },
        {
          coordinate: 'D1',
          kind: 'string',
          display: 'Jump to notes',
          value: 'Jump to notes',
          link: {
            kind: 'internal',
            sheet: 'Notes',
            coordinate: 'A1',
          },
        },
        { coordinate: 'B2', kind: 'number', display: '10', value: 10 },
        { coordinate: 'B3', kind: 'number', display: '12', value: 12 },
        { coordinate: 'B4', kind: 'number', display: '20', value: 20 },
      ],
    },
    {
      name: 'Notes',
      rowExtent: 2,
      columnExtent: 2,
      omittedHiddenRowCount: 0,
      omittedHiddenColumnCount: 0,
      merges: [],
      cells: [
        { coordinate: 'A1', kind: 'string', display: 'Review complete', value: 'Review complete' },
        {
          coordinate: 'B2',
          kind: 'formula',
          display: null,
          value: null,
          formula: 'NOW()',
          cachedResultAvailable: false,
        },
      ],
    },
  ],
  searchText: '',
};

export function stressManifest(rowCount = 20_000) {
  const cells = [];

  for (let row = 1; row <= rowCount; row += 1) {
    cells.push({
      coordinate: `A${row}`,
      kind: 'number',
      display: String(row),
      value: row,
    });
  }

  return {
    schema: 'xlsx-view-manifest-v1',
    profile: 'xlsx-typed-view-v1',
    workbook: {
      visibleSheetCount: 1,
      omittedHiddenSheetCount: 0,
      cellCount: rowCount,
      formulaCount: 0,
      formulasWithoutCachedResultCount: 0,
      linkCount: 0,
      mergeCount: 0,
      truncated: false,
    },
    sheets: [
      {
        name: '20k rows',
        rowExtent: rowCount,
        columnExtent: 1,
        omittedHiddenRowCount: 0,
        omittedHiddenColumnCount: 0,
        merges: [],
        cells,
      },
    ],
    searchText: '',
  };
}
