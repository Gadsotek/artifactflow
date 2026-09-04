import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import { safeConfirmedExternalTarget } from '../../resources/js/xlsx-external-link-confirmation.js';

const source = await readFile(
  new URL('../../resources/js/xlsx-viewer.js', import.meta.url),
  'utf8',
);
const contractSource = await readFile(
  new URL('../../resources/js/xlsx-viewer-contract.js', import.meta.url),
  'utf8',
);
const confirmationSource = await readFile(
  new URL('../../resources/js/xlsx-external-link-confirmation.js', import.meta.url),
  'utf8',
);
const combinedSource = `${source}\n${contractSource}`;
const html = await readFile(new URL('../index.html', import.meta.url), 'utf8');
const css = await readFile(new URL('../../resources/css/xlsx-viewer.css', import.meta.url), 'utf8');
const buildSource = await readFile(new URL('../build.mjs', import.meta.url), 'utf8');
const bundle = await readFile(new URL('../dist/viewer.js', import.meta.url), 'utf8');
const packageJson = JSON.parse(await readFile(new URL('../package.json', import.meta.url), 'utf8'));

test('pins the viewer and build dependencies', () => {
  assert.equal(packageJson.dependencies['tabulator-tables'], '6.5.0');
  assert.equal(packageJson.devDependencies.esbuild, '0.28.2');
});

test('registers only the reviewed read-only Tabulator modules', () => {
  for (const requiredModule of [
    'FormatModule',
    'InteractionModule',
    'KeybindingsModule',
    'ResizeTableModule',
  ]) {
    assert.match(source, new RegExp(`\\b${requiredModule}\\b`, 'u'));
  }

  for (const forbiddenModule of [
    'AjaxModule',
    'ClipboardModule',
    'ColumnCalcsModule',
    'DownloadModule',
    'EditModule',
    'ExportModule',
    'FilterModule',
    'HistoryModule',
    'HtmlTableImportModule',
    'ImportModule',
    'MutatorModule',
    'PersistenceModule',
    'ReactiveDataModule',
    'SortModule',
    'SpreadsheetModule',
    'TabulatorFull',
  ]) {
    assert.doesNotMatch(source, new RegExp(`\\b${forbiddenModule}\\b`, 'u'));
  }
});

test('renders workbook strings through explicit DOM text paths', () => {
  assert.doesNotMatch(source, /innerHTML|outerHTML|insertAdjacentHTML|document\.write/u);
  assert.doesNotMatch(source, /\beval\s*\(|new\s+Function\b/u);
  assert.match(source, /\.textContent\s*=/u);
  assert.doesNotMatch(source, /document\.createElement\('a'\)|window\.open/u);
  assert.match(source, /document\.createElement\('button'\)/u);
  assert.match(source, /artifactflow:xlsx-external-link-request/u);
  assert.match(source, /window\.parent\.postMessage/u);
});

test('delegates external navigation to a destination-visible app-origin confirmation', () => {
  assert.match(confirmationSource, /event\.origin !== 'null'/u);
  assert.match(confirmationSource, /event\.source !== frame\.contentWindow/u);
  assert.match(confirmationSource, /Object\.keys\(payload\)\.length === 2/u);
  assert.match(confirmationSource, /safeConfirmedExternalTarget\(event\.data\.target\)/u);
  assert.doesNotMatch(confirmationSource, /from ['"]\.\/xlsx-viewer-contract['"]/u);
  assert.match(confirmationSource, /open\.target = '_blank'/u);
  assert.match(confirmationSource, /open\.rel = 'noopener noreferrer'/u);
  assert.match(confirmationSource, /open\.referrerPolicy = 'no-referrer'/u);
  assert.doesNotMatch(confirmationSource, /innerHTML|outerHTML|insertAdjacentHTML|document\.write/u);
});

test('independently revalidates external destinations at the app-origin boundary', () => {
  assert.equal(
    safeConfirmedExternalTarget('https://example.com/a?q=1'),
    'https://example.com/a?q=1',
  );
  assert.equal(safeConfirmedExternalTarget('https://example.com'), 'https://example.com/');
  assert.equal(safeConfirmedExternalTarget('http://example.com/'), 'http://example.com/');
  assert.equal(
    safeConfirmedExternalTarget('mailto:person@example.com'),
    'mailto:person@example.com',
  );

  for (const target of [
    'javascript:alert(1)',
    'file:///etc/passwd',
    'ftp://example.com/',
    'https://user:password@example.com/',
    'relative/path',
    'https://example.com/\u0000bad',
  ]) {
    assert.equal(safeConfirmedExternalTarget(target), null);
  }
});

test('has no automatic network or original-workbook parsing API', () => {
  assert.doesNotMatch(
    combinedSource,
    /\bfetch\s*\(|XMLHttpRequest|WebSocket|EventSource|sendBeacon|XLSX\.read|sheet_to_html/u,
  );
  assert.doesNotMatch(combinedSource, /\bSheetJS\b|from\s+['"]xlsx['"]/u);
});

test('uses a fixed-height virtual grid and a deny-by-default local-only shell', () => {
  assert.match(source, /renderVertical:\s*'virtual'/u);
  assert.match(contractSource, /MAX_ROWS\s*=\s*20_000/u);
  assert.match(contractSource, /MAX_COLUMNS\s*=\s*256/u);
  assert.match(contractSource, /MAX_CELLS\s*=\s*100_000/u);
  assert.match(css, /minmax\(320px, 68vh\)/u);
  assert.match(html, /default-src 'none'/u);
  assert.match(html, /connect-src 'none'/u);
  assert.match(html, /script-src 'self'/u);
  assert.doesNotMatch(html, /https?:\/\//u);
  assert.doesNotMatch(html, /frame-ancestors|sandbox allow-/u);
  assert.doesNotMatch(html, /type=["']module["']/u);
  assert.match(html, /<script defer src=["']\.\/dist\/viewer\.js["']/u);
  assert.match(buildSource, /format:\s*'iife'/u);
});

test('keeps formula details and the empty formula state legible in dark mode', () => {
  assert.match(source, /selectedFormula\.classList\.toggle\('formula--empty', !hasFormula\)/u);
  assert.match(css, /\.formula--empty\s*\{[^}]*color:\s*#52606d;/su);

  const darkTheme = css.slice(css.indexOf('@media (prefers-color-scheme: dark)'));

  assert.match(darkTheme, /\.formula\s*\{[^}]*color:\s*#e9d5ff;/su);
  assert.match(darkTheme, /\.formula--empty\s*\{[^}]*color:\s*#cbd5e1;/su);
});

test('renders the complete workbook grid with dark surfaces and readable contrast', () => {
  const darkTheme = css.slice(css.indexOf('@media (prefers-color-scheme: dark)'));

  assert.match(
    darkTheme,
    /\.workbook-grid \.tabulator-header,[^}]*\.workbook-grid \.tabulator-header \.tabulator-col\s*\{[^}]*background:\s*#273449;[^}]*color:\s*#f8fafc;/su,
  );
  assert.match(
    darkTheme,
    /\.workbook-grid \.tabulator-tableholder,[^}]*\.workbook-grid \.tabulator-tableholder \.tabulator-table\s*\{[^}]*background:\s*#111827;[^}]*color:\s*#e5e7eb;/su,
  );
  assert.match(
    darkTheme,
    /\.workbook-grid \.tabulator-row\s*\{[^}]*background:\s*#1f2937;[^}]*color:\s*#e5e7eb;/su,
  );
  assert.match(darkTheme, /\.workbook-grid \.tabulator-row-even\s*\{[^}]*background:\s*#182231;/su);
  assert.match(
    darkTheme,
    /\.workbook-grid \.tabulator-row \.tabulator-cell\s*\{[^}]*border-color:\s*#374151;/su,
  );
});

test('tree-shaken JavaScript bundle stays bounded', () => {
  assert.ok(Buffer.byteLength(bundle, 'utf8') < 200_000);
  assert.doesNotMatch(
    bundle,
    /AjaxModule|ClipboardModule|DownloadModule|EditModule|HtmlTableImportModule|PersistenceModule|SpreadsheetModule/u,
  );
  assert.doesNotMatch(bundle, /XMLHttpRequest|\bfetch\s*\(/u);
});
