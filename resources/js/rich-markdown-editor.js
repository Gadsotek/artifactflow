function normaliseWhitespace(value) {
  return value
    .replace(/\u200b/gu, '')
    .replace(/\u00a0/gu, ' ')
    .replace(/[ \t]+\n/gu, '\n');
}

function escapeMarkdownText(value) {
  return normaliseWhitespace(value)
    .replace(/\\/gu, '\\\\')
    .replace(/([*_`])/gu, '\\$1');
}

// Text inside link text needs `[` and `]` escaped too: an unescaped `]` closes
// the link text early (`[a]b](url)` breaks). This is applied ONLY inside links —
// escaping brackets in ordinary prose would corrupt an unresolved `[[Page]]`
// wiki link, which the renderer leaves as a literal text node outside any <a>
// (MarkdownWikiLinkResolver::replaceWikiLinks).
function escapeLinkText(value) {
  return escapeMarkdownText(value).replace(/([[\]])/gu, '\\$1');
}

function safeLinkTarget(value) {
  const target = value.trim();

  if (
    target.startsWith('/') ||
    target.startsWith('#') ||
    /^https?:\/\//iu.test(target) ||
    /^mailto:/iu.test(target)
  ) {
    return target;
  }

  return null;
}

// Mirror of MarkdownPageRenderer::isUnsafeImageSource: allow only root-relative,
// http(s), and base64 raster data URLs. The editor renders images on the app origin,
// so a rejected source must never reach the DOM.
function safeImageTarget(value) {
  const target = value.trim();

  if (
    target.startsWith('/') ||
    /^https?:\/\//iu.test(target) ||
    /^data:image\/(?:png|jpe?g|gif|webp);base64,/iu.test(target)
  ) {
    return target;
  }

  return null;
}

function codeFence(source) {
  const runs = source.match(/`+/gu) ?? [];
  const longestRun = runs.reduce((length, run) => Math.max(length, run.length), 0);

  return '`'.repeat(Math.max(3, longestRun + 1));
}

// Serialise an inline code span. The fence must be a run of backticks longer
// than the longest backtick run inside the content: CommonMark forbids
// backslash escaping inside a code span, so `\`` is NOT an escaped backtick —
// it reparses as a broken single-tick span plus a stray backtick. Pad both
// sides with a space when the content abuts the fence with a backtick (else the
// fences merge) OR begins and ends with a space while holding a non-space char
// (else CommonMark strips one space from each side on parse and the surrounding
// spaces are lost). The pad is exactly the one space CommonMark strips back off,
// so the content round-trips. Content that is only spaces is left unpadded —
// CommonMark keeps an all-space code span verbatim.
function inlineCodeSpan(text) {
  const content = normaliseWhitespace(text);
  const runs = content.match(/`+/gu) ?? [];
  const longestRun = runs.reduce((length, run) => Math.max(length, run.length), 0);
  const fence = '`'.repeat(longestRun + 1);
  const abutsBacktick = content.startsWith('`') || content.endsWith('`');
  const wrappedInSpaces =
    content.length > 1 && content.startsWith(' ') && content.endsWith(' ') && content.trim() !== '';
  const pad = abutsBacktick || wrappedInSpaces ? ' ' : '';

  return `${fence}${pad}${content}${pad}${fence}`;
}

// Escape a link or image title for the `"..."` form. Backslashes must be escaped
// BEFORE quotes: a title ending in a backslash would otherwise escape the closing
// quote (`"path\"`), so the title never terminates and the link fails to parse.
function escapeLinkTitle(title) {
  return title.replace(/\\/gu, '\\\\').replace(/"/gu, '\\"');
}

const mermaidTemplates = {
  flowchart: 'graph TD\n  User --> ArtifactFlow\n  ArtifactFlow --> Page',
  sequence:
    'sequenceDiagram\n  actor User\n  User->>ArtifactFlow: Open page\n  ArtifactFlow-->>User: Render page',
  class: 'classDiagram\n  class Page\n  Page : uid\n  Page : title',
  state: 'stateDiagram-v2\n  [*] --> Draft\n  Draft --> Approved\n  Approved --> Archived',
  er: 'erDiagram\n  WORKSPACE ||--o{ PAGE : contains\n  PAGE ||--o{ PAGE_VERSION : has',
  pie: 'pie title Page types\n  "Markdown" : 70\n  "HTML artifacts" : 30',
  gantt:
    'gantt\n  title Release plan\n  dateFormat  YYYY-MM-DD\n  section Alpha\n  Hardening :done, 2026-06-20, 5d\n  Launch :active, 2026-06-28, 2d',
};

const savedSelections = new WeakMap();

function elementChildren(element, inLinkText = false) {
  return [...element.childNodes].map((node) => serialiseNode(node, inLinkText)).join('');
}

function serialiseEditableSourceNode(node) {
  if (node instanceof Text) {
    return node.data;
  }

  if (!(node instanceof HTMLElement)) {
    return '';
  }

  if (node.tagName === 'BR') {
    return '\n';
  }

  const source = [...node.childNodes].map((child) => serialiseEditableSourceNode(child)).join('');

  return node.matches('div, p') ? `${source}\n` : source;
}

function editableSourceText(element) {
  return normaliseWhitespace(
    [...element.childNodes].map((node) => serialiseEditableSourceNode(node)).join(''),
  );
}

// Block-level children a list item can legitimately hold — CommonMark permits
// arbitrary blocks in a list item. Everything else (text, emphasis, links, inline
// code, images, <br>) is inline. The two editor widgets (code block, Mermaid) are
// block <div>s identified by their data-attribute, not their tag name.
function isListItemBlockChild(node) {
  if (!(node instanceof HTMLElement)) {
    return false;
  }

  if (node.matches('[data-mermaid-diagram]') || node.matches('[data-editor-code-block]')) {
    return true;
  }

  return [
    'P',
    'UL',
    'OL',
    'BLOCKQUOTE',
    'PRE',
    'TABLE',
    'HR',
    'H1',
    'H2',
    'H3',
    'H4',
    'H5',
    'H6',
    'DIV',
  ].includes(node.tagName);
}

// Serialise a list item's contents in DOCUMENT ORDER. EVERY block child — paragraph,
// nested list, code block, Mermaid, blockquote, table, heading, rule — is emitted in
// place as its own block; only genuinely inline content is joined and space-collapsed.
// Flattening a block into the inline run would, e.g., collapse a fenced code block to a
// single ``` … ``` line and silently destroy it. A nested list is emitted where it
// appears, NOT hoisted after later content (which reordered `Before / list / After`).
// Blocks of a loose item (any <p> child) are separated by a blank line, a tight item's
// by a single newline; every continuation line is indented to the item's content column
// so CommonMark keeps the block inside the item.
function serialiseListItemBody(item, contentColumn) {
  const isLoose = [...item.childNodes].some(
    (node) => node instanceof HTMLElement && node.tagName === 'P',
  );
  const blocks = [];
  let inlineRun = '';

  const flushInline = () => {
    const text = inlineRun.replace(/\n+/gu, ' ').trim();

    if (text !== '') {
      blocks.push(text);
    }

    inlineRun = '';
  };

  const pushBlock = (markdown) => {
    const block = markdown.replace(/\n+$/u, '');

    if (block !== '') {
      blocks.push(block);
    }
  };

  for (const child of item.childNodes) {
    if (!isListItemBlockChild(child)) {
      inlineRun += serialiseNode(child);
      continue;
    }

    flushInline();

    if (child.tagName === 'UL' || child.tagName === 'OL') {
      // Nested lists serialise at base indent and shift by the content column below.
      pushBlock(serialiseList(child, ''));
    } else {
      pushBlock(serialiseNode(child));
    }
  }

  flushInline();

  return blocks
    .join(isLoose ? '\n\n' : '\n')
    .split('\n')
    .map((line, index) => (index === 0 || line === '' ? line : `${contentColumn}${line}`))
    .join('\n');
}

// Serialise an element whose children are normally inline but may contain block
// elements (a list, quote, or widget trapped inside a <p> by a legacy document
// or an unforeseen editing path). Inline runs and blocks are emitted in document
// order as separate blocks, so a trapped `- item` never glues onto the text.
// Unlike serialiseListItemBody this keeps <br> line breaks inside inline runs.
function serialiseBlockFlow(element) {
  const blocks = [];
  let inlineRun = '';

  const flushInline = () => {
    const text = inlineRun.trim();

    if (text !== '') {
      blocks.push(text);
    }

    inlineRun = '';
  };

  for (const child of element.childNodes) {
    if (isListItemBlockChild(child)) {
      flushInline();
      const block = serialiseNode(child).replace(/\n+$/u, '');

      if (block !== '') {
        blocks.push(block);
      }
    } else {
      inlineRun += serialiseNode(child);
    }
  }

  flushInline();

  return blocks.join('\n\n');
}

// `indent` is the exact whitespace the item's marker sits at. The content column
// (indent + marker width + one space) is where continuation lines and nested
// lists must align — not a fixed two spaces per depth: under `1. ` a child needs
// three spaces and under `10. ` four, or CommonMark parses the sublist outside
// the item.
function serialiseList(list, indent = '') {
  const ordered = list.tagName === 'OL';
  const items = [...list.children].filter((child) => child.tagName === 'LI');
  const start = Number.parseInt(list.getAttribute('start') ?? '', 10);
  let counter = ordered && !Number.isNaN(start) ? start : 1;

  return items
    .map((item) => {
      const checkbox = [...item.children].find(
        (child) => child.tagName === 'INPUT' && child.getAttribute('type') === 'checkbox',
      );
      const task =
        checkbox === undefined
          ? ''
          : checkbox.hasAttribute('checked') || checkbox.checked
            ? '[x] '
            : '[ ] ';

      let marker;

      if (ordered) {
        // Honour `<ol start>` and any explicit `<li value>`: renumbering every
        // item from 1 silently rewrites `3. Third` to `1. Third` on save.
        const value = Number.parseInt(item.getAttribute('value') ?? '', 10);

        if (!Number.isNaN(value)) {
          counter = value;
        }

        marker = `${counter}.`;
        counter += 1;
      } else {
        marker = '-';
      }

      const contentColumn = `${indent}${' '.repeat(marker.length + 1)}`;
      const body = serialiseListItemBody(item, contentColumn);

      return `${indent}${marker} ${task}${body}\n`;
    })
    .join('');
}

function serialiseMermaid(element) {
  const sourceElement =
    element.querySelector('[data-editor-mermaid-source]') ??
    element.querySelector('.artifactflow-mermaid-source-code code');
  const source = normaliseWhitespace(
    sourceElement instanceof HTMLElement
      ? editableSourceText(sourceElement)
      : (element.getAttribute('data-mermaid-source') ?? ''),
  ).trim();
  const fence = codeFence(source);

  return `${fence}mermaid\n${source}\n${fence}\n\n`;
}

function serialiseImage(node) {
  const alt = (node.getAttribute('alt') ?? '').replace(/\\/gu, '\\\\').replace(/([[\]])/gu, '\\$1');
  const source = (node.getAttribute('src') ?? '').trim();

  // A source stripped by the renderer (unsafe scheme) leaves an <img> with no src.
  // Keep the alt text rather than emit a broken `![alt]()` image.
  if (source === '') {
    return alt;
  }

  const title = (node.getAttribute('title') ?? '').trim();
  const titleSuffix = title === '' ? '' : ` "${escapeLinkTitle(title)}"`;

  return `![${alt}](${source}${titleSuffix})`;
}

function serialiseTableCell(cell) {
  return elementChildren(cell).replace(/\n+/gu, ' ').replace(/\|/gu, '\\|').trim();
}

// GFM column alignment is carried on each cell's `align` attribute (CommonMark
// renders `:---`/`:---:`/`---:` into align="left|center|right"). Reconstruct the
// delimiter cell from the header cell so alignment survives the round-trip.
function tableDelimiter(cell) {
  switch ((cell.getAttribute('align') ?? '').toLowerCase()) {
    case 'left':
      return ':---';
    case 'center':
      return ':---:';
    case 'right':
      return '---:';
    default:
      return '---';
  }
}

function serialiseTable(table) {
  const rows = [...table.querySelectorAll('tr')].filter((row) =>
    [...row.children].some((cell) => cell.tagName === 'TH' || cell.tagName === 'TD'),
  );

  if (rows.length === 0) {
    return '';
  }

  const cellElements = (row) =>
    [...row.children].filter((cell) => cell.tagName === 'TH' || cell.tagName === 'TD');
  const cellsOf = (row) => cellElements(row).map((cell) => serialiseTableCell(cell));

  const headerCells = cellElements(rows[0]);
  const header = headerCells.map((cell) => serialiseTableCell(cell));
  const width = header.length;
  const toRow = (cells) => `| ${cells.join(' | ')} |`;
  const lines = [toRow(header), toRow(headerCells.map((cell) => tableDelimiter(cell)))];

  for (const row of rows.slice(1)) {
    const cells = cellsOf(row);

    while (cells.length < width) {
      cells.push('');
    }

    lines.push(toRow(cells.slice(0, width)));
  }

  return `${lines.join('\n')}\n\n`;
}

function serialiseCodeBlock(element) {
  const codeElement = element.querySelector('[data-editor-code-content]');
  const source = (
    codeElement instanceof HTMLElement ? editableSourceText(codeElement) : ''
  ).replace(/\n$/u, '');
  const language = element.dataset.editorLanguage ?? '';
  const fence = codeFence(source);

  return `${fence}${language}\n${source}\n${fence}\n\n`;
}

function serialiseDelimitedInline(content, delimiter) {
  if (content.trim() === '') {
    return content;
  }

  const leadingWhitespace = /^\s*/u.exec(content)?.[0] ?? '';
  const trailingWhitespace = /\s*$/u.exec(content)?.[0] ?? '';
  const inner = content.slice(leadingWhitespace.length, content.length - trailingWhitespace.length);

  return `${leadingWhitespace}${delimiter}${inner}${delimiter}${trailingWhitespace}`;
}

function serialiseNode(node, inLinkText = false) {
  if (node instanceof Text) {
    return inLinkText ? escapeLinkText(node.data) : escapeMarkdownText(node.data);
  }

  if (!(node instanceof HTMLElement)) {
    return '';
  }

  if (node.matches('[data-mermaid-diagram]')) {
    return serialiseMermaid(node);
  }

  if (node.matches('[data-editor-code-block]')) {
    return serialiseCodeBlock(node);
  }

  const content = () => elementChildren(node, inLinkText);

  switch (node.tagName) {
    case 'H1':
    case 'H2':
    case 'H3':
    case 'H4':
    case 'H5':
    case 'H6':
      return `${'#'.repeat(Number(node.tagName.slice(1)))} ${content().trim()}\n\n`;
    case 'P':
      return `${serialiseBlockFlow(node)}\n\n`;
    case 'STRONG':
    case 'B':
      return serialiseDelimitedInline(content(), '**');
    case 'EM':
    case 'I':
      return serialiseDelimitedInline(content(), '_');
    case 'DEL':
    case 'S':
      return `~~${content()}~~`;
    case 'IMG':
      return serialiseImage(node);
    case 'TABLE':
      return serialiseTable(node);
    case 'A': {
      const target = safeLinkTarget(node.getAttribute('href') ?? '');

      if (target === null) {
        return content();
      }

      // Mirror serialiseImage: a link title (`[text](url "title")`) is document
      // content, not editor chrome, so dropping it silently rewrites the source.
      const title = (node.getAttribute('title') ?? '').trim();
      const titleSuffix = title === '' ? '' : ` "${escapeLinkTitle(title)}"`;

      // Force link-text escaping for the link's own children (brackets close the
      // text early), independent of the surrounding context.
      return `[${elementChildren(node, true)}](${target}${titleSuffix})`;
    }
    case 'CODE':
      return inlineCodeSpan(node.textContent ?? '');
    case 'PRE': {
      const source = normaliseWhitespace(node.textContent ?? '').replace(/\n$/u, '');
      const code = node.querySelector('code');
      const languageClass = [...(code?.classList ?? [])].find((className) =>
        className.startsWith('language-'),
      );
      const language =
        node.dataset.editorLanguage ?? languageClass?.replace(/^language-/u, '') ?? '';
      const fence = codeFence(source);

      return `${fence}${language}\n${source}\n${fence}\n\n`;
    }
    case 'UL':
    case 'OL':
      return `${serialiseList(node)}\n`;
    case 'LI':
      return content();
    case 'BLOCKQUOTE':
      return `${content()
        .trim()
        .split('\n')
        .map((line) => `> ${line}`)
        .join('\n')}\n\n`;
    case 'HR':
      return '---\n\n';
    case 'BR':
      return '\n';
    case 'SVG':
    case 'DETAILS':
    case 'SUMMARY':
    case 'SELECT':
    case 'OPTION':
    case 'INPUT':
      // Editor chrome (block-picker controls, task-list checkboxes rendered by
      // serialiseList) is never part of the document source. Guard the generic
      // path so a disturbed widget can never leak control labels into the saved
      // Markdown.
      return '';
    default:
      return content();
  }
}

export function markdownFromRichEditor(editor) {
  return [...editor.childNodes]
    .map((node) => serialiseNode(node))
    .join('')
    .replace(/\n{3,}/gu, '\n\n')
    .trim();
}

function selectionInside(editor) {
  const selection = window.getSelection();

  if (selection !== null && selection.rangeCount > 0) {
    const range = selection.getRangeAt(0);

    if (editor.contains(range.commonAncestorContainer)) {
      savedSelections.set(editor, range.cloneRange());

      return range;
    }
  }

  const savedRange = savedSelections.get(editor);

  return savedRange !== undefined && editor.contains(savedRange.commonAncestorContainer)
    ? savedRange.cloneRange()
    : null;
}

function rememberSelection(editor) {
  const selection = window.getSelection();

  if (selection === null || selection.rangeCount === 0) {
    return;
  }

  const range = selection.getRangeAt(0);

  if (editor.contains(range.commonAncestorContainer)) {
    savedSelections.set(editor, range.cloneRange());
  }
}

function selectInsertedText(node) {
  const selection = window.getSelection();

  if (selection === null) {
    return;
  }

  const range = document.createRange();
  range.selectNodeContents(node);
  selection.removeAllRanges();
  selection.addRange(range);
}

function placeCaretAtEnd(element) {
  const selection = window.getSelection();

  if (selection === null) {
    return;
  }

  const range = document.createRange();
  range.selectNodeContents(element);
  range.collapse(false);
  selection.removeAllRanges();
  selection.addRange(range);
  element.closest('[data-rich-markdown-editor]')?.focus();
}

function createCaretParagraph() {
  const paragraph = document.createElement('p');
  paragraph.dataset.editorCaret = '';
  paragraph.append(document.createElement('br'));

  return paragraph;
}

function normalisedLanguageName(language) {
  return language
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9_-]/gu, '');
}

function appendOptions(select, options, selectedValue) {
  for (const [value, label] of options) {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    option.selected = value === selectedValue;
    select.append(option);
  }
}

// Shared factory for the labelled-select header that tops code and Mermaid
// blocks. The three call sites differ only in class, label, select dataset key,
// aria-label, and options, so they all build the header the same way here.
function buildBlockHeader({
  headerClassName,
  labelText,
  selectDataKey,
  ariaLabel,
  options,
  selected,
}) {
  const header = document.createElement('div');
  header.className = headerClassName;

  const label = document.createElement('label');
  label.className = 'artifactflow-editor-control';

  const labelSpan = document.createElement('span');
  labelSpan.textContent = labelText;
  label.append(labelSpan);

  const select = document.createElement('select');
  select.dataset[selectDataKey] = '';
  select.setAttribute('aria-label', ariaLabel);
  appendOptions(select, options, selected);
  label.append(select);
  header.append(label);

  return header;
}

function createCodeBlock(language = '', source = '') {
  const safeLanguage = normalisedLanguageName(language);
  const wrapper = document.createElement('div');
  wrapper.className = 'artifactflow-code-block';
  wrapper.dataset.editorCodeBlock = '';
  wrapper.dataset.editorLanguage = safeLanguage;
  wrapper.contentEditable = 'false';

  const header = document.createElement('div');
  header.className = 'artifactflow-code-block-header';
  const headerLabel = document.createElement('span');
  headerLabel.className = 'artifactflow-code-block-label';
  headerLabel.textContent = 'Code';
  header.append(headerLabel);
  wrapper.append(header);

  const pre = document.createElement('pre');
  pre.className = 'artifactflow-code-block-editor';
  const code = document.createElement('code');
  code.dataset.editorCodeContent = '';
  code.contentEditable = 'true';
  code.spellcheck = false;
  code.className = safeLanguage === '' ? '' : `language-${safeLanguage}`;
  code.textContent = source === '' ? 'code' : source;
  pre.append(code);
  wrapper.append(pre);

  return wrapper;
}

function mermaidTemplateOptions() {
  return [
    ['flowchart', 'Flowchart'],
    ['sequence', 'Sequence'],
    ['class', 'Class'],
    ['state', 'State'],
    ['er', 'ER'],
    ['pie', 'Pie'],
    ['gantt', 'Gantt'],
  ];
}

function detectMermaidTemplate(source) {
  const trimmed = source.trimStart();

  if (trimmed.startsWith('sequenceDiagram')) {
    return 'sequence';
  }

  if (trimmed.startsWith('classDiagram')) {
    return 'class';
  }

  if (trimmed.startsWith('stateDiagram')) {
    return 'state';
  }

  if (trimmed.startsWith('erDiagram')) {
    return 'er';
  }

  if (trimmed.startsWith('pie')) {
    return 'pie';
  }

  if (trimmed.startsWith('gantt')) {
    return 'gantt';
  }

  return 'flowchart';
}

// Find a run delimited by `delimiter` (for example `*`, `**`, `_`) starting at
// `start`. The content must be non-empty, must not begin or end with
// whitespace, and must stay on one line — the same conditions the serialiser
// relies on when it emits emphasis. Returns the inner text and the index past
// the closing delimiter, or null when there is no well-formed match.
function matchDelimitedRun(text, start, delimiter) {
  if (text.slice(start, start + delimiter.length) !== delimiter) {
    return null;
  }

  const contentStart = start + delimiter.length;

  if (/\s/u.test(text[contentStart] ?? '')) {
    return null;
  }

  let searchFrom = contentStart;

  while (searchFrom < text.length) {
    const close = text.indexOf(delimiter, searchFrom);

    if (close === -1) {
      return null;
    }

    const inner = text.slice(contentStart, close);

    if (inner === '' || /\s$/u.test(inner) || inner.includes('\n')) {
      searchFrom = close + delimiter.length;
      continue;
    }

    return { inner, end: close + delimiter.length };
  }

  return null;
}

// Parse inline Markdown (code spans, links, strong, emphasis, and backslash
// escapes) into DOM nodes. Existing document source is loaded through here so
// that `**bold**`, `_em_`, `` `code` `` and `[text](url)` become real elements
// which the serialiser round-trips, instead of literal text that the serialiser
// would backslash-escape into `\*\*bold\*\*` on the next save.
function parseInlineMarkdown(text) {
  const nodes = [];
  let buffer = '';
  const flush = () => {
    if (buffer !== '') {
      nodes.push(document.createTextNode(buffer));
      buffer = '';
    }
  };

  let index = 0;

  while (index < text.length) {
    const character = text[index];

    if (
      character === '\\' &&
      index + 1 < text.length &&
      /[\\`*_[\]()#+\-.!>~]/u.test(text[index + 1])
    ) {
      buffer += text[index + 1];
      index += 2;
      continue;
    }

    if (character === '`') {
      const ticks = /^`+/u.exec(text.slice(index))?.[0] ?? '`';
      const close = text.indexOf(ticks, index + ticks.length);

      if (close !== -1) {
        flush();
        const code = document.createElement('code');
        code.textContent = text.slice(index + ticks.length, close);
        nodes.push(code);
        index = close + ticks.length;
        continue;
      }
    }

    if (character === '!' && text[index + 1] === '[') {
      const image = /^!\[([^\]]*)\]\(([^()\s]+)(?:\s+"([^"]*)")?\)/u.exec(text.slice(index));
      const source = image === null ? null : safeImageTarget(image[2]);

      if (image !== null && source !== null) {
        flush();
        const picture = document.createElement('img');
        picture.setAttribute('src', source);
        picture.setAttribute('alt', image[1]);

        if (image[3] !== undefined && image[3] !== '') {
          picture.setAttribute('title', image[3]);
        }

        nodes.push(picture);
        index += image[0].length;
        continue;
      }
    }

    if (character === '[') {
      const link = /^\[([^\]]*)\]\(([^()\s]+)\)/u.exec(text.slice(index));
      const target = link === null ? null : safeLinkTarget(link[2]);

      if (link !== null && target !== null) {
        flush();
        const anchor = document.createElement('a');
        anchor.setAttribute('href', target);
        appendInlineMarkdown(anchor, link[1]);
        nodes.push(anchor);
        index += link[0].length;
        continue;
      }
    }

    if (character === '~') {
      const strike = matchDelimitedRun(text, index, '~~');

      if (strike !== null) {
        flush();
        const element = document.createElement('del');
        appendInlineMarkdown(element, strike.inner);
        nodes.push(element);
        index = strike.end;
        continue;
      }
    }

    if (character === '*' || character === '_') {
      const strong = matchDelimitedRun(text, index, character.repeat(2));

      if (strong !== null) {
        flush();
        const element = document.createElement('strong');
        appendInlineMarkdown(element, strong.inner);
        nodes.push(element);
        index = strong.end;
        continue;
      }

      const emphasis = matchDelimitedRun(text, index, character);

      if (emphasis !== null) {
        flush();
        const element = document.createElement('em');
        appendInlineMarkdown(element, emphasis.inner);
        nodes.push(element);
        index = emphasis.end;
        continue;
      }
    }

    buffer += character;
    index += 1;
  }

  flush();

  return nodes;
}

function appendInlineMarkdown(target, text) {
  for (const node of parseInlineMarkdown(text)) {
    target.append(node);
  }
}

function appendParagraph(fragment, paragraphLines) {
  if (paragraphLines.length === 0) {
    return;
  }

  const paragraph = document.createElement('p');
  appendInlineMarkdown(paragraph, paragraphLines.join('\n'));
  fragment.append(paragraph);
  paragraphLines.length = 0;
}

function isClosingFence(line, marker, length) {
  const trimmed = line.trim();

  return trimmed.length >= length && [...trimmed].every((character) => character === marker);
}

const fenceStartPattern = /^(`{3,}|~{3,})([A-Za-z0-9_-]*)\s*$/u;
const atxHeadingPattern = /^(#{1,6})\s+(.+)$/u;
const thematicBreakPattern = /^ {0,3}(?:(?:- *){3,}|(?:\* *){3,}|(?:_ *){3,})$/u;
const blockquoteLinePattern = /^ {0,3}> ?(.*)$/u;
const listMarkerPattern = /^( {0,3})([-*+]|\d{1,9}[.)])([ \t]+)(.*)$/u;
const setextUnderlinePattern = /^ {0,3}(=+|-+)\s*$/u;

// Lines that open a new block end lazy paragraph continuation (inside list
// items and blockquotes); a plain text line continues the open paragraph.
function startsNewBlock(line) {
  return (
    line.trim() === '' ||
    fenceStartPattern.test(line) ||
    atxHeadingPattern.test(line) ||
    thematicBreakPattern.test(line) ||
    blockquoteLinePattern.test(line) ||
    listMarkerPattern.test(line)
  );
}

// Consume consecutive `>`-prefixed lines (plus lazy paragraph continuations),
// strip one quote level, and parse the remainder recursively so quotes can hold
// paragraphs, lists, fences, and nested quotes. Returns the next line index.
function appendBlockquote(parent, lines, start) {
  const quoteLines = [];
  let index = start;

  while (index < lines.length) {
    const quoted = blockquoteLinePattern.exec(lines[index]);

    if (quoted !== null) {
      quoteLines.push(quoted[1]);
      index += 1;
      continue;
    }

    if (quoteLines[quoteLines.length - 1]?.trim() !== '' && !startsNewBlock(lines[index])) {
      quoteLines.push(lines[index]);
      index += 1;
      continue;
    }

    break;
  }

  const quote = document.createElement('blockquote');
  appendMarkdownBlocks(quote, quoteLines);
  parent.append(quote);

  return index;
}

// Consume one list (all items of the same ordered/unordered kind), parsing each
// item's body recursively at its content column so nested lists, quotes, and
// fenced blocks stay inside the item. Returns the next line index.
function appendList(parent, lines, start) {
  const ordered = /^\d/u.test(listMarkerPattern.exec(lines[start])[2]);
  const list = document.createElement(ordered ? 'ol' : 'ul');
  const items = [];
  let expectedNumber = null;
  let loose = false;
  let index = start;

  while (index < lines.length) {
    const marker = listMarkerPattern.exec(lines[index]);

    if (marker === null || /^\d/u.test(marker[2]) !== ordered) {
      break;
    }

    // The content column is where the item's text begins; continuation lines
    // and nested blocks indented to it belong to this item.
    const spacing = marker[3].length > 4 ? 1 : marker[3].length;
    const contentColumn = marker[1].length + marker[2].length + spacing;
    const bodyLines = [marker[4]];
    let value = null;

    if (ordered) {
      const number = Number.parseInt(marker[2], 10);

      if (expectedNumber === null) {
        // Honour a start above 1, mirroring the serialiser's `<ol start>`.
        if (number !== 1) {
          list.setAttribute('start', String(number));
        }
      } else if (number !== expectedNumber) {
        // A non-sequential item carries its own `<li value>`.
        value = number;
      }

      expectedNumber = number + 1;
    }

    index += 1;

    while (index < lines.length) {
      const line = lines[index];

      if (line.trim() === '') {
        // A blank line stays inside the item (loose continuation) or between
        // items (loose list); a following outdented non-marker line ends the list.
        let ahead = index + 1;

        while (ahead < lines.length && lines[ahead].trim() === '') {
          ahead += 1;
        }

        if (ahead >= lines.length) {
          break;
        }

        const nextIndent = /^ */u.exec(lines[ahead])[0].length;

        if (nextIndent >= contentColumn) {
          loose = true;
          bodyLines.push('');
          index += 1;
          continue;
        }

        const nextMarker = listMarkerPattern.exec(lines[ahead]);

        if (nextMarker !== null && /^\d/u.test(nextMarker[2]) === ordered) {
          loose = true;
          index = ahead;
        }

        break;
      }

      if (/^ */u.exec(line)[0].length >= contentColumn) {
        bodyLines.push(line.slice(contentColumn));
        index += 1;
        continue;
      }

      if (listMarkerPattern.test(line)) {
        break;
      }

      // Lazy paragraph continuation of the item's open paragraph.
      if (!startsNewBlock(line) && bodyLines[bodyLines.length - 1].trim() !== '') {
        bodyLines.push(line);
        index += 1;
        continue;
      }

      break;
    }

    items.push({ value, bodyLines });
  }

  for (const { value, bodyLines } of items) {
    const item = document.createElement('li');

    if (value !== null) {
      item.setAttribute('value', String(value));
    }

    const task = /^\[([ xX])\][ \t]+/u.exec(bodyLines[0]);

    if (task !== null) {
      bodyLines[0] = bodyLines[0].slice(task[0].length);
      const checkbox = document.createElement('input');
      checkbox.setAttribute('type', 'checkbox');
      checkbox.setAttribute('disabled', '');

      if (task[1].toLowerCase() === 'x') {
        checkbox.setAttribute('checked', '');
      }

      item.append(checkbox, document.createTextNode(' '));
    }

    const body = document.createDocumentFragment();
    appendMarkdownBlocks(body, bodyLines);

    if (!loose) {
      // A tight item holds inline content, not <p> wrappers — the serialiser
      // treats any <p> child as a loose item and would add blank lines.
      for (const child of [...body.childNodes]) {
        if (child instanceof HTMLElement && child.tagName === 'P') {
          child.replaceWith(...child.childNodes);
        }
      }
    }

    item.append(body);
    list.append(item);
  }

  parent.append(list);

  return index;
}

// Parse block-level Markdown lines into `parent`. Recursive entry point shared
// by the document root, blockquote bodies, and list-item bodies, so every block
// construct nests the same way everywhere.
function appendMarkdownBlocks(parent, lines) {
  const paragraphLines = [];
  let index = 0;

  while (index < lines.length) {
    const line = lines[index];
    const fenceStart = fenceStartPattern.exec(line);

    if (fenceStart !== null) {
      appendParagraph(parent, paragraphLines);

      const fenceMarker = fenceStart[1][0];
      const fenceLength = fenceStart[1].length;
      const language = normalisedLanguageName(fenceStart[2] ?? '');
      const blockLines = [];
      index += 1;

      while (index < lines.length) {
        if (isClosingFence(lines[index], fenceMarker, fenceLength)) {
          index += 1;
          break;
        }

        blockLines.push(lines[index]);
        index += 1;
      }

      const blockSource = blockLines.join('\n');
      parent.append(
        language === 'mermaid'
          ? createMermaidBlock(blockSource)
          : createCodeBlock(language, blockSource),
      );
      continue;
    }

    if (line.trim() === '') {
      appendParagraph(parent, paragraphLines);
      index += 1;
      continue;
    }

    // A setext underline closes the open paragraph into a heading; checked
    // before the thematic break so `---` under text is a heading, not a rule.
    if (paragraphLines.length > 0) {
      const setext = setextUnderlinePattern.exec(line);

      if (setext !== null) {
        const heading = document.createElement(setext[1][0] === '=' ? 'h1' : 'h2');
        appendInlineMarkdown(heading, paragraphLines.join('\n'));
        paragraphLines.length = 0;
        parent.append(heading);
        index += 1;
        continue;
      }
    }

    if (thematicBreakPattern.test(line)) {
      appendParagraph(parent, paragraphLines);
      parent.append(document.createElement('hr'));
      index += 1;
      continue;
    }

    const heading = atxHeadingPattern.exec(line);

    if (heading !== null) {
      appendParagraph(parent, paragraphLines);

      const headingElement = document.createElement(`h${heading[1].length}`);
      appendInlineMarkdown(headingElement, heading[2]);
      parent.append(headingElement);
      index += 1;
      continue;
    }

    if (blockquoteLinePattern.test(line)) {
      appendParagraph(parent, paragraphLines);
      index = appendBlockquote(parent, lines, index);
      continue;
    }

    const marker = listMarkerPattern.exec(line);

    if (marker !== null) {
      // An ordered list can only interrupt a paragraph when it starts at 1
      // (CommonMark) — `1986. What a year` stays prose.
      const interrupts =
        paragraphLines.length === 0 ||
        !/^\d/u.test(marker[2]) ||
        Number.parseInt(marker[2], 10) === 1;

      if (interrupts) {
        appendParagraph(parent, paragraphLines);
        index = appendList(parent, lines, index);
        continue;
      }
    }

    paragraphLines.push(line);
    index += 1;
  }

  appendParagraph(parent, paragraphLines);
}

function renderMarkdownSource(source) {
  const fragment = document.createDocumentFragment();
  appendMarkdownBlocks(fragment, source.split('\n'));

  if (!fragment.hasChildNodes()) {
    fragment.append(createCaretParagraph());
  }

  return fragment;
}

function ensureMermaidCaretBoundaries(editor) {
  for (const wrapper of editor.querySelectorAll(':scope > [data-mermaid-diagram]')) {
    if (
      !wrapper.previousElementSibling ||
      wrapper.previousElementSibling.matches('[data-mermaid-diagram]')
    ) {
      wrapper.before(createCaretParagraph());
    }

    if (
      !wrapper.nextElementSibling ||
      wrapper.nextElementSibling.matches('[data-mermaid-diagram]')
    ) {
      wrapper.after(createCaretParagraph());
    }
  }
}

function insertNode(editor, node) {
  const range = selectionInside(editor);

  if (range === null) {
    editor.append(node);
  } else {
    range.deleteContents();
    range.insertNode(node);
  }

  selectInsertedText(node);
}

function wrapSelectionWithElement(editor, element, fallbackText) {
  const range = selectionInside(editor);

  if (range === null) {
    return;
  }

  if (range.collapsed) {
    element.textContent = fallbackText;
  } else {
    element.append(range.extractContents());
  }

  range.insertNode(element);
  selectInsertedText(element);
}

function selectedTextSlices(editor, range) {
  const slices = [];
  const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT);
  let node = walker.nextNode();

  while (node !== null) {
    if (node instanceof Text && node.length > 0 && range.intersectsNode(node)) {
      const startOffset = node === range.startContainer ? range.startOffset : 0;
      const endOffset = node === range.endContainer ? range.endOffset : node.length;

      if (startOffset < endOffset) {
        slices.push({ node, startOffset, endOffset });
      }
    }

    node = walker.nextNode();
  }

  return slices;
}

function matchingFormatAncestors(editor, node, selector) {
  const matches = [];
  let current =
    node instanceof HTMLElement ? node.closest(selector) : node.parentElement?.closest(selector);

  while (current instanceof HTMLElement && editor.contains(current)) {
    matches.push(current);
    current = current.parentElement?.closest(selector) ?? null;
  }

  return matches;
}

function nodeDepth(node) {
  let depth = 0;
  let current = node.parentNode;

  while (current !== null) {
    depth += 1;
    current = current.parentNode;
  }

  return depth;
}

function selectNodeSpan(firstNode, lastNode) {
  const selection = window.getSelection();

  if (selection === null || !firstNode.isConnected || !lastNode.isConnected) {
    return;
  }

  const range = document.createRange();
  range.setStartBefore(firstNode);
  range.setEndAfter(lastNode);
  selection.removeAllRanges();
  selection.addRange(range);
}

function unwrapFormatElements(elements, firstSelectedNode, lastSelectedNode) {
  const deepestFirst = [...elements].sort((left, right) => nodeDepth(right) - nodeDepth(left));

  for (const element of deepestFirst) {
    element.replaceWith(...element.childNodes);
  }

  selectNodeSpan(firstSelectedNode, lastSelectedNode);
}

function groupTextSlices(slices, keyForSlice) {
  const groups = [];

  for (let index = 0; index < slices.length; index += 1) {
    const slice = slices[index];
    const key = keyForSlice(slice, index);
    const current = groups.at(-1);

    if (current?.key === key) {
      current.slices.push(slice);
    } else {
      groups.push({ key, slices: [slice] });
    }
  }

  return groups;
}

function unwrapMatchingElements(fragment, selector) {
  const deepestFirst = [...fragment.querySelectorAll(selector)].sort(
    (left, right) => nodeDepth(right) - nodeDepth(left),
  );

  for (const element of deepestFirst) {
    element.replaceWith(...element.childNodes);
  }
}

function cloneFormatWithContents(element, contents) {
  const hasContent = [...contents.childNodes].some(
    (node) => !(node instanceof Text) || node.data !== '',
  );

  if (!hasContent) {
    return null;
  }

  const clone = element.cloneNode(false);

  if (!(clone instanceof HTMLElement)) {
    return null;
  }

  clone.append(contents);

  return clone;
}

function unwrapSelectedFormatGroup(group, selector) {
  const element = group.key;
  const firstSlice = group.slices[0];
  const lastSlice = group.slices.at(-1);
  const selectedRange = document.createRange();
  selectedRange.setStart(firstSlice.node, firstSlice.startOffset);
  selectedRange.setEnd(lastSlice.node, lastSlice.endOffset);

  const beforeRange = document.createRange();
  beforeRange.selectNodeContents(element);
  beforeRange.setEnd(firstSlice.node, firstSlice.startOffset);

  const afterRange = document.createRange();
  afterRange.selectNodeContents(element);
  afterRange.setStart(lastSlice.node, lastSlice.endOffset);

  const before = cloneFormatWithContents(element, beforeRange.cloneContents());
  const selected = selectedRange.cloneContents();
  const after = cloneFormatWithContents(element, afterRange.cloneContents());
  unwrapMatchingElements(selected, selector);

  const selectedNodes = [...selected.childNodes];
  element.replaceWith(...[before, ...selectedNodes, after].filter((node) => node !== null));

  return {
    firstNode: selectedNodes[0],
    lastNode: selectedNodes.at(-1),
  };
}

function unwrapSelectedFormats(slices, formatsBySlice, selector) {
  const groups = groupTextSlices(slices, (_slice, index) => formatsBySlice[index].at(-1));
  const selectedSpans = new Array(groups.length);

  for (let index = groups.length - 1; index >= 0; index -= 1) {
    selectedSpans[index] = unwrapSelectedFormatGroup(groups[index], selector);
  }

  selectNodeSpan(selectedSpans[0].firstNode, selectedSpans.at(-1).lastNode);
}

function inlineSelectionContainer(editor, node) {
  const container = node.parentElement?.closest(
    'p, h1, h2, h3, h4, h5, h6, li, blockquote, td, th',
  );

  return container instanceof HTMLElement && editor.contains(container) ? container : editor;
}

function wrapInlineSelectionByBlock(editor, range, createElement) {
  const slices = selectedTextSlices(editor, range);

  if (slices.length === 0) {
    return [];
  }

  const groups = groupTextSlices(slices, ({ node }) => inlineSelectionContainer(editor, node));
  const wrappers = new Array(groups.length);

  for (let index = groups.length - 1; index >= 0; index -= 1) {
    const firstSlice = groups[index].slices[0];
    const lastSlice = groups[index].slices.at(-1);
    const selectedRange = document.createRange();
    const wrapper = createElement();
    selectedRange.setStart(firstSlice.node, firstSlice.startOffset);
    selectedRange.setEnd(lastSlice.node, lastSlice.endOffset);
    wrapper.append(selectedRange.extractContents());
    selectedRange.insertNode(wrapper);
    wrappers[index] = wrapper;
  }

  selectNodeSpan(wrappers[0], wrappers.at(-1));

  return wrappers;
}

function toggleInlineFormat(editor, tagName, selector, fallbackText) {
  const range = selectionInside(editor);

  if (range === null) {
    return;
  }

  if (range.collapsed) {
    const existingFormats = matchingFormatAncestors(editor, range.startContainer, selector);

    if (existingFormats.length > 0) {
      unwrapFormatElements(existingFormats, range.startContainer, range.startContainer);
      return;
    }

    wrapSelectionWithElement(editor, document.createElement(tagName), fallbackText);
    return;
  }

  const slices = selectedTextSlices(editor, range);

  if (slices.length === 0) {
    return;
  }

  const formatsBySlice = slices.map(({ node }) => matchingFormatAncestors(editor, node, selector));

  if (formatsBySlice.every((formats) => formats.length > 0)) {
    unwrapSelectedFormats(slices, formatsBySlice, selector);
    return;
  }

  const formattedElements = new Set(formatsBySlice.flat());

  for (let index = slices.length - 1; index >= 0; index -= 1) {
    if (formatsBySlice[index].length > 0) {
      continue;
    }

    const { node, startOffset, endOffset } = slices[index];
    const selectedRange = document.createRange();
    const wrapper = document.createElement(tagName);
    selectedRange.setStart(node, startOffset);
    selectedRange.setEnd(node, endOffset);
    wrapper.append(selectedRange.extractContents());
    selectedRange.insertNode(wrapper);
    formattedElements.add(wrapper);
  }

  const documentOrderedFormats = [...formattedElements].sort((left, right) => {
    if (left === right) {
      return 0;
    }

    return left.compareDocumentPosition(right) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
  });
  selectNodeSpan(documentOrderedFormats[0], documentOrderedFormats.at(-1));
}

function selectedText(editor, fallback = '') {
  const range = selectionInside(editor);
  const text = range?.toString().trim() ?? '';

  return text === '' ? fallback : text;
}

function topLevelChildForRange(editor, range) {
  let current =
    range.commonAncestorContainer instanceof HTMLElement
      ? range.commonAncestorContainer
      : range.commonAncestorContainer.parentElement;

  while (current !== null && current.parentElement !== editor) {
    current = current.parentElement;
  }

  return current?.parentElement === editor ? current : null;
}

// Insert a block-level node at the collapsed range. A paragraph or heading
// cannot hold block children — the serialiser would glue `- item` markers onto
// its inline text — so the surrounding one is split at the caret and the block
// placed between the halves; empty halves are dropped.
function insertBlockNode(editor, range, block) {
  range.deleteContents();

  const host =
    range.endContainer instanceof HTMLElement
      ? range.endContainer
      : range.endContainer.parentElement;
  const paragraph = host?.closest('p, h1, h2, h3, h4, h5, h6') ?? null;

  if (paragraph === null || !editor.contains(paragraph)) {
    range.insertNode(block);
    return;
  }

  const tail = document.createRange();
  tail.selectNodeContents(paragraph);
  tail.setStart(range.endContainer, range.endOffset);
  const tailContents = tail.extractContents();

  paragraph.after(block);

  if ((tailContents.textContent ?? '').trim() !== '') {
    const continuation = document.createElement(paragraph.tagName);
    continuation.append(tailContents);
    block.after(continuation);
  }

  if ((paragraph.textContent ?? '').trim() === '') {
    paragraph.remove();
  }
}

function replaceSelectionWithBlock(editor, block) {
  const range = selectionInside(editor);

  if (range === null) {
    editor.append(block);
  } else {
    const topLevelChild = topLevelChildForRange(editor, range);
    const selected = range.toString().trim();
    const childText = topLevelChild?.textContent?.trim() ?? '';

    if (topLevelChild !== null && selected !== '' && selected === childText) {
      topLevelChild.replaceWith(block);
    } else {
      insertBlockNode(editor, range, block);
    }
  }

  if (block instanceof HTMLElement && block.matches('[data-editor-code-block]')) {
    const code = block.querySelector('[data-editor-code-content]');

    if (code instanceof HTMLElement) {
      selectInsertedText(code);
      return;
    }
  }

  selectInsertedText(block);
}

function blockFromSelection(editor, tagName, fallback) {
  const block = document.createElement(tagName);
  const text = selectedText(editor, fallback);

  block.textContent = text;
  replaceSelectionWithBlock(editor, block);
}

function listFromSelection(editor, ordered) {
  const list = document.createElement(ordered ? 'ol' : 'ul');
  const lines = selectedText(editor, 'List item')
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '');

  for (const line of lines.length === 0 ? ['List item'] : lines) {
    const item = document.createElement('li');
    item.textContent = line;
    list.append(item);
  }

  replaceSelectionWithBlock(editor, list);
}

function applyMermaidTemplate(select) {
  const wrapper = select.closest('[data-mermaid-diagram]');
  const source = wrapper?.querySelector('[data-editor-mermaid-source]');
  const template = mermaidTemplates[select.value] ?? mermaidTemplates.flowchart;

  if (!(wrapper instanceof HTMLElement) || !(source instanceof HTMLElement)) {
    return false;
  }

  source.textContent = template;
  wrapper.dataset.mermaidSource = template;
  wrapper.removeAttribute('data-mermaid-rendered');
  wrapper.querySelector('[data-mermaid-canvas]')?.replaceChildren();

  return true;
}

function activeEmbeddedSource(editor, target) {
  if (
    target instanceof HTMLElement &&
    target.matches('[data-editor-code-content], [data-editor-mermaid-source]')
  ) {
    return target;
  }

  const active = document.activeElement;

  if (
    active instanceof HTMLElement &&
    editor.contains(active) &&
    active.matches('[data-editor-code-content], [data-editor-mermaid-source]')
  ) {
    return active;
  }

  const range = selectionInside(editor);
  let current =
    range?.commonAncestorContainer instanceof HTMLElement
      ? range.commonAncestorContainer
      : range?.commonAncestorContainer.parentElement;

  while (current !== null && current !== editor) {
    if (current.matches('[data-editor-code-content], [data-editor-mermaid-source]')) {
      return current;
    }

    current = current.parentElement;
  }

  return null;
}

function createMermaidBlock(source = mermaidTemplates.flowchart) {
  const template = detectMermaidTemplate(source);
  const wrapper = document.createElement('div');
  wrapper.className = 'artifactflow-mermaid';
  wrapper.dataset.mermaidDiagram = '';
  wrapper.dataset.mermaidSource = source;
  wrapper.dataset.editorMermaidBlock = '';
  wrapper.contentEditable = 'false';

  wrapper.append(
    buildBlockHeader({
      headerClassName: 'artifactflow-mermaid-editor-header',
      labelText: 'Diagram',
      selectDataKey: 'editorMermaidTemplate',
      ariaLabel: 'Mermaid diagram type',
      options: mermaidTemplateOptions(),
      selected: template,
    }),
  );

  const canvas = document.createElement('div');
  canvas.className = 'artifactflow-mermaid-canvas';
  canvas.dataset.mermaidCanvas = '';
  canvas.setAttribute('role', 'img');
  canvas.setAttribute('aria-label', 'Mermaid diagram');
  wrapper.append(canvas);

  const details = document.createElement('details');
  details.className = 'artifactflow-mermaid-source';
  details.open = true;

  const summary = document.createElement('summary');
  summary.textContent = 'Diagram source';
  details.append(summary);

  const pre = document.createElement('pre');
  pre.className = 'artifactflow-mermaid-source-code';
  const code = document.createElement('code');
  code.className = 'language-mermaid';
  code.dataset.editorMermaidSource = '';
  code.contentEditable = 'true';
  code.spellcheck = false;
  code.textContent = source;
  pre.append(code);
  details.append(pre);
  wrapper.append(details);

  return wrapper;
}

function ensureMermaidEditorControls(wrapper) {
  wrapper.dataset.editorMermaidBlock = '';

  if (!wrapper.querySelector('[data-editor-mermaid-template]')) {
    const source = normaliseWhitespace(
      wrapper.querySelector('[data-editor-mermaid-source]')?.textContent ??
        wrapper.getAttribute('data-mermaid-source') ??
        '',
    ).trim();
    wrapper.prepend(
      buildBlockHeader({
        headerClassName: 'artifactflow-mermaid-editor-header',
        labelText: 'Diagram',
        selectDataKey: 'editorMermaidTemplate',
        ariaLabel: 'Mermaid diagram type',
        options: mermaidTemplateOptions(),
        selected: detectMermaidTemplate(source),
      }),
    );
  }
}

function prepareCodeBlocks(editor) {
  for (const pre of [...editor.querySelectorAll('pre')]) {
    if (pre.closest('[data-editor-code-block]') || pre.closest('[data-mermaid-diagram]')) {
      continue;
    }

    const code = pre.querySelector('code');
    const languageClass = [...(code?.classList ?? [])].find((className) =>
      className.startsWith('language-'),
    );
    const language = normalisedLanguageName(
      pre.dataset.editorLanguage ?? languageClass?.replace(/^language-/u, '') ?? '',
    );

    pre.replaceWith(
      createCodeBlock(language, normaliseWhitespace(pre.textContent ?? '').replace(/\n$/u, '')),
    );
  }

  for (const wrapper of editor.querySelectorAll('[data-editor-code-block]')) {
    const language = normalisedLanguageName(wrapper.dataset.editorLanguage ?? '');
    const code = wrapper.querySelector('[data-editor-code-content]');

    wrapper.contentEditable = 'false';
    wrapper.dataset.editorLanguage = language;

    if (code instanceof HTMLElement) {
      code.contentEditable = 'true';
      code.spellcheck = false;
      code.className = language === '' ? '' : `language-${language}`;
    }
  }
}

function prepareMermaidBlocks(editor) {
  for (const wrapper of editor.querySelectorAll('[data-mermaid-diagram]')) {
    wrapper.contentEditable = 'false';
    ensureMermaidEditorControls(wrapper);
    const canvas = wrapper.querySelector('[data-mermaid-canvas]');

    if (canvas instanceof HTMLElement) {
      canvas.contentEditable = 'false';
    }

    const code = wrapper.querySelector('.artifactflow-mermaid-source-code code');
    const details = wrapper.querySelector('details');

    if (details instanceof HTMLDetailsElement) {
      details.open = true;
    }

    if (code instanceof HTMLElement) {
      code.dataset.editorMermaidSource = '';
      code.contentEditable = 'true';
      code.spellcheck = false;
    }
  }

  ensureMermaidCaretBoundaries(editor);
}

async function renderMermaid(editor) {
  const { renderMermaidDiagrams } = await import('./mermaid-renderer');
  await renderMermaidDiagrams(editor);
}

function updateMermaidSource(target) {
  const source = target.closest('[data-editor-mermaid-source]');
  const wrapper = source?.closest('[data-mermaid-diagram]');

  if (!(source instanceof HTMLElement) || !(wrapper instanceof HTMLElement)) {
    return false;
  }

  wrapper.dataset.mermaidSource = editableSourceText(source).trim();
  wrapper.removeAttribute('data-mermaid-rendered');
  wrapper.querySelector('[data-mermaid-canvas]')?.replaceChildren();

  return true;
}

function insertPlainText(text) {
  insertTextAtSelection(text);
}

function insertTextAtSelection(text) {
  const selection = window.getSelection();

  if (selection === null || selection.rangeCount === 0) {
    return false;
  }

  const range = selection.getRangeAt(0);
  const node = document.createTextNode(text);

  range.deleteContents();
  range.insertNode(node);
  range.setStartAfter(node);
  range.setEndAfter(node);
  selection.removeAllRanges();
  selection.addRange(range);

  return true;
}

function insertLineBreakAtSelection() {
  const selection = window.getSelection();

  if (selection === null || selection.rangeCount === 0) {
    return false;
  }

  const range = selection.getRangeAt(0);
  const lineBreak = document.createElement('br');
  const caretAnchor = document.createTextNode('\u200b');

  range.deleteContents();
  range.insertNode(lineBreak);
  lineBreak.after(caretAnchor);
  range.setStart(caretAnchor, caretAnchor.data.length);
  range.setEnd(caretAnchor, caretAnchor.data.length);
  selection.removeAllRanges();
  selection.addRange(range);

  return true;
}

// A plain Enter escapes a code block only when the caret sits on an empty
// trailing line: the selection is collapsed at the end of the code content and
// that content ends with a line break (a <br>, serialised to "\n"). Mermaid
// sources never reach this — the caller gates on [data-editor-code-content].
function caretAtEmptyTrailingCodeLine(code) {
  const selection = window.getSelection();

  if (selection === null || selection.rangeCount === 0 || !selection.isCollapsed) {
    return false;
  }

  const range = selection.getRangeAt(0);

  if (!code.contains(range.endContainer)) {
    return false;
  }

  const tail = document.createRange();
  tail.selectNodeContents(code);
  tail.setStart(range.endContainer, range.endOffset);

  if (normaliseWhitespace(tail.toString()) !== '') {
    return false;
  }

  return /\n$/u.test(editableSourceText(code));
}

// Escape the code block: drop the empty trailing line (the <br> plus its
// zero-width caret anchor left by the preceding Enter) and drop the caret into
// a fresh paragraph immediately after the block.
function exitCodeBlock(code) {
  const block = code.closest('[data-editor-code-block]');

  if (!(block instanceof HTMLElement)) {
    return false;
  }

  let node = code.lastChild;

  while (node !== null) {
    const previous = node.previousSibling;
    const isBreak = node instanceof HTMLElement && node.tagName === 'BR';

    node.remove();

    if (isBreak) {
      break;
    }

    node = previous;
  }

  const paragraph = document.createElement('p');
  paragraph.append(document.createElement('br'));
  block.after(paragraph);
  placeCaretAtEnd(paragraph);

  return true;
}

export function initialiseRichMarkdownEditor(form, textarea, editor, status, count) {
  let lastMarkdownSource = textarea.value;
  let mermaidTimer;

  editor.dataset.editorEnhanced = 'true';
  editor.setAttribute('role', 'textbox');
  editor.setAttribute('aria-multiline', 'true');
  prepareCodeBlocks(editor);
  prepareMermaidBlocks(editor);

  if (editor.textContent?.trim() === '' && textarea.value.trim() === '') {
    const paragraph = document.createElement('p');
    paragraph.append(document.createElement('br'));
    editor.replaceChildren(paragraph);
  }

  const updateCount = (markdown) => {
    if (count instanceof HTMLElement) {
      const lines = markdown === '' ? 0 : markdown.split('\n').length;
      count.textContent = `${lines} lines · ${markdown.length} characters`;
    }
  };

  const sync = () => {
    const markdown = markdownFromRichEditor(editor);
    textarea.value = markdown;
    lastMarkdownSource = markdown;
    updateCount(markdown);

    if (status instanceof HTMLElement) {
      status.textContent = 'Unsaved changes';
    }

    return markdown;
  };

  const scheduleMermaidRender = () => {
    window.clearTimeout(mermaidTimer);
    mermaidTimer = window.setTimeout(() => {
      void renderMermaid(editor);
    }, 350);
  };

  const activate = (source, shouldFocus = true) => {
    if (source === lastMarkdownSource) {
      if (shouldFocus) {
        editor.focus();
      }

      return;
    }

    editor.replaceChildren(renderMarkdownSource(source));
    prepareCodeBlocks(editor);
    prepareMermaidBlocks(editor);
    lastMarkdownSource = source;
    updateCount(source);
    if (shouldFocus) {
      editor.focus();
    }

    void renderMermaid(editor);
  };

  editor.addEventListener('paste', (event) => {
    event.preventDefault();
    insertPlainText(event.clipboardData?.getData('text/plain') ?? '');
    sync();
  });

  editor.addEventListener('drop', (event) => {
    event.preventDefault();
    editor.focus();
    insertPlainText(event.dataTransfer?.getData('text/plain') ?? '');
    sync();
  });

  document.addEventListener('selectionchange', () => {
    rememberSelection(editor);
  });

  editor.addEventListener('keyup', () => {
    rememberSelection(editor);
  });

  editor.addEventListener('mouseup', () => {
    rememberSelection(editor);
  });

  editor.addEventListener('click', (event) => {
    if (event.target !== editor) {
      return;
    }

    const trailingCaret = [...editor.children]
      .reverse()
      .find((child) => child instanceof HTMLElement && child.matches('[data-editor-caret]'));

    if (trailingCaret instanceof HTMLElement) {
      placeCaretAtEnd(trailingCaret);
    }
  });

  editor.addEventListener('input', (event) => {
    sync();

    if (event.target instanceof HTMLElement && updateMermaidSource(event.target)) {
      scheduleMermaidRender();
    }
  });

  editor.addEventListener(
    'beforeinput',
    (event) => {
      const embeddedSource = activeEmbeddedSource(editor, event.target);

      if (
        embeddedSource === null ||
        !(event instanceof InputEvent) ||
        (event.inputType !== 'insertParagraph' && event.inputType !== 'insertLineBreak')
      ) {
        return;
      }

      event.preventDefault();
      insertLineBreakAtSelection();
      sync();

      if (updateMermaidSource(embeddedSource)) {
        scheduleMermaidRender();
      }
    },
    true,
  );

  editor.addEventListener('change', (event) => {
    if (
      event.target instanceof HTMLSelectElement &&
      event.target.matches('[data-editor-mermaid-template]')
    ) {
      if (applyMermaidTemplate(event.target)) {
        sync();
        void renderMermaid(editor);
      }
    }
  });

  editor.addEventListener(
    'keydown',
    (event) => {
      const embeddedSource = activeEmbeddedSource(editor, event.target);

      if (embeddedSource !== null) {
        if (event.key === 'Enter') {
          event.preventDefault();

          // Only code blocks can be escaped, and only with a plain Enter on an
          // already-empty trailing line (effective double-Enter). Shift+Enter
          // and Mermaid sources always fall through to a hard line break, so a
          // Mermaid caret stays fully trapped and intentional trailing blank
          // lines remain possible.
          if (
            !event.shiftKey &&
            embeddedSource.matches('[data-editor-code-content]') &&
            caretAtEmptyTrailingCodeLine(embeddedSource) &&
            exitCodeBlock(embeddedSource)
          ) {
            sync();
            return;
          }

          insertLineBreakAtSelection();
          sync();

          if (updateMermaidSource(embeddedSource)) {
            scheduleMermaidRender();
          }
          return;
        }

        if (event.key === 'Tab') {
          event.preventDefault();
          insertTextAtSelection('  ');
          sync();
          return;
        }
      }

      if (
        (event.metaKey || event.ctrlKey) &&
        event.key.toLowerCase() === 'a' &&
        embeddedSource !== null
      ) {
        event.preventDefault();
        selectInsertedText(embeddedSource);
        return;
      }

      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        sync();
        form.requestSubmit();
      }
    },
    true,
  );

  // A handler returning false aborts the trailing sync() (used when the link
  // prompt is cancelled or rejected); any other return value syncs as usual.
  const applyLinkAction = () => {
    const target = window.prompt('Link URL (https://, http://, mailto:, /path, or #anchor)');

    if (target === null) {
      return false;
    }

    const safeTarget = safeLinkTarget(target);

    if (safeTarget === null) {
      if (status instanceof HTMLElement) {
        status.textContent = 'That link URL is not allowed.';
      }
      return false;
    }

    const range = selectionInside(editor);

    if (range === null || range.collapsed) {
      const link = document.createElement('a');
      link.href = safeTarget;
      link.textContent = 'link text';
      insertNode(editor, link);
    } else {
      const commonParent =
        range.commonAncestorContainer instanceof HTMLElement
          ? range.commonAncestorContainer
          : range.commonAncestorContainer.parentElement;
      const existingLink = commonParent?.closest('a') ?? null;

      if (existingLink instanceof HTMLAnchorElement && editor.contains(existingLink)) {
        existingLink.href = safeTarget;
        selectInsertedText(existingLink);
      } else {
        wrapInlineSelectionByBlock(editor, range, () => {
          const link = document.createElement('a');
          link.href = safeTarget;

          return link;
        });
      }
    }

    return true;
  };

  const insertCodeBlock = () => {
    const block = createCodeBlock('', selectedText(editor, 'code'));
    replaceSelectionWithBlock(editor, block);
  };

  const insertMermaidBlock = () => {
    const block = createMermaidBlock();
    // Block-level insertion (paragraph splitting), NOT insertNode: a Mermaid
    // widget nested inside a paragraph would serialise glued onto its text.
    replaceSelectionWithBlock(editor, block);
    prepareMermaidBlocks(editor);
    const trailingCaret = block.nextElementSibling;

    if (trailingCaret instanceof HTMLElement) {
      placeCaretAtEnd(trailingCaret);
    }
    void renderMermaid(editor);
  };

  const editorActions = {
    heading: () => blockFromSelection(editor, 'h2', 'Heading'),
    paragraph: () => blockFromSelection(editor, 'p', 'Paragraph'),
    bold: () => toggleInlineFormat(editor, 'strong', 'strong, b', 'bold text'),
    italic: () => toggleInlineFormat(editor, 'em', 'em, i', 'italic text'),
    link: applyLinkAction,
    'unordered-list': () => listFromSelection(editor, false),
    'ordered-list': () => listFromSelection(editor, true),
    blockquote: () => blockFromSelection(editor, 'blockquote', 'Quote'),
    'horizontal-rule': () => replaceSelectionWithBlock(editor, document.createElement('hr')),
    'code-block': insertCodeBlock,
    mermaid: insertMermaidBlock,
  };

  for (const button of form.querySelectorAll('[data-editor-action]')) {
    button.addEventListener('mousedown', (event) => {
      event.preventDefault();
    });

    button.addEventListener('click', () => {
      editor.focus();
      const action = button.getAttribute('data-editor-action');
      const handler =
        action !== null && Object.hasOwn(editorActions, action) ? editorActions[action] : null;

      // Only applyLinkAction returns false (prompt cancelled) to skip the trailing
      // sync(); every other action returns undefined so the editor state is always
      // written back. Object.hasOwn stops a data-editor-action like "constructor"
      // from resolving a prototype member.
      if (handler && handler() === false) {
        return;
      }

      sync();
    });
  }

  for (const select of form.querySelectorAll('[data-editor-block-style]')) {
    select.addEventListener('change', () => {
      if (!(select instanceof HTMLSelectElement)) {
        return;
      }

      const value = select.value;
      editor.focus();

      if (value === 'p') {
        blockFromSelection(editor, 'p', 'Paragraph');
      } else if (/^h[1-6]$/u.test(value)) {
        blockFromSelection(editor, value, 'Heading');
      }

      sync();
    });
  }

  form.addEventListener('submit', () => {
    sync();

    if (status instanceof HTMLElement) {
      status.textContent = 'Saving…';
    }
  });

  updateCount(textarea.value);
  void renderMermaid(editor);

  return { activate, sync };
}
