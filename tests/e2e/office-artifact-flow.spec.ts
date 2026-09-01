import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const baseUrl = (process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:18180').replace(/\/$/u, '');
const repoRoot = fileURLToPath(new URL('../..', import.meta.url));
const appCommandTarget = process.env.E2E_APP_COMMAND_TARGET ?? 'run-e2e-app-cmd';

test.use({ screenshot: 'off', trace: 'off', video: 'off' });

const crc32Table = Uint32Array.from({ length: 256 }, (_, value) => {
  let current = value;

  for (let bit = 0; bit < 8; bit += 1) {
    current = (current & 1) === 1 ? 0xedb88320 ^ (current >>> 1) : current >>> 1;
  }

  return current >>> 0;
});

function crc32(input: Buffer): number {
  let checksum = 0xffffffff;

  for (const byte of input) {
    checksum = crc32Table[(checksum ^ byte) & 0xff] ^ (checksum >>> 8);
  }

  return (checksum ^ 0xffffffff) >>> 0;
}

function zip(
  entries: Array<{ name: string; data: Buffer | string; dataDescriptor?: boolean }>,
): Buffer {
  const localParts: Buffer[] = [];
  const centralParts: Buffer[] = [];
  let localOffset = 0;

  for (const entry of entries) {
    const name = Buffer.from(entry.name, 'utf8');
    const data = Buffer.isBuffer(entry.data) ? entry.data : Buffer.from(entry.data, 'utf8');
    const checksum = crc32(data);
    const flags = entry.dataDescriptor === true ? 0x0808 : 0x0800;
    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt16LE(flags, 6);
    local.writeUInt16LE(0, 8);
    local.writeUInt32LE(entry.dataDescriptor === true ? 0 : checksum, 14);
    local.writeUInt32LE(entry.dataDescriptor === true ? 0 : data.length, 18);
    local.writeUInt32LE(entry.dataDescriptor === true ? 0 : data.length, 22);
    local.writeUInt16LE(name.length, 26);
    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    central.writeUInt16LE(20, 4);
    central.writeUInt16LE(20, 6);
    central.writeUInt16LE(flags, 8);
    central.writeUInt16LE(0, 10);
    central.writeUInt32LE(checksum, 16);
    central.writeUInt32LE(data.length, 20);
    central.writeUInt32LE(data.length, 24);
    central.writeUInt16LE(name.length, 28);
    central.writeUInt32LE(localOffset, 42);
    const descriptor = entry.dataDescriptor === true ? Buffer.alloc(16) : Buffer.alloc(0);
    if (entry.dataDescriptor === true) {
      descriptor.writeUInt32LE(0x08074b50, 0);
      descriptor.writeUInt32LE(checksum, 4);
      descriptor.writeUInt32LE(data.length, 8);
      descriptor.writeUInt32LE(data.length, 12);
    }
    const localRecord = Buffer.concat([local, name, data, descriptor]);
    localParts.push(localRecord);
    centralParts.push(central, name);
    localOffset += localRecord.length;
  }

  const centralDirectory = Buffer.concat(centralParts);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(entries.length, 8);
  end.writeUInt16LE(entries.length, 10);
  end.writeUInt32LE(centralDirectory.length, 12);
  end.writeUInt32LE(localOffset, 16);

  return Buffer.concat([...localParts, centralDirectory, end]);
}

function xml(value: string): string {
  return `<?xml version="1.0" encoding="UTF-8"?>${value}`;
}

function buildEmf(): Buffer {
  const values = [
    1, 88, 0, 0, 100, 100, 0, 0, 2540, 2540, 0x464d4520, 0x00010000, 132, 3, 1, 0, 0, 0, 96, 96, 25,
    25, 43, 24, 10, 10, 90, 90, 14, 20, 0, 16, 20,
  ];
  const emf = Buffer.alloc(values.length * 4);
  values.forEach((value, offset) => emf.writeUInt32LE(value, offset * 4));

  return emf;
}

function buildXlsx(
  marker: string,
  linkTarget = `https://example.com/artifactflow-${marker}`,
): Buffer {
  const packageRelationships = 'http://schemas.openxmlformats.org/package/2006/relationships';
  const workbookRelationships =
    'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
  const spreadsheet = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
  return zip([
    {
      name: '[Content_Types].xml',
      data: xml(
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' +
          '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' +
          '<Default Extension="xml" ContentType="application/xml"/>' +
          '<Default Extension="png" ContentType="image/png"/>' +
          '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' +
          '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' +
          '<Override PartName="/xl/tables/table1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>' +
          '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' +
          '<Override PartName="/xl/customProperty1.bin" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.customProperty"/>' +
          '</Types>',
      ),
    },
    {
      name: '_rels/.rels',
      data: xml(
        `<Relationships xmlns="${packageRelationships}">` +
          `<Relationship Id="rId1" Type="${workbookRelationships}/officeDocument" Target="xl/workbook.xml"/>` +
          '</Relationships>',
      ),
    },
    {
      name: 'xl/workbook.xml',
      data: xml(
        `<workbook xmlns="${spreadsheet}" xmlns:r="${workbookRelationships}">` +
          '<sheets><sheet name="Summary" sheetId="1" r:id="rId1"/></sheets></workbook>',
      ),
    },
    {
      name: 'xl/_rels/workbook.xml.rels',
      data: xml(
        `<Relationships xmlns="${packageRelationships}">` +
          `<Relationship Id="rId1" Type="${workbookRelationships}/worksheet" Target="worksheets/sheet1.xml"/>` +
          '</Relationships>',
      ),
    },
    {
      name: 'xl/worksheets/sheet1.xml',
      dataDescriptor: true,
      data: xml(
        `<worksheet xmlns="${spreadsheet}" xmlns:r="${workbookRelationships}"><dimension ref="A1:B3"/><sheetData>` +
          '<row r="1"><c r="A1" t="inlineStr"><is><t>Marker</t></is></c>' +
          '<c r="B1" t="inlineStr"><is><t>Revenue</t></is></c></row>' +
          `<row r="2"><c r="A2" t="inlineStr"><is><t>${marker}</t></is></c>` +
          '<c r="B2"><v>1200</v></c></row>' +
          '<row r="3"><c r="A3" t="inlineStr"><is><t>Total</t></is></c>' +
          '<c r="B3"><f>SUM(B2:B2)</f><v>1200</v></c></row>' +
          '</sheetData><hyperlinks><hyperlink ref="A2" r:id="rId1"/><hyperlink ref="B2" location="Summary!A2:B3"/></hyperlinks>' +
          '<customProperties><customPr name="ArtifactFlow fixture property" r:id="rId4"/></customProperties></worksheet>',
      ),
    },
    {
      name: 'xl/worksheets/_rels/sheet1.xml.rels',
      data: xml(
        `<Relationships xmlns="${packageRelationships}">` +
          `<Relationship Id="rId1" Type="${workbookRelationships}/hyperlink" Target="${linkTarget}" TargetMode="External"/>` +
          `<Relationship Id="rId2" Type="${workbookRelationships}/table" Target="../tables/table1.xml"/>` +
          `<Relationship Id="rId3" Type="${workbookRelationships}/drawing" Target="../drawings/drawing1.xml"/>` +
          `<Relationship Id="rId4" Type="${workbookRelationships}/customProperty" Target="../customProperty1.bin"/>` +
          '</Relationships>',
      ),
    },
    {
      name: 'xl/tables/table1.xml',
      data: xml(
        `<table xmlns="${spreadsheet}" id="1" name="Table1" displayName="Table1" ref="A1:B3" totalsRowShown="0">` +
          '<autoFilter ref="A1:B3"/><tableColumns count="2"><tableColumn id="1" name="Marker"/><tableColumn id="2" name="Revenue"/></tableColumns></table>',
      ),
    },
    {
      name: 'xl/drawings/drawing1.xml',
      data: xml(
        '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"/>',
      ),
    },
    {
      name: 'xl/drawings/_rels/drawing1.xml.rels',
      data: xml(
        `<Relationships xmlns="${packageRelationships}">` +
          `<Relationship Id="rId1" Type="${workbookRelationships}/image" Target="../media/image1.png"/>` +
          '</Relationships>',
      ),
    },
    {
      name: 'xl/media/image1.png',
      data: Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        'base64',
      ),
    },
    {
      name: 'xl/customProperty1.bin',
      data: Buffer.from('opaque worksheet custom property'),
    },
  ]);
}

function buildDocx(marker: string, includeEmbeddedWorkbook = false): Buffer {
  const entries: Array<{ name: string; data: Buffer | string }> = [
    {
      name: '[Content_Types].xml',
      data: xml(
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' +
          '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' +
          '<Default Extension="xml" ContentType="application/xml"/>' +
          '<Default Extension="emf" ContentType="image/x-emf"/>' +
          '<Default Extension="odttf" ContentType="application/vnd.openxmlformats-officedocument.obfuscatedFont"/>' +
          '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' +
          '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>' +
          '<Override PartName="/word/fontTable.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.fontTable+xml"/>' +
          '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>' +
          '<Override PartName="/word/stylesWithEffects.xml" ContentType="application/vnd.ms-word.stylesWithEffects+xml"/>' +
          '<Override PartName="/word/charts/chart1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>' +
          '<Override PartName="/customXml/itemProps1.xml" ContentType="application/vnd.openxmlformats-officedocument.customXmlProperties+xml"/>' +
          '<Override PartName="/docProps/thumbnail.emf" ContentType="image/x-emf"/>' +
          '</Types>',
      ),
    },
    {
      name: '_rels/.rels',
      data: xml(
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
          '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officedocument/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' +
          '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>' +
          '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/thumbnail" Target="docProps/thumbnail.emf"/>' +
          '</Relationships>',
      ),
    },
    {
      name: 'docProps/core.xml',
      data: xml(
        '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"/>',
      ),
    },
    {
      name: 'docProps/thumbnail.emf',
      data: buildEmf(),
    },
    {
      name: 'word/document.xml',
      data:
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
        '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:v="urn:schemas-microsoft-com:vml">' +
        `<w:body><w:p><w:r><w:t>${marker}</w:t></w:r></w:p>` +
        '<w:sdt><w:sdtPr><w:dataBinding w:prefixMappings="" w:xpath="/root[1]/value[1]" w:storeItemID="{00112233-4455-6677-8899-AABBCCDDEEFF}"/></w:sdtPr><w:sdtContent><w:p><w:r><w:t>Cached custom XML preview text</w:t></w:r></w:p></w:sdtContent></w:sdt>' +
        '<w:p><w:r><w:pict><v:shape id="ArtifactFlowEmf" style="width:72pt;height:72pt"><v:imagedata r:id="rId2"/></v:shape></w:pict></w:r></w:p>' +
        '<w:sectPr/></w:body></w:document>',
    },
    {
      name: 'word/_rels/document.xml.rels',
      data: xml(
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
          '<Relationship Id="rId1" Type="http://schemas.microsoft.com/office/2007/relationships/stylesWithEffects" Target="stylesWithEffects.xml"/>' +
          '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image3.emf"/>' +
          '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/fontTable" Target="fontTable.xml"/>' +
          '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXml" Target="../customXml/item1.xml"/>' +
          '<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="charts/chart1.xml"/>' +
          '<Relationship Id="rId6" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>' +
          '</Relationships>',
      ),
    },
    {
      name: 'word/settings.xml',
      data: xml(
        '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' +
          '<w:attachedTemplate r:id="rId1"/></w:settings>',
      ),
    },
    {
      name: 'word/_rels/settings.xml.rels',
      data: xml(
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
          '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" Target="file:///Users/example/private/Normal.dotm" TargetMode="External"/>' +
          '</Relationships>',
      ),
    },
    {
      name: 'word/fontTable.xml',
      data: xml(
        '<w:fonts xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' +
          '<w:font w:name="ArtifactFlow Embedded"><w:embedRegular r:id="rId1" w:fontKey="{00112233-4455-6677-8899-AABBCCDDEEFF}"/></w:font>' +
          '</w:fonts>',
      ),
    },
    {
      name: 'word/_rels/fontTable.xml.rels',
      data: xml(
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
          '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/font" Target="fonts/font1.odttf"/>' +
          '</Relationships>',
      ),
    },
    {
      name: 'word/fonts/font1.odttf',
      data: Buffer.from('untrusted-obfuscated-font-bytes\u0000\u0001'),
    },
    {
      name: 'word/stylesWithEffects.xml',
      data: '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
    },
    {
      name: 'word/media/image3.emf',
      data: buildEmf(),
    },
    {
      name: 'word/charts/chart1.xml',
      data: xml(
        '<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart/></c:chartSpace>',
      ),
    },
    {
      name: 'customXml/item1.xml',
      data: xml('<root><value>Mapped custom XML value</value></root>'),
    },
    {
      name: 'customXml/_rels/item1.xml.rels',
      data: xml(
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
          '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXmlProps" Target="itemProps1.xml"/>' +
          '</Relationships>',
      ),
    },
    {
      name: 'customXml/itemProps1.xml',
      data: xml(
        '<ds:datastoreItem ds:itemID="{00112233-4455-6677-8899-AABBCCDDEEFF}" xmlns:ds="http://schemas.openxmlformats.org/officeDocument/2006/customXml"><ds:schemaRefs/></ds:datastoreItem>',
      ),
    },
  ];

  if (includeEmbeddedWorkbook) {
    entries.push({
      name: 'word/embeddings/Microsoft_Excel_Worksheet.xlsx',
      data: Buffer.from('opaque embedded workbook bytes'),
    });
  }

  return zip(entries);
}

function runAppCommand(appCommand: string, failureMessage: string): void {
  if (!['run-e2e-app-cmd', 'run-app-cmd'].includes(appCommandTarget)) {
    throw new Error('Unsupported e2e app command target.');
  }

  try {
    execFileSync('make', [appCommandTarget, `APP_CMD=${appCommand}`], {
      cwd: repoRoot,
      stdio: 'ignore',
    });
  } catch {
    throw new Error(failureMessage);
  }
}

async function login(page: Page, email: string, password: string): Promise<void> {
  await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/dashboard$/u);
}

async function openCreateForm(page: Page, type: 'xlsx' | 'docx', title: string): Promise<void> {
  await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-create-page-form]')).toHaveAttribute(
    'data-create-page-mode-ready',
    'true',
  );
  await page.locator('select[name="type"]').selectOption(type);
  await expect(page.locator('select[name="mode"]')).toHaveValue(`${type}_upload`);
  const form = page.locator('[data-content-editor]');
  await expect(form).toHaveAttribute('data-editor-ready', 'true');
  const titleInput = page.locator('input[name="title"]');
  await titleInput.fill(title);
  await expect(titleInput).toHaveValue(title);
}

test('XLSX and Word uploads are searchable, isolated, previewable, and exactly downloadable @artifact-security', async ({
  page,
  request,
}) => {
  test.setTimeout(180_000);

  const suffix = randomUUID().replaceAll('-', '').slice(0, 12);
  const email = `office-artifact-${suffix}@example.test`;
  const password = `af${randomUUID().replaceAll('-', '')}`;
  const xlsxTitle = `Excel artifact ${suffix}`;
  const xlsxMarker = `ExcelMarker${suffix}`;
  const xlsx = buildXlsx(xlsxMarker);
  const rejectedXlsx = buildXlsx(`RejectedExcelMarker${suffix}`, 'mailto://example.com/person');
  const docxTitle = `Word artifact ${suffix}`;
  const docxMarker = `WordMarker${suffix}`;
  const docx = buildDocx(docxMarker);
  const rejectedEmbeddedDocx = buildDocx(`RejectedWordMarker${suffix}`, true);

  runAppCommand(
    `php artisan artifactflow:create-user --name=OfficeArtifactE2E --email=${email} --password=${password}`,
    'Failed to prepare the Office artifact e2e account.',
  );
  await login(page, email, password);

  await openCreateForm(page, 'xlsx', `Rejected Excel artifact ${suffix}`);
  await page.locator('input[name="xlsx_file"]').setInputFiles({
    name: 'authority-mailto.xlsx',
    mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    buffer: rejectedXlsx,
  });
  await page.getByRole('button', { name: 'Save page' }).click();
  await expect(page.locator('#create-xlsx-file-error')).toContainText(
    'XLSX could not be validated or processed.',
  );

  await openCreateForm(page, 'xlsx', xlsxTitle);
  await page.locator('input[name="xlsx_file"]').setInputFiles({
    name: 'quarterly.xlsx',
    mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    buffer: xlsx,
  });
  const xlsxPreviewPromise = page.waitForResponse(
    (response) =>
      response.request().resourceType() === 'document' &&
      new URL(response.url()).pathname.startsWith('/artifact-previews/'),
  );
  await page.getByRole('button', { name: 'Save page' }).click();
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u, { timeout: 30_000 });
  await expect(page.getByRole('heading', { name: xlsxTitle })).toBeVisible();
  await expect(page.locator('[data-xlsx-facts]')).toContainText('1 visible sheet');
  await expect(page.locator('[data-xlsx-facts]')).toContainText('1 formula');

  const xlsxFrame = page.locator('iframe[title="Read-only Excel preview"]');
  await expect(xlsxFrame).toHaveAttribute(
    'sandbox',
    'allow-scripts allow-popups allow-popups-to-escape-sandbox',
  );
  await expect(xlsxFrame).toHaveAttribute('allow', '');
  await expect(xlsxFrame).toHaveAttribute('referrerpolicy', 'no-referrer');
  const xlsxPreview = await xlsxPreviewPromise;
  expect(new URL(xlsxPreview.url()).origin).not.toBe(new URL(baseUrl).origin);
  expect(xlsxPreview.status()).toBe(200);
  expect(await xlsxPreview.headerValue('content-type')).toBe('text/html; charset=UTF-8');
  expect(await xlsxPreview.headerValue('set-cookie')).toBeNull();
  expect(await xlsxPreview.request().headerValue('cookie')).toBeNull();
  expect(await xlsxPreview.headerValue('access-control-allow-origin')).toBeNull();
  const xlsxCsp = await xlsxPreview.headerValue('content-security-policy');
  expect(xlsxCsp).toContain('sandbox allow-scripts allow-popups allow-popups-to-escape-sandbox');
  expect(xlsxCsp).toContain("connect-src 'none'");
  expect(xlsxCsp).toContain("frame-src 'none'");
  const xlsxViewer = page.frameLocator('iframe[title="Read-only Excel preview"]');
  await expect(xlsxViewer.locator('html')).toHaveAttribute('data-viewer-ready', 'true');
  await expect(xlsxViewer.getByText(xlsxMarker)).toBeVisible();
  await expect(xlsxViewer.getByText('Summary', { exact: true })).toBeVisible();
  const xlsxLink = xlsxViewer.getByRole('link', { name: xlsxMarker });
  await expect(xlsxLink).toHaveAttribute('href', `https://example.com/artifactflow-${xlsxMarker}`);
  await expect(xlsxLink).toHaveAttribute('target', '_blank');
  await expect(xlsxLink).toHaveAttribute('rel', 'noopener noreferrer');
  await expect(xlsxLink).toHaveAttribute('referrerpolicy', 'no-referrer');
  await expect(xlsxViewer.getByRole('button', { name: '1200' })).toBeVisible();

  await page.emulateMedia({ colorScheme: 'dark' });
  await expect(xlsxViewer.locator('.tabulator-header')).toHaveCSS(
    'background-color',
    'rgb(39, 52, 73)',
  );
  await expect(xlsxViewer.locator('.tabulator-tableholder')).toHaveCSS(
    'background-color',
    'rgb(17, 24, 39)',
  );
  await expect(xlsxViewer.locator('.tabulator-row').first()).toHaveCSS(
    'background-color',
    'rgb(31, 41, 55)',
  );
  await expect(xlsxViewer.locator('.tabulator-row-even').first()).toHaveCSS(
    'background-color',
    'rgb(24, 34, 49)',
  );
  await expect(xlsxViewer.locator('.tabulator-cell').first()).toHaveCSS(
    'border-right-color',
    'rgb(55, 65, 81)',
  );
  await xlsxViewer.getByText('Total', { exact: true }).click();
  await expect(xlsxViewer.getByText('No formula', { exact: true })).toHaveCSS(
    'color',
    'rgb(203, 213, 225)',
  );

  const xlsxDownloadPromise = page.waitForEvent('download');
  await page.getByRole('link', { name: 'Download original XLSX' }).click();
  const xlsxDownload = await xlsxDownloadPromise;
  expect(xlsxDownload.suggestedFilename()).toMatch(
    /^artifactflow-[0-9a-hjkmnp-tv-z]{26}-v1\.xlsx$/u,
  );
  const xlsxDownloadPath = await xlsxDownload.path();
  expect(xlsxDownloadPath).not.toBeNull();
  expect(readFileSync(xlsxDownloadPath as string)).toEqual(xlsx);

  await page.goto(`${baseUrl}/pages?q=${encodeURIComponent(xlsxMarker)}`, {
    waitUntil: 'domcontentloaded',
  });
  await expect(page.getByRole('link', { name: xlsxTitle })).toBeVisible();

  await openCreateForm(page, 'docx', `Rejected Word artifact ${suffix}`);
  await page.locator('input[name="docx_file"]').setInputFiles({
    name: 'embedded-workbook.docx',
    mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    buffer: rejectedEmbeddedDocx,
  });
  await page.getByRole('button', { name: 'Save page' }).click();
  await expect(page.locator('#create-docx-file-error')).toContainText(
    'This Word document contains an embedded file or OLE object, which is not supported.',
  );

  await openCreateForm(page, 'docx', docxTitle);
  await page.locator('input[name="docx_file"]').setInputFiles({
    name: 'contract.docx',
    mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    buffer: docx,
  });
  const docxPreviewPromise = page.waitForResponse(
    (response) =>
      response.request().resourceType() === 'document' &&
      new URL(response.url()).pathname.startsWith('/docx-previews/'),
  );
  await page.getByRole('button', { name: 'Save page' }).click();
  await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u, { timeout: 60_000 });
  await expect(page.getByRole('heading', { name: docxTitle })).toBeVisible();
  await expect(page.locator('[data-docx-facts]')).toContainText('1 preview page');

  const docxFrame = page.locator('iframe[title="Word document PDF preview"]');
  expect(await docxFrame.getAttribute('sandbox')).toBeNull();
  await expect(docxFrame).toHaveAttribute('allow', '');
  await expect(docxFrame).toHaveAttribute('referrerpolicy', 'no-referrer');
  const docxPreview = await docxPreviewPromise;
  expect(new URL(docxPreview.url()).origin).not.toBe(new URL(baseUrl).origin);
  expect(docxPreview.status()).toBe(200);
  expect(await docxPreview.headerValue('content-type')).toBe('application/pdf');
  expect(await docxPreview.headerValue('set-cookie')).toBeNull();
  expect(await docxPreview.request().headerValue('cookie')).toBeNull();
  expect(await docxPreview.headerValue('access-control-allow-origin')).toBeNull();
  const docxCsp = await docxPreview.headerValue('content-security-policy');
  expect(docxCsp).toContain("default-src 'none'");
  expect(docxCsp).toContain(`frame-ancestors ${baseUrl}`);
  expect(docxCsp).not.toMatch(/(?:^|;)\s*sandbox(?:\s|;|$)/u);
  const derivedPdfResponse = await request.get(docxPreview.url(), {
    headers: { Accept: 'application/pdf' },
  });
  expect(derivedPdfResponse.status()).toBe(200);
  expect(derivedPdfResponse.headers()['set-cookie']).toBeUndefined();
  const derivedPdf = await derivedPdfResponse.body();
  expect(derivedPdf.subarray(0, 5).toString('ascii')).toBe('%PDF-');
  expect(derivedPdf).not.toEqual(docx);

  const docxDownloadPromise = page.waitForEvent('download');
  await page.getByRole('link', { name: 'Download original DOCX' }).click();
  const docxDownload = await docxDownloadPromise;
  expect(docxDownload.suggestedFilename()).toMatch(
    /^artifactflow-[0-9a-hjkmnp-tv-z]{26}-v1\.docx$/u,
  );
  const docxDownloadPath = await docxDownload.path();
  expect(docxDownloadPath).not.toBeNull();
  expect(readFileSync(docxDownloadPath as string)).toEqual(docx);

  await page.goto(`${baseUrl}/pages?q=${encodeURIComponent(docxMarker)}`, {
    waitUntil: 'domcontentloaded',
  });
  await expect(page.getByRole('link', { name: docxTitle })).toBeVisible();
});
