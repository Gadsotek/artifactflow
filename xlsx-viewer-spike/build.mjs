import { build } from 'esbuild';

await Promise.all([
  build({
    entryPoints: ['../resources/js/xlsx-viewer.js'],
    bundle: true,
    format: 'iife',
    minify: true,
    outfile: 'dist/viewer.js',
    platform: 'browser',
    target: ['es2022'],
    treeShaking: true,
    nodePaths: ['node_modules'],
  }),
  build({
    entryPoints: ['viewer-entry.css'],
    bundle: true,
    minify: true,
    outfile: 'dist/viewer.css',
    nodePaths: ['node_modules'],
  }),
]);
