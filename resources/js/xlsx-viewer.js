import {
  FormatModule,
  InteractionModule,
  KeybindingsModule,
  ResizeTableModule,
  Tabulator,
} from 'tabulator-tables';
import {
  columnLabel,
  decodedCoordinate,
  safeExternalTarget,
  validatedManifest,
} from './xlsx-viewer-contract';

Tabulator.registerModule([FormatModule, InteractionModule, KeybindingsModule, ResizeTableModule]);

const tabs = document.getElementById('sheet-tabs');
const grid = document.getElementById('workbook-grid');
const status = document.getElementById('viewer-status');
const selectedCoordinate = document.getElementById('selected-coordinate');
const selectedValue = document.getElementById('selected-value');
const selectedFormula = document.getElementById('selected-formula');
const previewReadyRequest = 'artifactflow:preview-ready-request';
const previewReadyResponse = 'artifactflow:preview-ready';
const externalLinkRequest = 'artifactflow:xlsx-external-link-request';

let activeTable = null;
let activeManifest = null;
let pendingInternalDestination = null;

window.addEventListener('message', (event) => {
  const payload = event.data;

  if (
    event.source !== window.parent ||
    typeof payload !== 'object' ||
    payload === null ||
    payload.type !== previewReadyRequest ||
    !Number.isSafeInteger(payload.requestId)
  ) {
    return;
  }

  try {
    window.parent.postMessage({ type: previewReadyResponse, requestId: payload.requestId }, '*');
  } catch {
    // The embedding page may already be gone.
  }
});

function showCellDetails(entry, coordinate) {
  const hasFormula = typeof entry.formula === 'string';

  selectedCoordinate.textContent = coordinate;
  selectedValue.textContent =
    entry.cachedResultAvailable === false
      ? 'Cached result unavailable'
      : `Cached value: ${entry.display ?? ''}`;
  selectedFormula.classList.toggle('formula--empty', !hasFormula);
  selectedFormula.textContent = hasFormula ? `=${entry.formula}` : 'No formula';
}

function activateInternalLink(link) {
  if (
    link?.kind !== 'internal' ||
    typeof link.sheet !== 'string' ||
    typeof link.coordinate !== 'string'
  ) {
    return;
  }

  const sheetIndex = activeManifest.sheets.findIndex((sheet) => sheet.name === link.sheet);

  if (sheetIndex < 0) {
    return;
  }

  decodedCoordinate(link.coordinate);
  pendingInternalDestination = link.coordinate;
  renderSheet(sheetIndex);
}

function cellElement(entry) {
  const label =
    entry?.display ?? (entry?.cachedResultAvailable === false ? 'Cached result unavailable' : '');

  if (entry?.link?.kind === 'external') {
    const target = safeExternalTarget(entry.link.target);

    if (target !== null) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'external-link';
      button.textContent = label;
      button.addEventListener('click', () => {
        try {
          window.parent.postMessage({ type: externalLinkRequest, target }, '*');
        } catch {
          // The embedding page may already be gone.
        }
      });

      return button;
    }
  }

  if (entry?.link?.kind === 'internal') {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'internal-link';
    button.textContent = label;
    button.addEventListener('click', () => activateInternalLink(entry.link));

    return button;
  }

  const text = document.createElement('span');
  text.textContent = label;

  if (typeof entry?._mergeEnd === 'string') {
    const mergeHint = document.createElement('span');
    mergeHint.className = 'merge-hint';
    mergeHint.textContent = ` merged through ${entry._mergeEnd}`;
    text.append(mergeHint);
  }

  return text;
}

function rowData(sheet) {
  const rows = Array.from({ length: sheet.rowExtent }, (_, index) => ({
    _row: index + 1,
  }));

  for (const cell of sheet.cells) {
    const decoded = decodedCoordinate(cell.coordinate);
    rows[decoded.row][columnLabel(decoded.column)] = cell;
  }

  for (const merge of sheet.merges) {
    const start = decodedCoordinate(merge.start);
    const field = columnLabel(start.column);
    const existing = rows[start.row][field];
    rows[start.row][field] = {
      ...(existing ?? {
        coordinate: merge.start,
        kind: 'blank',
        display: null,
        value: null,
      }),
      _mergeEnd: merge.end,
    };
  }

  return rows;
}

function tableColumns(sheet) {
  const columns = [
    {
      title: '#',
      field: '_row',
      width: 72,
      minWidth: 72,
      maxWidth: 72,
      headerSort: false,
      resizable: false,
      hozAlign: 'right',
    },
  ];

  for (let index = 0; index < sheet.columnExtent; index += 1) {
    const field = columnLabel(index);

    columns.push({
      title: field,
      field,
      width: 180,
      minWidth: 96,
      headerSort: false,
      resizable: false,
      formatter: (component) => cellElement(component.getValue()),
      cellClick: (_event, component) => {
        const entry = component.getValue();

        if (entry !== undefined) {
          showCellDetails(entry, entry.coordinate);
        }
      },
    });
  }

  return columns;
}

function updateTabs(activeIndex) {
  tabs.replaceChildren();

  activeManifest.sheets.forEach((sheet, index) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.role = 'tab';
    button.textContent = sheet.name;
    button.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');
    button.addEventListener('click', () => renderSheet(index));
    tabs.append(button);
  });
}

function renderSheet(index) {
  const sheet = activeManifest.sheets[index];

  if (!sheet) {
    throw new Error('Unknown worksheet.');
  }

  activeTable?.destroy();
  updateTabs(index);
  status.textContent = `Showing ${sheet.name}. ${sheet.rowExtent} rows, ${sheet.columnExtent} columns, ${sheet.merges.length} merged ranges.`;

  activeTable = new Tabulator(grid, {
    data: rowData(sheet),
    columns: tableColumns(sheet),
    height: '100%',
    index: '_row',
    layout: 'fitDataTable',
    placeholder: 'This visible worksheet is empty.',
    renderVertical: 'virtual',
    renderVerticalBuffer: 160,
  });

  activeTable.on('tableBuilt', async () => {
    if (pendingInternalDestination !== null) {
      const destination = decodedCoordinate(pendingInternalDestination);
      const field = columnLabel(destination.column);

      await activeTable.scrollToCell(destination.row + 1, field, 'center', false);
      const destinationCell = activeTable.getCell(destination.row + 1, field);
      destinationCell?.getElement().focus({ preventScroll: true });
      pendingInternalDestination = null;
    }

    document.documentElement.dataset.viewerReady = 'true';
  });
}

function embeddedManifest() {
  const source = document.getElementById('xlsx-manifest');

  if (!(source instanceof HTMLScriptElement)) {
    throw new Error('Workbook manifest is missing.');
  }

  const encoded = source.textContent?.trim() ?? '';

  if (encoded === '' || !/^[A-Za-z0-9_-]+$/u.test(encoded)) {
    throw new Error('Workbook manifest encoding is invalid.');
  }

  const standard = encoded.replaceAll('-', '+').replaceAll('_', '/');
  const padded = standard.padEnd(Math.ceil(standard.length / 4) * 4, '=');
  const binary = atob(padded);
  const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
  const json = new TextDecoder('utf-8', { fatal: true }).decode(bytes);

  return JSON.parse(json);
}

try {
  activeManifest = validatedManifest(embeddedManifest());
  renderSheet(0);
} catch {
  status.textContent = 'This workbook preview is unavailable.';
  document.documentElement.dataset.viewerReady = 'error';
}
