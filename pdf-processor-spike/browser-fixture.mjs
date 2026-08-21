import { createServer } from 'node:http';

const port = 18991;
const artifactOrigin = `http://127.0.0.1:${port}`;

function buildPdf() {
  const content = 'BT /F1 36 Tf 72 700 Td (ArtifactFlow PDF cage) Tj ET';
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
  document += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
  offsets.slice(1).forEach((offset) => {
    document += `${offset.toString().padStart(10, '0')} 00000 n \n`;
  });
  document += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\n`;
  document += `startxref\n${xrefOffset}\n%%EOF\n`;

  return Buffer.from(document, 'ascii');
}

const pdf = buildPdf();
const server = createServer((request, response) => {
  const host = request.headers.host ?? '';
  const requestUrl = new URL(request.url ?? '/', `http://${host || 'localhost'}`);

  if (host.startsWith('localhost')) {
    response.writeHead(200, {
      'Content-Type': 'text/html; charset=utf-8',
      'Set-Cookie': 'artifactflow_session=must-not-cross-origins; Path=/; HttpOnly; SameSite=Lax',
    });
    response.end(`<!doctype html>
<html>
  <head>
    <title>ArtifactFlow native PDF viewer spike</title>
    <style>
      body { font-family: system-ui; margin: 24px; }
      .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; }
      iframe { width: 100%; height: 620px; border: 1px solid #999; }
    </style>
  </head>
  <body>
    <h1>Native PDF viewer compatibility</h1>
    <p>Both PDFs come from <code>${artifactOrigin}</code>; this page is on <code>localhost</code>.</p>
    <div class="grid">
      <section>
        <h2>Current CAGE: iframe sandbox + CSP sandbox</h2>
        <iframe src="${artifactOrigin}/fixture.pdf?csp=sandbox" sandbox="" allow="" referrerpolicy="no-referrer"></iframe>
      </section>
      <section>
        <h2>Sandbox allow-scripts</h2>
        <iframe src="${artifactOrigin}/fixture.pdf?csp=allow-scripts" sandbox="allow-scripts" allow="" referrerpolicy="no-referrer"></iframe>
      </section>
      <section>
        <h2>Sandbox allow-same-origin</h2>
        <iframe src="${artifactOrigin}/fixture.pdf?csp=allow-same-origin" sandbox="allow-same-origin" allow="" referrerpolicy="no-referrer"></iframe>
      </section>
      <section>
        <h2>Sandbox allow-scripts + allow-same-origin</h2>
        <iframe src="${artifactOrigin}/fixture.pdf?csp=allow-both" sandbox="allow-scripts allow-same-origin" allow="" referrerpolicy="no-referrer"></iframe>
      </section>
      <section>
        <h2>Origin isolation only</h2>
        <iframe src="${artifactOrigin}/fixture.pdf?csp=none" allow="" referrerpolicy="no-referrer"></iframe>
      </section>
    </div>
  </body>
</html>`);
    return;
  }

  const cspMode = requestUrl.searchParams.get('csp');
  const sandboxDirective =
    {
      'allow-both': ' sandbox allow-scripts allow-same-origin;',
      'allow-same-origin': ' sandbox allow-same-origin;',
      'allow-scripts': ' sandbox allow-scripts;',
      sandbox: ' sandbox;',
    }[cspMode] ?? '';
  response.writeHead(200, {
    'Cache-Control': 'private, no-store',
    'Content-Disposition': 'inline; filename="artifactflow-pdf-spike.pdf"',
    'Content-Length': pdf.length,
    'Content-Security-Policy': `default-src 'none';${sandboxDirective} object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors http://localhost:${port}`,
    'Content-Type': 'application/pdf',
    'Referrer-Policy': 'no-referrer',
    'X-Content-Type-Options': 'nosniff',
  });
  response.end(pdf);
});

server.listen(port, '127.0.0.1', () => {
  process.stdout.write(`PDF viewer fixture listening at http://localhost:${port}\n`);
});
