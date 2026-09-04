import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { createSocket } from 'node:dgram';
import { createServer } from 'node:net';
import { fileURLToPath } from 'node:url';

// Standing matrix for connection primitives blocked inside the sandboxed HTML
// artifact. The artifact runs attacker-authored script (`sandbox allow-scripts`)
// on the isolated artifact origin. CSP blocks ordinary subresources and
// connection APIs, while the guard stubs top-realm WebRTC and the server
// rewriter prevents an unpatched nested realm from existing. This spec sends
// those vectors to real loopback TCP and UDP listeners and asserts neither is
// touched. The UDP socket exists specifically because Chromium and WebKit ignore
// `webrtc 'block'`, and a 2026-07-17 WebRTC/STUN miss escaped a TCP-only collector.
//
// This is deliberately not an absolute "no packets" proof: an IP-literal target
// cannot observe DNS resolution, while location-based frame self-navigation is a
// documented residual covered by the saved-preview tests and threat model.

const baseUrl = (process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:18180').replace(/\/$/u, '');
const repoRoot = fileURLToPath(new URL('../..', import.meta.url));
const appCommandTarget = process.env.E2E_APP_COMMAND_TARGET ?? 'run-e2e-app-cmd';

test.use({ screenshot: 'off', trace: 'off', video: 'off' });

function escapeHtmlAttribute(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('"', '&quot;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;');
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

function assertSavedPreviewSchemaReady(): void {
  runAppCommand(
    'php artisan tinker --execute="if (! Illuminate\\\\Support\\\\Facades\\\\Schema::hasColumn(\\"pages\\", \\"search_vector\\") || ! Illuminate\\\\Support\\\\Facades\\\\Schema::hasTable(\\"installation_settings\\")) { throw new RuntimeException(\\"Missing saved-preview e2e schema\\"); }"',
    'Artifact egress matrix e2e requires pages.search_vector and installation_settings in the isolated e2e database. Refresh the e2e database schema before running this browser test.',
  );
}

async function login(page: Page, email: string, password: string): Promise<void> {
  await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/dashboard$/u);
}

interface LoopbackProbes {
  tcpUrl: string;
  wsUrl: string;
  stunUrl: string;
  webTransportUrl: string;
  tcpConnections: () => number;
  udpPackets: () => number;
  close: () => Promise<void>;
}

// A real TCP server and a real UDP socket on ephemeral loopback ports. A TCP
// connection from a blocked network primitive or a UDP STUN/QUIC datagram that
// reaches them is a cage breach. Neither socket observes DNS resolution. Both are
// unref'd so a leaked listener can never keep the Playwright worker alive.
async function startLoopbackProbes(): Promise<LoopbackProbes> {
  let tcpConnections = 0;
  let udpPackets = 0;

  const tcpProbe = createServer((socket) => {
    tcpConnections += 1;
    socket.destroy();
  });
  await new Promise<void>((resolve, reject) => {
    tcpProbe.once('error', reject);
    tcpProbe.listen(0, '127.0.0.1', () => {
      tcpProbe.off('error', reject);
      resolve();
    });
  });
  tcpProbe.unref();

  const udpProbe = createSocket('udp4');
  udpProbe.on('message', () => {
    udpPackets += 1;
  });
  await new Promise<void>((resolve, reject) => {
    udpProbe.once('error', reject);
    udpProbe.bind(0, '127.0.0.1', () => {
      udpProbe.off('error', reject);
      resolve();
    });
  });
  udpProbe.unref();

  const tcpAddress = tcpProbe.address();
  const udpAddress = udpProbe.address();

  if (tcpAddress === null || typeof tcpAddress === 'string') {
    throw new Error('Expected an IPv4 TCP probe address.');
  }

  const tcpPort = tcpAddress.port;
  const udpPort = udpAddress.port;

  return {
    tcpUrl: `http://127.0.0.1:${tcpPort}`,
    wsUrl: `ws://127.0.0.1:${tcpPort}`,
    stunUrl: `stun:127.0.0.1:${udpPort}`,
    webTransportUrl: `https://127.0.0.1:${udpPort}`,
    tcpConnections: () => tcpConnections,
    udpPackets: () => udpPackets,
    close: async () => {
      await new Promise<void>((resolve) => {
        tcpProbe.close(() => resolve());
      });
      await new Promise<void>((resolve) => {
        udpProbe.close(() => resolve());
      });
    },
  };
}

test('sandboxed HTML artifact blocks guarded outbound APIs without TCP or UDP egress @artifact-security', async ({
  page,
}) => {
  // The full flow (create user, author, save, cross-origin render, fire every
  // vector, settle two watchdog windows) far exceeds the default per-test budget
  // on CI.
  test.setTimeout(120_000);

  const runSuffix = randomUUID().replaceAll('-', '').slice(0, 12);
  const email = `artifact-egress-e2e-${runSuffix}@example.test`;
  const password = `af${randomUUID().replaceAll('-', '')}`;
  const title = `Egress matrix sandbox ${runSuffix}`;
  const navCheck = `${baseUrl}/saved-preview-navigation-check?run=${runSuffix}`;

  let navigationRequests = 0;

  const probes = await startLoopbackProbes();

  try {
    assertSavedPreviewSchemaReady();
    runAppCommand(
      `php artisan artifactflow:create-user --name=EgressMatrixE2E --email=${email} --password=${password}`,
      'Failed to prepare the artifact egress matrix e2e account.',
    );

    // Count only navigation-family attempts that target the app origin. Network
    // vectors are aimed at the loopback probes instead of an intercepted route,
    // so aborting here would mask them -- the probes are their ground truth.
    await page.route('**/saved-preview-navigation-check**', async (route) => {
      navigationRequests += 1;
      await route.abort();
    });

    await login(page, email, password);
    await page.goto(`${baseUrl}/pages/create`, { waitUntil: 'domcontentloaded' });

    const editorForm = page.locator('[data-content-editor]');
    await expect(editorForm).toHaveAttribute('data-editor-ready', 'true');

    await page.locator('select[name="type"]').selectOption('html_artifact');
    await page.locator('select[name="mode"]').selectOption('html_paste');

    // Wait for the async CodeMirror language switch to settle before typing so
    // the trailing setValue() cannot clobber the inserted content on slow runs.
    await expect(editorForm).toHaveAttribute('data-editor-language', 'html');
    await expect(editorForm).toHaveAttribute('data-editor-ready', 'true');
    const titleInput = page.locator('input[name="title"]');
    await titleInput.fill(title);
    await expect(titleInput).toHaveValue(title);

    const sourceEditor = page.locator('[data-source-editor-mount] .cm-content');
    await expect(sourceEditor).toBeVisible();
    await sourceEditor.click();
    await page.keyboard.press('ControlOrMeta+A');
    const nestedRealmRtc = `<!doctype html><script>
      var nestedConnection = new RTCPeerConnection({
        iceServers: [{ urls: '${probes.stunUrl}' }],
      });
      nestedConnection.createDataChannel('nested-egress');
      nestedConnection
        .createOffer()
        .then(function (offer) {
          return nestedConnection.setLocalDescription(offer);
        })
        .catch(function () {});
    </script>`;
    const nestedRealmBreakout =
      `<svg><style><div><iframe data-nested-realm-egress ` +
      `srcdoc="${escapeHtmlAttribute(nestedRealmRtc)}"></iframe></div></style></svg>`;

    // The artifact author is hostile. The <head> carries STATIC resource hints
    // and a meta refresh so the server rewriter (layer 1) is exercised; the
    // <script> fires DYNAMIC post-load mutations so the in-page guard (layer 3)
    // is exercised. Every network target is a loopback probe; navigation-family
    // vectors are deferred behind window.fireDeferredEgress so they cannot
    // detach the document before the immediate assertions run.
    await page.keyboard.insertText(`<!doctype html>
<html>
  <head>
    <title>Egress matrix sandbox</title>
    <meta http-equiv="refresh" content="0; url=${navCheck}&via=meta-static">
    <link rel="preconnect" href="${probes.tcpUrl}/static-preconnect">
    <link rel="dns-prefetch" href="${probes.tcpUrl}/static-dns-prefetch">
    <link rel="prefetch" href="${probes.tcpUrl}/static-prefetch">
    <link rel="prerender" href="${probes.tcpUrl}/static-prerender">
  </head>
  <body>
    <p id="result">starting</p>
    ${nestedRealmBreakout}
    <script>
      var probeHttp = '${probes.tcpUrl}';
      var probeWs = '${probes.wsUrl}';
      var stunUrl = '${probes.stunUrl}';
      var webTransportUrl = '${probes.webTransportUrl}';
      var navCheck = '${navCheck}';
      var fired = [];
      var blockedHints = 0;

      function tryVector(name, run) {
        try {
          run();
        } catch (error) {
          // A thrown/neutralized primitive is a pass; the probes are the oracle.
        }
        fired.push(name);
      }

      // fetch family -- CSP connect-src 'none' plus guard stubs.
      tryVector('fetch', function () {
        fetch(probeHttp + '/fetch', { mode: 'no-cors' }).catch(function () {});
      });
      tryVector('xhr', function () {
        var request = new XMLHttpRequest();
        request.open('POST', probeHttp + '/xhr');
        request.send('x');
      });
      tryVector('websocket', function () {
        new WebSocket(probeWs + '/ws');
      });
      tryVector('eventsource', function () {
        new EventSource(probeHttp + '/eventsource');
      });
      tryVector('beacon', function () {
        if (typeof navigator.sendBeacon === 'function') {
          navigator.sendBeacon(probeHttp + '/beacon', 'x');
        }
      });
      tryVector('webtransport', function () {
        if (typeof WebTransport === 'function') {
          new WebTransport(webTransportUrl + '/webtransport');
        }
      });
      tryVector('worker', function () {
        var blob = new Blob(
          ['fetch("' + probeHttp + '/worker").catch(function () {});'],
          { type: 'text/javascript' },
        );
        new Worker(URL.createObjectURL(blob));
      });
      tryVector('sharedworker', function () {
        if (typeof SharedWorker === 'function') {
          var blob = new Blob(
            ['fetch("' + probeHttp + '/sharedworker").catch(function () {});'],
            { type: 'text/javascript' },
          );
          new SharedWorker(URL.createObjectURL(blob));
        }
      });

      // Top-realm WebRTC -- the early guard constructor stub is the control.
      tryVector('webrtc', function () {
        var connection = new RTCPeerConnection({ iceServers: [{ urls: stunUrl }] });
        connection.createDataChannel('egress');
        connection
          .createOffer()
          .then(function (offer) {
            return connection.setLocalDescription(offer);
          })
          .catch(function () {});
      });

      // External subresource loads -- CSP img-src is data:/blob: only.
      tryVector('image', function () {
        var image = new Image();
        image.src = probeHttp + '/image';
      });

      // Resource hints via every synchronous mutation sink the guard must cover.
      function fireHint(rel, via) {
        var link = document.createElement('link');
        document.head.appendChild(link);
        var href = probeHttp + '/hint-' + via + '-' + rel;

        if (via === 'prop') {
          link.rel = rel;
          link.href = href;
        } else if (via === 'attr') {
          link.setAttribute('rel', rel);
          link.setAttribute('href', href);
        } else if (via === 'relListAdd') {
          link.relList.add(rel);
          link.href = href;
        } else if (via === 'relListValue') {
          link.relList.value = rel;
          link.href = href;
        } else if (via === 'setAttributeNS') {
          link.setAttributeNS(null, 'rel', rel);
          link.setAttributeNS(null, 'href', href);
        }

        return !link.isConnected && !link.relList.contains(rel);
      }
      ['preconnect', 'dns-prefetch', 'prefetch', 'prerender'].forEach(function (rel) {
        ['prop', 'attr', 'relListAdd', 'relListValue', 'setAttributeNS'].forEach(function (via) {
          tryVector('hint-' + via + '-' + rel, function () {
            if (fireHint(rel, via)) {
              blockedHints += 1;
            }
          });
        });
      });

      // Dynamic meta refresh (the runtime companion to the static <head> one).
      tryVector('meta-refresh-dynamic', function () {
        var meta = document.createElement('meta');
        document.head.appendChild(meta);
        meta.httpEquiv = 'refresh';
        meta.content = '0; url=' + navCheck + '&via=meta-dynamic';
      });

      // Speculation Rules -- newer prefetch/prerender surface no CSP fetch
      // directive governs directly. Chromium-only today; inert elsewhere.
      tryVector('speculation-prefetch', function () {
        var rules = document.createElement('script');
        rules.type = 'speculationrules';
        rules.textContent = JSON.stringify({
          prefetch: [{ source: 'list', urls: [probeHttp + '/speculation-prefetch'] }],
        });
        document.head.appendChild(rules);
      });
      tryVector('speculation-prerender', function () {
        var rules = document.createElement('script');
        rules.type = 'speculationrules';
        rules.textContent = JSON.stringify({
          prerender: [{ source: 'list', urls: [probeHttp + '/speculation-prerender'] }],
        });
        document.head.appendChild(rules);
      });

      // Interceptable navigation-family vectors run only when the harness asks,
      // after the immediate assertions.
      window.fireDeferredEgress = function () {
        try {
          window.open(probeHttp + '/window-open', '_blank');
        } catch (error) {
          // window.open is stubbed to return null.
        }
        try {
          var anchor = document.createElement('a');
          anchor.href = navCheck + '&via=ping-anchor';
          anchor.setAttribute('ping', probeHttp + '/ping');
          anchor.textContent = 'x';
          document.body.appendChild(anchor);
          anchor.click();
        } catch (error) {
          // hyperlink auditing ping is governed by connect-src 'none'.
        }
      };

      var result = document.getElementById('result');
      result.dataset.blockedHints = String(blockedHints);
      result.textContent = 'egress-suite-fired ' + fired.length;
    </script>
  </body>
</html>`);

    // Guard against a lost editor sync before saving.
    await expect(sourceEditor).toContainText('egress-suite-fired');
    await expect(page.locator('[data-editor-textarea]')).toHaveValue(/egress-suite-fired/u);
    await expect(titleInput).toHaveValue(title);

    const previewResponsePromise = page.waitForResponse(
      (response) =>
        response.request().resourceType() === 'document' &&
        new URL(response.url()).pathname.startsWith('/artifact-previews/'),
    );

    await page.getByRole('button', { name: 'Save page' }).click();

    // Save posts via fetch and rebuilds the document, then the preview loads a
    // signed URL cross-origin. Both are far slower than the 5s default on CI.
    await expect(page).toHaveURL(/\/pages\/[0-9a-hjkmnp-tv-z]{26}$/u, { timeout: 20_000 });
    await expect(page.getByRole('heading', { name: title })).toBeVisible({ timeout: 20_000 });

    const frame = page.locator('iframe[title="Artifact preview"]');
    await expect(frame).toHaveAttribute('sandbox', 'allow-scripts');
    await expect(frame).toHaveAttribute('allow', '');
    await expect(frame).not.toHaveAttribute('sandbox', /allow-same-origin/u);

    // Confirm we hit the real hardened responder, not a stubbed document.
    const previewResponse = await previewResponsePromise;
    const servedBody = await previewResponse.text();
    const csp = await previewResponse.headerValue('content-security-policy');
    expect(csp).toContain("default-src 'none'");
    expect(csp).toContain("connect-src 'none'");
    expect(csp).toContain('sandbox allow-scripts');
    expect(await previewResponse.headerValue('x-dns-prefetch-control')).toBe('off');
    expect(new URL(previewResponse.url()).origin).not.toBe(new URL(baseUrl).origin);
    expect(servedBody).not.toContain('<iframe data-nested-realm-egress');
    expect(servedBody).toContain(
      '<template data-artifactflow-blocked-browsing-context data-nested-realm-egress',
    );

    // The artifact script ran end to end -- every immediate vector was fired.
    const previewResult = page.frameLocator('iframe[title="Artifact preview"]').locator('#result');
    await expect(previewResult).toBeAttached({ timeout: 20_000 });
    await expect(previewResult).toHaveText(/^egress-suite-fired \d+$/u, { timeout: 20_000 });
    await expect(previewResult).toHaveAttribute('data-blocked-hints', '20');
    const preview = page.frameLocator('iframe[title="Artifact preview"]');
    await expect(preview.locator('iframe[data-nested-realm-egress]')).toHaveCount(0);
    await expect(preview.locator('template[data-nested-realm-egress]')).toHaveCount(1);

    // Let any asynchronous connection attempt (fetch/ICE/speculation) reach the
    // probes before asserting the steady state of zero.
    await page.waitForTimeout(1_000);
    expect(probes.tcpConnections()).toBe(0);
    expect(probes.udpPackets()).toBe(0);
    expect(navigationRequests).toBe(0);

    // Now fire popup and hyperlink-auditing vectors, then assert out-of-document
    // counters rather than relying on an in-document marker.
    const parentPageUrl = page.url();
    const artifactFrame = await (await frame.elementHandle())?.contentFrame();
    await artifactFrame?.evaluate(() => {
      const fire = Reflect.get(window, 'fireDeferredEgress');

      if (typeof fire === 'function') {
        fire();
      }
    });
    await page.waitForTimeout(1_750);

    expect(probes.tcpConnections()).toBe(0);
    expect(probes.udpPackets()).toBe(0);
    expect(navigationRequests).toBe(0);
    expect(page.url()).toBe(parentPageUrl);
  } finally {
    await probes.close();
  }
});
