import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';

const TABULATOR_LICENSE_BANNER = `/*! Tabulator 6.5.0 | Copyright (c) 2015-2026 Oli Folkerd | MIT License
Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE. */`;

function retainTabulatorLicense() {
  return {
    name: 'retain-tabulator-license',
    generateBundle(_options, bundle) {
      for (const output of Object.values(bundle)) {
        if (
          output.type === 'chunk' &&
          Object.keys(output.modules).some((id) => id.includes('/node_modules/tabulator-tables/'))
        ) {
          output.code = `${TABULATOR_LICENSE_BANNER}\n${output.code}`;
        }
      }
    },
  };
}

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
        input: [
          'resources/css/app.css',
          'resources/js/app.js',
          'resources/js/external-share-bootstrap.js',
          'resources/js/external-share-viewer.js',
          'resources/js/xlsx-viewer.js',
          'resources/css/xlsx-viewer.css',
        ],
        refresh: true,
      }),
      tailwindcss(),
      retainTabulatorLicense(),
    ],
  };
});
