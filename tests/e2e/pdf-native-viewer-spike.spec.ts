import { expect, test } from '@playwright/test';
import { createServer, type Server, type ServerResponse } from 'node:http';

test.use({ screenshot: 'off', trace: 'off', video: 'off' });

interface ObservedRequest {
  cookie: string | undefined;
  method: string | undefined;
  profile: string;
  range: string | undefined;
}

function buildPdf(): Buffer {
  const content = 'BT /F1 24 Tf 72 700 Td (ArtifactFlow PDF cage) Tj ET';
  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    `<< /Length ${Buffer.byteLength(content, 'ascii')} >>\nstream\n${content}\nendstream`,
  ];
  let document = '%PDF-1.4\n';
  const offsets = [0];

  objects.forEach((object, index) => {
    offsets.push(Buffer.byteLength(document, 'ascii'));
    document += `${index + 1} 0 obj\n${object}\nendobj\n`;
  });

  const xrefOffset = Buffer.byteLength(document, 'ascii');
  document += `xref\n0 ${objects.length + 1}\n`;
  document += '0000000000 65535 f \n';
  offsets.slice(1).forEach((offset) => {
    document += `${offset.toString().padStart(10, '0')} 00000 n \n`;
  });
  document += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\n`;
  document += `startxref\n${xrefOffset}\n%%EOF\n`;

  return Buffer.from(document, 'ascii');
}

async function listen(server: Server): Promise<number> {
  await new Promise<void>((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      server.off('error', reject);
      resolve();
    });
  });

  const address = server.address();
  if (address === null || typeof address === 'string') {
    throw new Error('Expected a local TCP address.');
  }

  return address.port;
}

async function close(server: Server): Promise<void> {
  await new Promise<void>((resolve, reject) => {
    server.close((error) => {
      if (error !== undefined) {
        reject(error);
        return;
      }
      resolve();
    });
  });
}

function respondWithPdf(
  response: ServerResponse,
  pdf: Buffer,
  method: string | undefined,
  rangeHeader: string | undefined,
  includeCspSandbox: boolean,
): void {
  const commonHeaders = {
    'Accept-Ranges': 'bytes',
    'Cache-Control': 'private, no-store',
    'Content-Disposition': 'inline; filename="artifactflow-pdf-spike.pdf"',
    'Content-Security-Policy': `default-src 'none';${includeCspSandbox ? ' sandbox;' : ''} object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors http://localhost:*`,
    'Content-Type': 'application/pdf',
    'Referrer-Policy': 'no-referrer',
    'X-Content-Type-Options': 'nosniff',
  };

  if (rangeHeader !== undefined) {
    const match = /^bytes=(\d+)-(\d*)$/u.exec(rangeHeader);
    if (match === null) {
      response.writeHead(416, { ...commonHeaders, 'Content-Range': `bytes */${pdf.length}` });
      response.end();
      return;
    }

    const start = Number.parseInt(match[1], 10);
    const requestedEnd = match[2] === '' ? pdf.length - 1 : Number.parseInt(match[2], 10);
    const end = Math.min(requestedEnd, pdf.length - 1);
    if (start >= pdf.length || end < start) {
      response.writeHead(416, { ...commonHeaders, 'Content-Range': `bytes */${pdf.length}` });
      response.end();
      return;
    }

    const body = pdf.subarray(start, end + 1);
    response.writeHead(206, {
      ...commonHeaders,
      'Content-Length': body.length,
      'Content-Range': `bytes ${start}-${end}/${pdf.length}`,
    });
    response.end(method === 'HEAD' ? undefined : body);
    return;
  }

  response.writeHead(200, { ...commonHeaders, 'Content-Length': pdf.length });
  response.end(method === 'HEAD' ? undefined : pdf);
}

test('native PDF viewer uses a narrow PDF-only iframe exception without crossing the two-origin cage @artifact-security', async ({
  browserName,
  page,
}, testInfo) => {
  test.setTimeout(30_000);

  const pdf = buildPdf();
  const artifactRequests: ObservedRequest[] = [];
  let artifactOrigin = '';

  const appServer = createServer((_request, response) => {
    response.writeHead(200, {
      'Content-Type': 'text/html; charset=utf-8',
      'Set-Cookie': 'artifactflow_session=must-not-cross-origins; Path=/; HttpOnly; SameSite=Lax',
    });
    response.end(`<!doctype html>
<html>
  <body>
    <iframe
      title="PDF iframe sandbox control"
      src="${artifactOrigin}/fixture.pdf?profile=iframe-sandbox"
      sandbox=""
      allow=""
      referrerpolicy="no-referrer"
      style="width: 800px; height: 700px"
    ></iframe>
    <iframe
      title="PDF CSP sandbox control"
      src="${artifactOrigin}/fixture.pdf?profile=csp-sandbox"
      allow=""
      referrerpolicy="no-referrer"
      style="width: 800px; height: 700px"
    ></iframe>
    <iframe
      title="PDF isolated-origin profile"
      src="${artifactOrigin}/fixture.pdf?profile=pdf-isolated-origin"
      allow=""
      referrerpolicy="no-referrer"
      style="width: 800px; height: 700px"
    ></iframe>
    <iframe title="Blank control" src="about:blank" style="width: 800px; height: 700px"></iframe>
  </body>
</html>`);
  });
  const appPort = await listen(appServer);
  const appOrigin = `http://localhost:${appPort}`;

  const artifactServer = createServer((request, response) => {
    const requestUrl = new URL(request.url ?? '/', 'http://artifact.invalid');
    const profile = requestUrl.searchParams.get('profile') ?? 'unknown';
    artifactRequests.push({
      cookie: request.headers.cookie,
      method: request.method,
      profile,
      range: request.headers.range,
    });
    respondWithPdf(response, pdf, request.method, request.headers.range, profile === 'csp-sandbox');
  });
  const artifactPort = await listen(artifactServer);
  artifactOrigin = `http://127.0.0.1:${artifactPort}`;

  try {
    const pdfResponsePromise = page.waitForResponse(
      (response) =>
        new URL(response.url()).origin === artifactOrigin &&
        new URL(response.url()).pathname === '/fixture.pdf' &&
        new URL(response.url()).searchParams.get('profile') === 'pdf-isolated-origin',
    );

    await page.goto(appOrigin, { waitUntil: 'domcontentloaded' });
    const pdfResponse = await pdfResponsePromise;
    const iframeSandbox = page.locator('iframe[title="PDF iframe sandbox control"]');
    const cspSandbox = page.locator('iframe[title="PDF CSP sandbox control"]');
    const pdfIframe = page.locator('iframe[title="PDF isolated-origin profile"]');
    const blankControl = page.locator('iframe[title="Blank control"]');

    await expect(pdfIframe).not.toHaveAttribute('sandbox', '');
    await expect(pdfIframe).toHaveAttribute('allow', '');
    await expect(pdfIframe).toHaveAttribute('referrerpolicy', 'no-referrer');
    await expect(pdfIframe).toHaveAttribute(
      'src',
      `${artifactOrigin}/fixture.pdf?profile=pdf-isolated-origin`,
    );
    expect(new URL(page.url()).origin).toBe(appOrigin);
    expect(new URL(pdfResponse.url()).origin).toBe(artifactOrigin);
    expect(pdfResponse.status()).toBeGreaterThanOrEqual(200);
    expect(pdfResponse.status()).toBeLessThan(300);
    expect(await pdfResponse.headerValue('content-type')).toBe('application/pdf');
    expect(await pdfResponse.headerValue('x-content-type-options')).toBe('nosniff');
    const pdfCsp = await pdfResponse.headerValue('content-security-policy');
    expect(pdfCsp).toContain("default-src 'none'");
    expect(pdfCsp).toContain("object-src 'none'");
    expect(pdfCsp).not.toMatch(/(?:^|;)\s*sandbox(?:\s|;|$)/u);

    await expect
      .poll(() => new Set(artifactRequests.map((request) => request.profile)).size)
      .toBe(3);
    expect(artifactRequests.every((request) => request.cookie === undefined)).toBe(true);

    const blankScreenshot = await blankControl.screenshot();
    const profileScreenshots = {
      cspSandbox: await cspSandbox.screenshot(),
      iframeSandbox: await iframeSandbox.screenshot(),
      pdfIsolatedOrigin: await pdfIframe.screenshot(),
    };
    const visiblyRendered = Object.fromEntries(
      Object.entries(profileScreenshots).map(([profile, screenshot]) => [
        profile,
        !screenshot.equals(blankScreenshot),
      ]),
    );
    // Firefox exposes its native viewer to headless screenshots. Chromium and
    // WebKit do not, so headed/manual evidence remains mandatory for those
    // engines instead of turning a blank plugin surface into a false failure.
    if (browserName === 'firefox') {
      expect(visiblyRendered.pdfIsolatedOrigin).toBe(true);
      expect(visiblyRendered.cspSandbox).toBe(false);
    }

    const frame = await pdfIframe.elementHandle().then((element) => element?.contentFrame());
    expect(frame).not.toBeNull();
    expect(frame?.url()).not.toContain('chrome-error://');

    process.stdout.write(
      `PDF viewer evidence ${browserName}: ${JSON.stringify({ artifactRequests, frameUrl: frame?.url(), visiblyRendered })}\n`,
    );

    await testInfo.attach(`pdf-native-viewer-${browserName}.json`, {
      body: Buffer.from(
        JSON.stringify(
          {
            artifactRequests,
            browserName,
            frameUrl: frame?.url(),
            responseStatus: pdfResponse.status(),
            visiblyRendered,
          },
          null,
          2,
        ),
      ),
      contentType: 'application/json',
    });
    await testInfo.attach(`pdf-native-viewer-${browserName}.png`, {
      body: profileScreenshots.pdfIsolatedOrigin,
      contentType: 'image/png',
    });
  } finally {
    await Promise.all([close(appServer), close(artifactServer)]);
  }
});
