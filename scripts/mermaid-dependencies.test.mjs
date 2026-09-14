import assert from 'node:assert/strict';
import test from 'node:test';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
import ELK from 'elkjs/lib/elk.bundled.js';

const parserRequire = createRequire(import.meta.resolve('chevrotain'));
const { template, unset } = await import(pathToFileURL(parserRequire.resolve('lodash-es')).href);

test('Mermaid parser dependency refuses executable template import names', () => {
  assert.throws(() => template('value', { imports: { 'x = 1': 1 } }), /Invalid/u);
});

test('Mermaid parser dependency cannot delete inherited prototype properties through array paths', () => {
  const prototype = { marker: 'retained' };
  const object = Object.create(prototype);
  unset(object, ['__proto__', 'marker']);
  assert.equal(prototype.marker, 'retained');
});

test('reviewed ELK release lays out connected nodes through the bundled API', async () => {
  const elk = new ELK();
  const graph = await elk.layout({
    id: 'root',
    layoutOptions: { 'elk.algorithm': 'layered', 'elk.direction': 'RIGHT' },
    children: [
      { id: 'a', width: 80, height: 30 },
      { id: 'b', width: 80, height: 30 },
    ],
    edges: [{ id: 'ab', sources: ['a'], targets: ['b'] }],
  });
  assert.ok(graph.children[1].x > graph.children[0].x);
  assert.equal(graph.edges[0].sections.length, 1);
  assert.ok(Number.isFinite(graph.width) && graph.width > 0);
});
