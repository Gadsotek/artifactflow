import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';

function originFromUrl(url) {
  try {
    return new URL(url).origin;
  } catch {
    return null;
  }
}

function uniqueOrigins(origins) {
  return [...new Set(origins.filter((origin) => origin !== null))];
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const vitePort = Number.parseInt(env.VITE_PORT ?? '5181', 10);
  const appPort = env.APP_PORT ?? '18080';
  const viteClientOrigin = env.VITE_DEV_SERVER_ORIGIN ?? `http://localhost:${vitePort}`;
  const viteClientUrl = new URL(viteClientOrigin);
  const appOrigins = uniqueOrigins([
    originFromUrl(env.APP_URL ?? `http://localhost:${appPort}`),
    `http://localhost:${appPort}`,
    `http://127.0.0.1:${appPort}`,
  ]);

  return {
    server: {
      host: '0.0.0.0',
      port: vitePort,
      strictPort: true,
      origin: viteClientOrigin,
      cors: {
        origin: appOrigins,
      },
      hmr: {
        host: viteClientUrl.hostname,
        port: Number.parseInt(viteClientUrl.port || String(vitePort), 10),
        protocol: viteClientUrl.protocol === 'https:' ? 'wss' : 'ws',
      },
    },
    plugins: [
      laravel({
        input: ['resources/css/app.css', 'resources/js/app.js'],
        refresh: true,
      }),
      tailwindcss(),
    ],
  };
});
