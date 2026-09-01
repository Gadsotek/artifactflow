'use strict';

const { TextDecoder } = require('node:util');
const { posix: path } = require('node:path');
const { SaxesParser } = require('saxes');

const CONTENT_TYPES_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/content-types';
const RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/relationships';
const XMLNS_NAMESPACE = 'http://www.w3.org/2000/xmlns/';
const XLSX_MAIN_CONTENT_TYPE =
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
const CONTENT_TYPE_BY_PATH = new Map([
  ['docProps/app.xml', 'application/vnd.openxmlformats-officedocument.extended-properties+xml'],
  ['docProps/core.xml', 'application/vnd.openxmlformats-package.core-properties+xml'],
  ['docProps/custom.xml', 'application/vnd.openxmlformats-officedocument.custom-properties+xml'],
  ['xl/calcChain.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.calcChain+xml'],
  [
    'xl/metadata.xml',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheetMetadata+xml',
  ],
  [
    'xl/sharedStrings.xml',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml',
  ],
  ['xl/styles.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml'],
  ['xl/workbook.xml', XLSX_MAIN_CONTENT_TYPE],
]);
const WORKBOOK_RELATIONSHIP_PREFIX =
  'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
const PACKAGE_RELATIONSHIP_PREFIX = 'http://schemas.openxmlformats.org/package/2006/relationships/';
const WORKSHEET_CUSTOM_PROPERTY_PART = /^xl\/customProperty[1-9][0-9]*\.bin$/u;
const UTF8_DECODER = new TextDecoder('utf-8', { fatal: true });

function projectionFailure(error) {
  return error?.name === 'XlsxProjectionError';
}

function decodedControlXml(entry, limits, fail) {
  if (entry.data.length > limits.maxControlXmlBytes) {
    fail('unsupported_xlsx_profile', 'An XLSX control XML part exceeds its size limit.');
  }

  try {
    return UTF8_DECODER.decode(entry.data);
  } catch {
    fail('unsupported_xlsx_profile', 'An XLSX control XML part is not valid UTF-8.');
  }
}

function attributeMap(node, fail) {
  const attributes = new Map();

  for (const attribute of Object.values(node.attributes)) {
    if (attribute.uri === XMLNS_NAMESPACE) {
      continue;
    }

    if (attribute.uri !== '' || attribute.prefix !== '' || attributes.has(attribute.local)) {
      fail('unsupported_xlsx_profile', 'The XLSX control XML uses unsupported attributes.');
    }

    attributes.set(attribute.local, attribute.value);
  }

  return attributes;
}

function exactAttributes(attributes, required, optional, fail) {
  const allowed = new Set([...required, ...optional]);

  if (
    required.some((name) => !attributes.has(name)) ||
    [...attributes.keys()].some((name) => !allowed.has(name))
  ) {
    fail('unsupported_xlsx_profile', 'The XLSX control XML uses an unsupported shape.');
  }
}

function parseControlXml(xml, onOpenTag, fail) {
  let depth = 0;
  let rootSeen = false;
  const parser = new SaxesParser({
    defaultXMLVersion: '1.0',
    forceXMLVersion: true,
    xmlns: true,
  });

  parser.on('xmldecl', (declaration) => {
    if (declaration.version !== undefined && declaration.version !== '1.0') {
      fail('unsupported_xlsx_profile', 'Only XML 1.0 control documents are supported.');
    }

    if (declaration.encoding !== undefined && declaration.encoding.toLowerCase() !== 'utf-8') {
      fail('unsupported_xlsx_profile', 'Only UTF-8 control documents are supported.');
    }
  });
  parser.on('doctype', () => {
    fail('unsupported_xlsx_profile', 'DTD declarations are not accepted in XLSX packages.');
  });
  parser.on('processinginstruction', () => {
    fail(
      'unsupported_xlsx_profile',
      'Processing instructions are not accepted in XLSX control XML.',
    );
  });
  parser.on('cdata', () => {
    fail('unsupported_xlsx_profile', 'CDATA is not accepted in XLSX control XML.');
  });
  parser.on('text', (text) => {
    if (text.trim() !== '') {
      fail('unsupported_xlsx_profile', 'Text is not accepted in XLSX control XML.');
    }
  });
  parser.on('opentag', (node) => {
    depth += 1;
    rootSeen = true;
    onOpenTag(node, depth);
  });
  parser.on('closetag', () => {
    depth -= 1;
  });
  parser.on('error', (error) => {
    throw error;
  });

  try {
    parser.write(xml).close();
  } catch (error) {
    if (projectionFailure(error)) {
      throw error;
    }

    fail('unsupported_xlsx_profile', 'The XLSX control XML is malformed.');
  }

  if (!rootSeen || depth !== 0) {
    fail('unsupported_xlsx_profile', 'The XLSX control XML is incomplete.');
  }
}

function validateXmlParts(entries, limits, fail) {
  let attributeCount = 0;
  let nodeCount = 0;
  let textBytes = 0;

  for (const [entryName, entry] of entries) {
    if (
      !entryName.endsWith('.xml') &&
      !entryName.endsWith('.rels') &&
      !entryName.endsWith('.vml')
    ) {
      continue;
    }

    let xml;

    try {
      xml = UTF8_DECODER.decode(entry.data);
    } catch {
      fail('unsupported_xlsx_profile', 'An XLSX XML part is not valid UTF-8.');
    }

    let depth = 0;
    let rootSeen = false;
    const parser = new SaxesParser({
      defaultXMLVersion: '1.0',
      forceXMLVersion: true,
      xmlns: true,
    });

    parser.on('xmldecl', (declaration) => {
      if (
        (declaration.version !== undefined && declaration.version !== '1.0') ||
        (declaration.encoding !== undefined && declaration.encoding.toLowerCase() !== 'utf-8')
      ) {
        fail('unsupported_xlsx_profile', 'Only UTF-8 XML 1.0 workbook parts are supported.');
      }
    });
    parser.on('doctype', () => {
      fail('unsupported_xlsx_profile', 'DTD declarations are not accepted in XLSX packages.');
    });
    parser.on('processinginstruction', () => {
      fail(
        'unsupported_xlsx_profile',
        'Processing instructions are not accepted in XLSX packages.',
      );
    });
    parser.on('cdata', () => {
      fail('unsupported_xlsx_profile', 'CDATA is not accepted in XLSX packages.');
    });
    parser.on('text', (text) => {
      textBytes += Buffer.byteLength(text, 'utf8');

      if (textBytes > limits.maxXmlTextBytes) {
        fail('unsupported_xlsx_profile', 'The XLSX package exceeds its XML text budget.');
      }
    });
    parser.on('opentag', (node) => {
      depth += 1;
      nodeCount += 1;
      attributeCount += Object.keys(node.attributes).length;
      rootSeen = true;

      if (
        depth > limits.maxXmlDepth ||
        nodeCount > limits.maxXmlNodes ||
        attributeCount > limits.maxXmlAttributes
      ) {
        fail('unsupported_xlsx_profile', 'The XLSX package exceeds its XML structure budget.');
      }
    });
    parser.on('closetag', () => {
      depth -= 1;
    });
    parser.on('error', (error) => {
      throw error;
    });

    try {
      parser.write(xml).close();
    } catch (error) {
      if (projectionFailure(error)) {
        throw error;
      }

      fail('unsupported_xlsx_profile', 'An XLSX XML part is malformed.');
    }

    if (!rootSeen || depth !== 0) {
      fail('unsupported_xlsx_profile', 'An XLSX XML part is incomplete.');
    }
  }
}

function normalizedPartName(partName, fail) {
  if (
    typeof partName !== 'string' ||
    !partName.startsWith('/') ||
    /[%\\?#\u0000-\u001f\u007f]/u.test(partName)
  ) {
    fail('unsupported_xlsx_profile', 'The XLSX package declares an invalid part name.');
  }

  const normalized = partName.slice(1);

  if (
    normalized.length === 0 ||
    path.normalize(normalized) !== normalized ||
    normalized
      .split('/')
      .some((segment) => segment.length === 0 || segment === '.' || segment === '..')
  ) {
    fail('unsupported_xlsx_profile', 'The XLSX package declares an ambiguous part name.');
  }

  return normalized;
}

function expectedContentType(entryName) {
  if (entryName.endsWith('.rels')) {
    return 'application/vnd.openxmlformats-package.relationships+xml';
  }

  if (CONTENT_TYPE_BY_PATH.has(entryName)) {
    return CONTENT_TYPE_BY_PATH.get(entryName);
  }

  if (/^xl\/worksheets\/sheet[1-9][0-9]*\.xml$/u.test(entryName)) {
    return 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml';
  }

  if (/^xl\/theme\/theme[1-9][0-9]*\.xml$/u.test(entryName)) {
    return 'application/vnd.openxmlformats-officedocument.theme+xml';
  }

  if (/^xl\/tables\/table[1-9][0-9]*\.xml$/u.test(entryName)) {
    return 'application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml';
  }

  if (/^xl\/drawings\/drawing[1-9][0-9]*\.xml$/u.test(entryName)) {
    return 'application/vnd.openxmlformats-officedocument.drawing+xml';
  }

  if (WORKSHEET_CUSTOM_PROPERTY_PART.test(entryName)) {
    return 'application/vnd.openxmlformats-officedocument.spreadsheetml.customProperty';
  }

  if (/^xl\/media\/[A-Za-z0-9_.-]+\.png$/u.test(entryName)) {
    return 'image/png';
  }

  if (/^xl\/media\/[A-Za-z0-9_.-]+\.jpe?g$/iu.test(entryName)) {
    return 'image/jpeg';
  }

  if (/^docProps\/thumbnail\.png$/iu.test(entryName)) {
    return 'image/png';
  }

  if (/^docProps\/thumbnail\.jpe?g$/iu.test(entryName)) {
    return 'image/jpeg';
  }

  return null;
}

function supportedPassiveContentType(entryName, contentType) {
  if (
    typeof contentType !== 'string' ||
    contentType.length === 0 ||
    contentType.length > 255 ||
    /[\u0000-\u0020\u007f]/u.test(contentType)
  ) {
    return false;
  }

  const lower = contentType.toLowerCase();
  const lowerEntryName = entryName.toLowerCase();
  if (
    [
      '/activex/',
      '/embeddings/',
      '/externallinks/',
      '/webextensions/',
      'customui/',
      'vbaproject',
    ].some((marker) => `/${lowerEntryName}`.includes(marker)) ||
    [
      'activex',
      'altchunk',
      'embeddedpackage',
      'macroenabled',
      'oleobject',
      'vbaproject',
      'webextension',
    ].some((marker) => lower.includes(marker))
  ) {
    return false;
  }

  const expected = expectedContentType(entryName);
  if (expected !== null) {
    return contentType === expected;
  }

  if (entryName.endsWith('.xml')) {
    return (
      contentType === 'application/xml' ||
      (/^application\/(?:vnd\.(?:openxmlformats-officedocument|ms-[a-z0-9.-]+)\.)[a-z0-9.+-]+\+xml$/iu.test(
        contentType,
      ) &&
        !lower.includes('externallink'))
    );
  }

  if (entryName.endsWith('.vml')) {
    return contentType === 'application/vnd.openxmlformats-officedocument.vmlDrawing';
  }

  const mediaExtension = /^xl\/media\/[A-Za-z0-9_.-]+\.(?<extension>[A-Za-z0-9]+)$/u
    .exec(entryName)
    ?.groups?.extension.toLowerCase();
  if (mediaExtension !== undefined) {
    return (
      new Map([
        ['bmp', ['image/bmp']],
        ['emf', ['image/emf', 'image/x-emf']],
        ['gif', ['image/gif']],
        ['tif', ['image/tiff']],
        ['tiff', ['image/tiff']],
        ['webp', ['image/webp']],
        ['wmf', ['image/wmf', 'image/x-wmf']],
      ])
        .get(mediaExtension)
        ?.includes(contentType) === true
    );
  }

  if (/^xl\/printerSettings\/[A-Za-z0-9_.-]+\.bin$/u.test(entryName)) {
    return (
      contentType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.printerSettings'
    );
  }

  return false;
}

function parseContentTypes(entry, entries, limits, fail) {
  const defaults = new Map();
  const overrides = new Map();
  let rootSeen = false;

  parseControlXml(
    decodedControlXml(entry, limits, fail),
    (node, depth) => {
      if (depth === 1) {
        if (node.local !== 'Types' || node.uri !== CONTENT_TYPES_NAMESPACE) {
          fail('unsupported_xlsx_profile', 'The package content-type root is invalid.');
        }

        rootSeen = true;
        return;
      }

      if (depth !== 2 || node.uri !== CONTENT_TYPES_NAMESPACE) {
        fail(
          'unsupported_xlsx_profile',
          'The package content-type document is nested unexpectedly.',
        );
      }

      const attributes = attributeMap(node, fail);

      if (node.local === 'Default') {
        exactAttributes(attributes, ['Extension', 'ContentType'], [], fail);
        const extension = attributes.get('Extension').toLowerCase();

        if (!/^[a-z0-9]{1,16}$/u.test(extension) || defaults.has(extension)) {
          fail(
            'unsupported_xlsx_profile',
            'The package has duplicate or invalid default content types.',
          );
        }

        defaults.set(extension, attributes.get('ContentType'));
        return;
      }

      if (node.local === 'Override') {
        exactAttributes(attributes, ['PartName', 'ContentType'], [], fail);
        const partName = normalizedPartName(attributes.get('PartName'), fail);

        if (overrides.has(partName)) {
          fail('unsupported_xlsx_profile', 'The package has duplicate content-type overrides.');
        }

        overrides.set(partName, attributes.get('ContentType'));
        return;
      }

      fail('unsupported_xlsx_profile', 'The package declares an unsupported content-type element.');
    },
    fail,
  );

  if (!rootSeen || overrides.get('xl/workbook.xml') !== XLSX_MAIN_CONTENT_TYPE) {
    fail(
      'unsupported_xlsx_profile',
      'The package does not declare the supported XLSX workbook type.',
    );
  }

  for (const [partName, contentType] of overrides) {
    if (!entries.has(partName) || !supportedPassiveContentType(partName, contentType)) {
      fail(
        'unsupported_xlsx_profile',
        'The package declares an unsupported content-type override.',
      );
    }
  }

  for (const entryName of entries.keys()) {
    if (entryName === '[Content_Types].xml') {
      continue;
    }

    const extension = entryName.includes('.')
      ? entryName.slice(entryName.lastIndexOf('.') + 1).toLowerCase()
      : '';
    const declared = overrides.get(entryName) ?? defaults.get(extension);

    if (!supportedPassiveContentType(entryName, declared)) {
      fail('unsupported_xlsx_profile', 'The package contains an unsupported or conflicting part.');
    }
  }
}

function relationshipSource(relationshipPart, fail) {
  if (relationshipPart === '_rels/.rels') {
    return null;
  }

  const match = /^(.*)\/_rels\/([^/]+)\.rels$/u.exec(relationshipPart);

  if (!match) {
    fail('unsupported_xlsx_profile', 'The package contains an invalid relationship part.');
  }

  return `${match[1]}/${match[2]}`;
}

function normalizedInternalTarget(source, target, allowParent, fail) {
  const rawTarget = target.startsWith('/') ? target.slice(1) : target;

  if (
    target.length === 0 ||
    target.length > 8_192 ||
    /[%\\?#\u0000-\u001f\u007f]/u.test(target) ||
    rawTarget.split('/').some((segment) => segment.length === 0 || segment === '.') ||
    (!allowParent && rawTarget.split('/').includes('..'))
  ) {
    fail('unsupported_xlsx_profile', 'The package contains an invalid relationship target.');
  }

  const baseSegments =
    target.startsWith('/') || source === null ? [] : path.dirname(source).split('/');
  const resolvedSegments = [...baseSegments];
  for (const segment of rawTarget.split('/')) {
    if (segment === '..') {
      if (resolvedSegments.length === 0) {
        fail('unsupported_xlsx_profile', 'The package contains an invalid relationship target.');
      }
      resolvedSegments.pop();
    } else {
      resolvedSegments.push(segment);
    }
  }
  const joined = resolvedSegments.join('/');

  if (
    joined.length === 0 ||
    path.normalize(joined) !== joined ||
    joined.split('/').some((segment) => segment.length === 0 || segment === '.' || segment === '..')
  ) {
    fail('unsupported_xlsx_profile', 'The package contains an ambiguous relationship target.');
  }

  return joined;
}

function validateExternalHyperlink(target, fail) {
  if (target.length === 0 || target.length > 8_192 || /[\u0000-\u001f\u007f]/u.test(target)) {
    fail(
      'unsupported_hyperlink',
      'The workbook contains an invalid external hyperlink relationship.',
    );
  }

  try {
    const url = new URL(target);

    if (
      !['http:', 'https:', 'mailto:'].includes(url.protocol) ||
      url.username !== '' ||
      url.password !== '' ||
      (url.protocol === 'mailto:' && target.slice(0, 9).toLowerCase() === 'mailto://')
    ) {
      fail(
        'unsupported_hyperlink',
        'The workbook contains an unsupported external hyperlink relationship.',
      );
    }
  } catch (error) {
    if (projectionFailure(error)) {
      throw error;
    }

    fail(
      'unsupported_hyperlink',
      'The workbook contains a malformed external hyperlink relationship.',
    );
  }
}

function relationshipRule(source, type) {
  if (source === null) {
    return new Map([
      [
        `${WORKBOOK_RELATIONSHIP_PREFIX}officeDocument`,
        { target: /^xl\/workbook\.xml$/u, unique: true },
      ],
      [
        `${PACKAGE_RELATIONSHIP_PREFIX}metadata/core-properties`,
        { target: /^docProps\/core\.xml$/u, unique: true },
      ],
      [
        `${WORKBOOK_RELATIONSHIP_PREFIX}extended-properties`,
        { target: /^docProps\/app\.xml$/u, unique: true },
      ],
      [
        `${WORKBOOK_RELATIONSHIP_PREFIX}custom-properties`,
        { target: /^docProps\/custom\.xml$/u, unique: true },
      ],
      [
        `${PACKAGE_RELATIONSHIP_PREFIX}metadata/thumbnail`,
        { target: /^docProps\/thumbnail\.(?:png|jpe?g)$/iu, unique: true },
      ],
    ]).get(type);
  }

  if (source === 'xl/workbook.xml') {
    const workbookRule = new Map([
      [
        `${WORKBOOK_RELATIONSHIP_PREFIX}worksheet`,
        { target: /^xl\/worksheets\/sheet[1-9][0-9]*\.xml$/u },
      ],
      [`${WORKBOOK_RELATIONSHIP_PREFIX}styles`, { target: /^xl\/styles\.xml$/u, unique: true }],
      [
        `${WORKBOOK_RELATIONSHIP_PREFIX}theme`,
        { target: /^xl\/theme\/theme[1-9][0-9]*\.xml$/u, unique: true },
      ],
      [
        `${WORKBOOK_RELATIONSHIP_PREFIX}sharedStrings`,
        { target: /^xl\/sharedStrings\.xml$/u, unique: true },
      ],
      [
        `${WORKBOOK_RELATIONSHIP_PREFIX}calcChain`,
        { target: /^xl\/calcChain\.xml$/u, unique: true },
      ],
      [
        `${WORKBOOK_RELATIONSHIP_PREFIX}sheetMetadata`,
        { target: /^xl\/metadata\.xml$/u, unique: true },
      ],
    ]).get(type);

    if (workbookRule !== undefined) {
      return workbookRule;
    }
  }

  if (
    /^xl\/worksheets\/sheet[1-9][0-9]*\.xml$/u.test(source) &&
    type === `${WORKBOOK_RELATIONSHIP_PREFIX}hyperlink`
  ) {
    return { external: true };
  }

  if (
    /^xl\/worksheets\/sheet[1-9][0-9]*\.xml$/u.test(source) &&
    type === `${WORKBOOK_RELATIONSHIP_PREFIX}customProperty`
  ) {
    return { allowParent: true, target: WORKSHEET_CUSTOM_PROPERTY_PART, customProperty: true };
  }

  const passiveRelationship =
    /^(?:http:\/\/schemas\.openxmlformats\.org\/officeDocument\/2006\/relationships\/|http:\/\/purl\.oclc\.org\/ooxml\/officeDocument\/relationships\/|http:\/\/schemas\.microsoft\.com\/office\/(?:[0-9]{4}(?:\/[0-9]{1,2})?\/)?relationships\/)(?<kind>[A-Za-z][A-Za-z0-9.-]{0,127})$/u.exec(
      type,
    );
  const kind = passiveRelationship?.groups?.kind ?? '';
  const lowerKind = kind.toLowerCase();
  if (
    source !== null &&
    passiveRelationship !== null &&
    ![
      'activexcontrol',
      'attachedtoolbars',
      'ctrlprop',
      'customproperty',
      'customui',
      'externallink',
      'externallinkpath',
      'oleobject',
      'package',
      'vbaproject',
      'webextension',
      'webextensiontaskpanes',
    ].includes(lowerKind)
  ) {
    return { allowParent: true, target: /^.+$/u };
  }

  return undefined;
}

function parseRelationshipPart(entry, source, limits, fail) {
  const relationships = [];
  const ids = new Set();
  const uniqueTypes = new Set();
  let rootSeen = false;

  parseControlXml(
    decodedControlXml(entry, limits, fail),
    (node, depth) => {
      if (depth === 1) {
        if (node.local !== 'Relationships' || node.uri !== RELATIONSHIPS_NAMESPACE) {
          fail('unsupported_xlsx_profile', 'The relationship document root is invalid.');
        }

        rootSeen = true;
        return;
      }

      if (depth !== 2 || node.local !== 'Relationship' || node.uri !== RELATIONSHIPS_NAMESPACE) {
        fail('unsupported_xlsx_profile', 'The relationship document has an unsupported shape.');
      }

      const attributes = attributeMap(node, fail);
      exactAttributes(attributes, ['Id', 'Type', 'Target'], ['TargetMode'], fail);
      const id = attributes.get('Id');
      const type = attributes.get('Type');
      const target = attributes.get('Target');
      const targetMode = attributes.get('TargetMode');
      const rule = relationshipRule(source, type);

      if (
        source === null &&
        type === `${WORKBOOK_RELATIONSHIP_PREFIX}officeDocument` &&
        targetMode !== undefined
      ) {
        fail('invalid_xlsx_package', 'The package root relationship is not a local workbook.');
      }

      if (
        !/^[A-Za-z_][A-Za-z0-9_.-]{0,255}$/u.test(id) ||
        ids.has(id) ||
        rule === undefined ||
        (targetMode !== undefined && targetMode !== 'External')
      ) {
        fail('unsupported_xlsx_profile', 'The package contains an unsupported relationship.');
      }

      ids.add(id);

      if (rule.unique === true) {
        if (uniqueTypes.has(type)) {
          fail(
            'unsupported_xlsx_profile',
            'The package contains a duplicate singleton relationship.',
          );
        }

        uniqueTypes.add(type);
      }

      if (rule.external === true) {
        if (targetMode !== 'External') {
          fail('unsupported_xlsx_profile', 'Hyperlink relationships must be explicitly external.');
        }

        validateExternalHyperlink(target, fail);
        relationships.push({ external: true, target, type });
      } else {
        if (targetMode !== undefined) {
          fail('unsupported_xlsx_profile', 'Only hyperlink relationships may be external.');
        }

        const normalizedTarget = normalizedInternalTarget(
          source,
          target,
          rule.allowParent === true,
          fail,
        );

        if (!rule.target.test(normalizedTarget)) {
          fail(
            'unsupported_xlsx_profile',
            'The relationship target does not match its declared type.',
          );
        }

        if (WORKSHEET_CUSTOM_PROPERTY_PART.test(normalizedTarget) && rule.customProperty !== true) {
          fail(
            'unsupported_xlsx_profile',
            'A worksheet custom-property part has an invalid relationship type.',
          );
        }

        relationships.push({ external: false, target: normalizedTarget, type });
      }

      if (relationships.length > limits.maxRelationships) {
        fail('unsupported_xlsx_profile', 'The package contains too many relationships.');
      }
    },
    fail,
  );

  if (!rootSeen) {
    fail('unsupported_xlsx_profile', 'The relationship document is empty.');
  }

  return relationships;
}

function validateRelationships(entries, limits, fail) {
  const relationshipsBySource = new Map();
  let relationshipCount = 0;

  for (const [entryName, entry] of entries) {
    if (!entryName.endsWith('.rels')) {
      continue;
    }

    const source = relationshipSource(entryName, fail);

    if (source !== null && !entries.has(source)) {
      fail('unsupported_xlsx_profile', 'A relationship part has no source part.');
    }

    const relationships = parseRelationshipPart(entry, source, limits, fail);
    relationshipCount += relationships.length;

    if (relationshipCount > limits.maxRelationships || relationshipsBySource.has(source)) {
      fail('unsupported_xlsx_profile', 'The package relationship count or ownership is invalid.');
    }

    relationshipsBySource.set(source, relationships);
  }

  const rootRelationships = relationshipsBySource.get(null);
  const workbookRelationships = relationshipsBySource.get('xl/workbook.xml');

  if (
    rootRelationships === undefined ||
    workbookRelationships === undefined ||
    rootRelationships.filter(
      (relationship) => relationship.type === `${WORKBOOK_RELATIONSHIP_PREFIX}officeDocument`,
    ).length !== 1 ||
    workbookRelationships.filter(
      (relationship) => relationship.type === `${WORKBOOK_RELATIONSHIP_PREFIX}worksheet`,
    ).length === 0
  ) {
    fail('unsupported_xlsx_profile', 'The package relationship graph is incomplete.');
  }

  const reachable = new Set();
  const queue = [null];

  while (queue.length > 0) {
    const source = queue.shift();

    for (const relationship of relationshipsBySource.get(source) ?? []) {
      if (relationship.external || reachable.has(relationship.target)) {
        continue;
      }

      if (!entries.has(relationship.target)) {
        fail('unsupported_xlsx_profile', 'A relationship points to a missing package part.');
      }

      reachable.add(relationship.target);
      queue.push(relationship.target);
    }
  }

  for (const entryName of entries.keys()) {
    if (entryName === '[Content_Types].xml' || entryName === '_rels/.rels') {
      continue;
    }

    if (entryName.endsWith('.rels')) {
      const source = relationshipSource(entryName, fail);

      if (source === null || !reachable.has(source)) {
        fail('unsupported_xlsx_profile', 'The package contains an unreachable relationship part.');
      }

      continue;
    }

    if (!reachable.has(entryName)) {
      fail('unsupported_xlsx_profile', 'The package contains an unreachable or unreferenced part.');
    }
  }
}

function validateOpcProfile(entries, limits, fail) {
  const contentTypes = entries.get('[Content_Types].xml');
  const rootRelationships = entries.get('_rels/.rels');
  const workbook = entries.get('xl/workbook.xml');

  if (!contentTypes || !rootRelationships || !workbook) {
    fail('invalid_xlsx_package', 'The XLSX package is missing required workbook parts.');
  }

  validateXmlParts(entries, limits, fail);
  parseContentTypes(contentTypes, entries, limits, fail);
  validateRelationships(entries, limits, fail);
}

module.exports = { validateOpcProfile };
