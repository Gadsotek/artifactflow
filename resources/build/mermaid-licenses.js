import { readFileSync } from 'node:fs';
import { URL } from 'node:url';

export function retainMermaidLicenses() {
  return {
    name: 'retain-mermaid-licenses',
    apply: 'build',
    generateBundle(_options, bundle) {
      const manifest = JSON.parse(
        readFileSync(new URL('../../node_modules/elkjs/package.json', import.meta.url), 'utf8'),
      );
      if (manifest.version !== '0.12.0' || manifest.license !== 'EPL-2.0 OR GPL-3.0-or-later') {
        throw new Error('ELK license notices require the reviewed elkjs 0.12.0 package.');
      }

      for (const filename of ['NOTICE.txt', 'EPL-2.0.txt', 'GPL-3.0.txt']) {
        this.emitFile({
          type: 'asset',
          fileName: 'licenses/elkjs-0.12.0/' + filename,
          source: readFileSync(
            new URL('../licenses/elkjs-0.12.0/' + filename, import.meta.url),
            'utf8',
          ),
        });
      }

      for (const output of Object.values(bundle)) {
        if (
          output.type === 'chunk' &&
          Object.keys(output.modules).some((id) => id.includes('/node_modules/elkjs/'))
        ) {
          output.code =
            '// ELK 0.12.0; Copyright (c) 2017 Kiel University and others; 2019 TypeFox and others; EPL-2.0 OR GPL-3.0-or-later; License texts and corresponding source: ../licenses/elkjs-0.12.0/NOTICE.txt\n' +
            output.code;
        }
      }
    },
  };
}
