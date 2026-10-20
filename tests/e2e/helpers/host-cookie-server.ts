import { execFileSync } from 'node:child_process';
import { generateKeyPairSync } from 'node:crypto';
import { createServer as createHttpServer } from 'node:http';
import { createServer as createHttpsServer } from 'node:https';
import { connect, type Server, type Socket } from 'node:net';

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
    throw new Error('Expected a loopback TCP address.');
  }
  return address.port;
}

// Real HTTPS preserves browser-managed cookie attachment. The CONNECT proxy
// maps only these synthetic hosts to loopback without DNS or URL rewriting.
export async function createHostCookieServer(origins: string[]) {
  const hosts = new Set(origins.map((origin) => new URL(origin).hostname));
  const tunnels = new Set([...hosts].map((host) => `${host}:443`));
  const responseCookies = new Map<string, string>();
  const requests = new Map<string, { cookie: string | undefined }>();
  const sockets = new Set<Socket>();

  // Ephemeral test credentials stay in memory; no certificate/key files or
  // machine trust-store changes. Only this test context ignores TLS trust.
  const { privateKey } = generateKeyPairSync('rsa', { modulusLength: 2048 });
  const key = privateKey.export({ type: 'pkcs8', format: 'pem' });
  const cert = execFileSync(
    'openssl',
    [
      'req',
      '-new',
      '-x509',
      '-key',
      '/dev/stdin',
      '-subj',
      '/CN=*.cookie-prefix.test',
      '-days',
      '1',
      '-sha256',
    ],
    { input: key, timeout: 5_000, stdio: ['pipe', 'pipe', 'pipe'] },
  );

  const httpsServer = createHttpsServer({ key, cert }, (request, response) => {
    const host = request.headers.host ?? '';
    if (!hosts.has(host)) {
      response.writeHead(421).end();
      return;
    }
    const url = `https://${host}${request.url}`;
    requests.set(url, { cookie: request.headers.cookie });
    const cookie = responseCookies.get(url);
    response.writeHead(200, {
      'Content-Type': 'text/html',
      'Cache-Control': 'no-store',
      ...(cookie === undefined ? {} : { 'Set-Cookie': cookie }),
    });
    response.end('<!doctype html><title>Cookie boundary probe</title>');
  });
  const proxyServer = createHttpServer((_request, response) => {
    response.writeHead(403).end();
  });

  function track(socket: Socket): void {
    sockets.add(socket);
    socket.once('close', () => sockets.delete(socket));
    socket.on('error', () => socket.destroy());
  }
  httpsServer.on('connection', track);
  proxyServer.on('connection', track);

  async function close(): Promise<void> {
    const closing = [proxyServer, httpsServer].map(
      (server) => new Promise<void>((resolve) => server.close(() => resolve())),
    );
    for (const socket of sockets) {
      socket.destroy();
    }
    await Promise.all(closing);
  }

  try {
    const httpsPort = await listen(httpsServer);
    proxyServer.on('connect', (request, client, head) => {
      if (!tunnels.has(request.url ?? '')) {
        client.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');
        return;
      }
      const upstream = connect(httpsPort, '127.0.0.1', () => {
        client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
        if (head.length > 0) {
          upstream.write(head);
        }
        client.pipe(upstream);
        upstream.pipe(client);
      });
      track(upstream);
      client.once('close', () => upstream.destroy());
      upstream.once('close', () => client.destroy());
    });
    const proxyPort = await listen(proxyServer);
    return {
      proxy: { server: `http://127.0.0.1:${proxyPort}` },
      responseCookies,
      requests,
      close,
    };
  } catch (error) {
    await close();
    throw error;
  }
}
