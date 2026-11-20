import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import test from 'node:test';

const mermaidRequire = createRequire(import.meta.resolve('mermaid'));
const katex = mermaidRequire('katex');
const concurrentlyRequire = createRequire(import.meta.resolve('concurrently'));
const { parse, quote } = concurrentlyRequire('shell-quote');

const eslintRequire = createRequire(import.meta.resolve('eslint'));
const eslintConfigRequire = createRequire(eslintRequire.resolve('@eslint/config-array'));

// GHSA-g3hv-75wg-m249: exercise each ESLint consumer with a small, bounded
// amplification fixture, without claiming application exposure to hostile globs.
for (const [consumer, consumerRequire] of [
  ['eslint', eslintRequire],
  ['@eslint/config-array', eslintConfigRequire],
]) {
  const { Minimatch } = consumerRequire('minimatch');

  test(`${consumer} minimatch rejects incomplete globstar-parent pattern expansion`, () => {
    const pattern = Array(8).fill('**/../a/b').join('/');

    assert.throws(
      () => new Minimatch(pattern, { optimizationLevel: 2, braceExpandMax: 16 }),
      RangeError,
    );
  });

  test(`${consumer} minimatch preserves complete expansion within its limit`, () => {
    const matcher = new Minimatch('**/../a/b/**/../c/d', {
      optimizationLevel: 2,
      braceExpandMax: 4,
    });

    assert.equal(matcher.set.length, 4);
    assert.equal(matcher.match('../a/b/../c/d'), true);
    assert.equal(matcher.match('x/a/b/y/c/d'), true);
  });

  // GHSA-cf2h-pxjq-68xw: compile both a long row and brace-expanded rows.
  // Run in a child so a regression has a hard deadline and cannot hang CI.
  test(`${consumer} minimatch compiles long wildcard patterns within a bounded budget`, () => {
    const source = [
      "const assert = require('node:assert/strict');",
      'const { Minimatch } = require(process.argv[1]);',
      "const stars = Array(16000).fill('*').join('/');",
      "const words = Array.from({ length: 16 }, (_, i) => 'w' + i).join(',');",
      "const patterns = [stars, '{' + words + '}*/' + stars.slice(0, 8000), '{' + words + '}/' + stars.slice(0, 8000)];",
      'for (const pattern of patterns) {',
      '  const matcher = new Minimatch(pattern);',
      '  assert.ok(matcher.set.length > 0);',
      '  assert.equal(matcher.set.length, matcher.globParts.length);',
      '}',
    ].join('\n');
    const result = spawnSync(
      process.execPath,
      ['-e', source, consumerRequire.resolve('minimatch')],
      {
        encoding: 'utf8',
        timeout: 5000,
        maxBuffer: 64 * 1024,
      },
    );

    assert.ifError(result.error);
    assert.equal(result.status, 0, result.stderr);
  });

  test(`${consumer} minimatch preserves ordinary file selection`, () => {
    const matcher = new Minimatch('resources/**/*.{js,css}', { optimizationLevel: 2 });

    assert.equal(matcher.match('resources/js/app.js'), true);
    assert.equal(matcher.match('resources/css/app.css'), true);
    assert.equal(matcher.match('resources/views/app.blade.php'), false);
    assert.equal(matcher.match('resources/.private/app.js'), false);
  });
}

// GHSA-pqg4-j6r4-53mv: inspect quoting only; never execute the resulting shell text.
for (const [name, terminator] of Object.entries({
  LF: '\n',
  CR: '\r',
  'Unicode line separator': '\u2028',
  'Unicode paragraph separator': '\u2029',
})) {
  test(`shell-quote rejects ${name} anywhere after a comment token`, () => {
    for (const between of [[], ['ordinary argument']]) {
      assert.throws(
        () => quote(['echo', { comment: 'note' }, ...between, `before${terminator}after`]),
        TypeError,
      );
    }
  });
}

test('shell-quote rejects a newline appended after a comment produced by parse', () => {
  const tokens = parse('echo https://example.invalid/#fragment');
  assert.ok(tokens.some((token) => typeof token === 'object' && 'comment' in token));
  assert.throws(() => quote([...tokens, 'before\nafter']), TypeError);
});

test('shell-quote preserves benign arguments and line terminators before comments', () => {
  const args = ['space separated', "O'Brien!", 'line one\nline two', 'literal $value', ''];
  assert.deepEqual(parse(quote(args)), args);
  assert.equal(quote(['echo', 'before\nafter', { comment: 'note' }]), "echo 'before\nafter' #note");
  assert.equal(quote(['echo', { comment: 'note' }, 'ordinary']), 'echo #note ordinary');
});

const link = String.raw`\href{https://example.invalid/katex-canary}{label}`;
const image = String.raw`\includegraphics{https://example.invalid/katex-canary}`;
const activeMarkup = /<(?:a|img|mglyph)\b|<[^>]+\shref=/u;

for (const output of ['html', 'mathml']) {
  test(`KaTeX ignores inherited trust when producing ${output}`, () => {
    const options = Object.assign(Object.create({ trust: true }), { output, strict: 'ignore' });
    for (const expression of [link, image]) {
      assert.doesNotMatch(katex.renderToString(expression, options), activeMarkup);
    }
  });
}

// A dependency-level gadget regression, not a claim that ArtifactFlow lets a
// caller pollute prototypes. Restore each property before node:test sees results.
function withPrototypeProperty(name, value, callback) {
  const previous = Object.getOwnPropertyDescriptor(Object.prototype, name);
  Object.defineProperty(Object.prototype, name, { value, configurable: true, writable: true });
  try {
    return callback();
  } finally {
    if (previous) {
      Object.defineProperty(Object.prototype, name, previous);
    } else {
      delete Object.prototype[name];
    }
  }
}

for (const property of ['trust', 'default', 'processor']) {
  test(`KaTeX ignores a polluted Object.prototype.${property}`, () => {
    const value = property === 'processor' ? (option) => (option === false ? true : option) : true;
    const rendered = withPrototypeProperty(property, value, () =>
      katex.renderToString(link, {
        output: 'html',
        strict: 'ignore',
        macros: {},
        ...(property === 'processor' ? { trust: false } : {}),
      }),
    );
    assert.doesNotMatch(rendered, activeMarkup);
  });
}

test('KaTeX does not resolve an inherited property as a built-in macro', () => {
  withPrototypeProperty('\\fixtureInheritedMacro', 'polluted', () => {
    assert.throws(() => katex.renderToString('\\fixtureInheritedMacro'), katex.ParseError);
  });
});

test('KaTeX retains public rendering, own macros, and explicit trust controls', () => {
  const expression = String.raw`\frac{\fixture}{\sqrt{x}}`;
  const options = { macros: { '\\fixture': 'y' }, displayMode: true, throwOnError: true };
  const mathml = katex.renderToString(expression, { ...options, output: 'mathml' });
  assert.match(mathml, /<mfrac>/u);
  assert.match(mathml, /<msqrt>/u);
  assert.match(mathml, /<mi>y<\/mi>/u);
  assert.match(
    katex.renderToString(expression, { ...options, output: 'htmlAndMathml' }),
    /katex-html/u,
  );
  assert.match(katex.renderToString(link, { trust: true, output: 'html' }), /<a href=/u);
  assert.doesNotMatch(katex.renderToString(link, { trust: false }), activeMarkup);
});
