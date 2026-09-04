'use strict';

const { TextDecoder } = require('node:util');
const zlib = require('node:zlib');

const UTF8_DECODER = new TextDecoder('utf-8', { fatal: true });
const CRC32_TABLE = Uint32Array.from({ length: 256 }, (_, value) => {
  let current = value;

  for (let bit = 0; bit < 8; bit += 1) {
    current = (current & 1) === 1 ? 0xedb88320 ^ (current >>> 1) : current >>> 1;
  }

  return current >>> 0;
});

function crc32(input) {
  let checksum = 0xffffffff;

  for (const byte of input) {
    checksum = CRC32_TABLE[(checksum ^ byte) & 0xff] ^ (checksum >>> 8);
  }

  return (checksum ^ 0xffffffff) >>> 0;
}

function locateEndOfCentralDirectory(input) {
  const minimumOffset = Math.max(0, input.length - 65_557);

  for (let offset = input.length - 22; offset >= minimumOffset; offset -= 1) {
    if (input.readUInt32LE(offset) !== 0x06054b50) {
      continue;
    }

    const commentLength = input.readUInt16LE(offset + 20);

    if (offset + 22 + commentLength === input.length) {
      return offset;
    }
  }

  return -1;
}

function decodeEntryName(rawName, limits, fail) {
  let name;

  try {
    name = UTF8_DECODER.decode(rawName);
  } catch {
    fail('invalid_zip_entry', 'The XLSX package contains a non-UTF-8 entry path.');
  }

  if (
    rawName.length === 0 ||
    rawName.length > limits.maxEntryNameBytes ||
    name.normalize('NFC') !== name ||
    [...name].some((character) => !/^[A-Za-z0-9._/\-\[\]]$/u.test(character))
  ) {
    fail('invalid_zip_entry', 'The XLSX package contains an invalid entry path.');
  }

  const segments = name.split('/');

  if (
    segments.length > limits.maxEntryPathDepth ||
    segments.some(
      (segment) =>
        segment.length === 0 ||
        segment === '.' ||
        segment === '..' ||
        Buffer.byteLength(segment, 'utf8') > 255,
    )
  ) {
    fail('invalid_zip_entry', 'The XLSX package contains an invalid entry path.');
  }

  return name;
}

function expandedEntry(input, entry, remainingBytes, fail) {
  const compressed = input.subarray(entry.dataOffset, entry.dataEnd);
  let expanded;

  if (entry.compressionMethod === 0) {
    if (compressed.length > remainingBytes) {
      fail('expanded_size_limit_exceeded', 'The XLSX package exceeds expansion limits.');
    }

    expanded = Buffer.from(compressed);
  } else {
    try {
      expanded = zlib.inflateRawSync(compressed, {
        maxOutputLength: Math.max(1, remainingBytes + 1),
      });
    } catch (error) {
      if (error?.code === 'ERR_BUFFER_TOO_LARGE') {
        fail('expanded_size_limit_exceeded', 'The XLSX package exceeds expansion limits.');
      }

      fail('invalid_zip_structure', 'The XLSX package contains invalid compressed data.');
    }
  }

  if (expanded.length > remainingBytes) {
    fail('expanded_size_limit_exceeded', 'The XLSX package exceeds expansion limits.');
  }

  if (expanded.length !== entry.uncompressedSize || crc32(expanded) !== entry.crc32) {
    fail('invalid_zip_structure', 'The XLSX package entry size or checksum is inconsistent.');
  }

  return expanded;
}

function canonicalZipPackage(input, entries) {
  const localParts = [];
  const centralParts = [];
  let localOffset = 0;

  for (const entry of entries.values()) {
    const compressed = input.subarray(entry.dataOffset, entry.dataEnd);
    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt16LE(0x0800, 6);
    local.writeUInt16LE(entry.compressionMethod, 8);
    local.writeUInt32LE(entry.crc32, 14);
    local.writeUInt32LE(entry.compressedSize, 18);
    local.writeUInt32LE(entry.uncompressedSize, 22);
    local.writeUInt16LE(entry.rawName.length, 26);

    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    central.writeUInt16LE(20, 4);
    central.writeUInt16LE(20, 6);
    central.writeUInt16LE(0x0800, 8);
    central.writeUInt16LE(entry.compressionMethod, 10);
    central.writeUInt32LE(entry.crc32, 16);
    central.writeUInt32LE(entry.compressedSize, 20);
    central.writeUInt32LE(entry.uncompressedSize, 24);
    central.writeUInt16LE(entry.rawName.length, 28);
    central.writeUInt32LE(0x81a40000, 38);
    central.writeUInt32LE(localOffset, 42);

    const localRecord = Buffer.concat([local, entry.rawName, compressed]);
    localParts.push(localRecord);
    centralParts.push(central, entry.rawName);
    localOffset += localRecord.length;
  }

  const centralDirectory = Buffer.concat(centralParts);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(entries.size, 8);
  end.writeUInt16LE(entries.size, 10);
  end.writeUInt32LE(centralDirectory.length, 12);
  end.writeUInt32LE(localOffset, 16);

  return Buffer.concat([...localParts, centralDirectory, end]);
}

function inspectZipPackage(input, limits, fail) {
  if (!Buffer.isBuffer(input) || input.length < 22 || input.length > limits.maxInputBytes) {
    fail('not_xlsx_zip', 'The upload is not a bounded XLSX ZIP package.');
  }

  if (input.readUInt32LE(0) !== 0x04034b50) {
    fail('not_xlsx_zip', 'The upload is not an XLSX ZIP package.');
  }

  const endOffset = locateEndOfCentralDirectory(input);

  if (endOffset < 0) {
    fail('invalid_zip_structure', 'The XLSX ZIP directory is missing or ambiguous.');
  }

  const diskNumber = input.readUInt16LE(endOffset + 4);
  const centralDisk = input.readUInt16LE(endOffset + 6);
  const diskEntries = input.readUInt16LE(endOffset + 8);
  const totalEntries = input.readUInt16LE(endOffset + 10);
  const centralSize = input.readUInt32LE(endOffset + 12);
  const centralOffset = input.readUInt32LE(endOffset + 16);

  if (
    diskNumber !== 0 ||
    centralDisk !== 0 ||
    diskEntries !== totalEntries ||
    totalEntries === 0xffff ||
    centralSize === 0xffffffff ||
    centralOffset === 0xffffffff
  ) {
    fail('unsupported_zip_structure', 'Split and Zip64 XLSX packages are not supported.');
  }

  if (
    totalEntries === 0 ||
    totalEntries > limits.maxEntries ||
    centralSize > limits.maxCentralDirectoryBytes ||
    centralOffset + centralSize !== endOffset
  ) {
    fail('invalid_zip_structure', 'The XLSX ZIP directory exceeds the accepted profile.');
  }

  const entries = new Map();
  const caseFoldedNames = new Set();
  let offset = centralOffset;
  let declaredExpandedBytes = 0;

  for (let index = 0; index < totalEntries; index += 1) {
    if (offset + 46 > endOffset || input.readUInt32LE(offset) !== 0x02014b50) {
      fail('invalid_zip_structure', 'The XLSX ZIP directory is malformed.');
    }

    const flags = input.readUInt16LE(offset + 8);
    const compressionMethod = input.readUInt16LE(offset + 10);
    const entryCrc32 = input.readUInt32LE(offset + 16);
    const compressedSize = input.readUInt32LE(offset + 20);
    const uncompressedSize = input.readUInt32LE(offset + 24);
    const nameLength = input.readUInt16LE(offset + 28);
    const extraLength = input.readUInt16LE(offset + 30);
    const commentLength = input.readUInt16LE(offset + 32);
    const diskStart = input.readUInt16LE(offset + 34);
    const externalAttributes = input.readUInt32LE(offset + 38);
    const localHeaderOffset = input.readUInt32LE(offset + 42);
    const nextOffset = offset + 46 + nameLength + extraLength + commentLength;

    if (nextOffset > endOffset || nameLength === 0 || diskStart !== 0) {
      fail('invalid_zip_structure', 'The XLSX ZIP directory entry is invalid.');
    }

    if ((flags & 0x0041) !== 0) {
      fail('unsupported_zip_structure', 'Encrypted ZIP entries are not supported.');
    }

    if ((flags & ~0x080e) !== 0 || (compressionMethod === 0 && (flags & 0x0006) !== 0)) {
      fail('unsupported_zip_structure', 'The XLSX ZIP entry flags are not supported.');
    }

    if (compressionMethod !== 0 && compressionMethod !== 8) {
      fail('unsupported_zip_compression', 'The XLSX package uses unsupported compression.');
    }

    const rawName = input.subarray(offset + 46, offset + 46 + nameLength);
    const name = decodeEntryName(rawName, limits, fail);
    const foldedName = name.toLowerCase();
    const unixFileType = (externalAttributes >>> 16) & 0xf000;

    if (unixFileType !== 0 && unixFileType !== 0x8000) {
      fail('invalid_zip_entry', 'Only regular-file entries are accepted in XLSX packages.');
    }

    if (entries.has(name) || caseFoldedNames.has(foldedName)) {
      fail('duplicate_zip_entry', 'Duplicate XLSX package entries are not accepted.');
    }

    declaredExpandedBytes += uncompressedSize;

    if (
      declaredExpandedBytes > limits.maxExpandedBytes ||
      uncompressedSize > limits.maxExpandedBytes ||
      compressedSize > limits.maxInputBytes ||
      (compressedSize === 0 && uncompressedSize !== 0) ||
      (compressedSize > 0 && uncompressedSize / compressedSize > 200)
    ) {
      fail('expanded_size_limit_exceeded', 'The XLSX package exceeds expansion limits.');
    }

    entries.set(name, {
      compressedSize,
      compressionMethod,
      crc32: entryCrc32,
      flags,
      localHeaderOffset,
      name,
      rawName,
      uncompressedSize,
    });
    caseFoldedNames.add(foldedName);
    offset = nextOffset;
  }

  if (offset !== endOffset) {
    fail('invalid_zip_structure', 'The XLSX ZIP directory length is inconsistent.');
  }

  const intervals = [];

  for (const entry of entries.values()) {
    const localOffset = entry.localHeaderOffset;

    if (localOffset + 30 > centralOffset || input.readUInt32LE(localOffset) !== 0x04034b50) {
      fail('invalid_zip_structure', 'The XLSX package has an invalid local header.');
    }

    const localFlags = input.readUInt16LE(localOffset + 6);
    const localMethod = input.readUInt16LE(localOffset + 8);
    const localCrc32 = input.readUInt32LE(localOffset + 14);
    const localCompressedSize = input.readUInt32LE(localOffset + 18);
    const localUncompressedSize = input.readUInt32LE(localOffset + 22);
    const localNameLength = input.readUInt16LE(localOffset + 26);
    const localExtraLength = input.readUInt16LE(localOffset + 28);
    const nameStart = localOffset + 30;
    const dataOffset = nameStart + localNameLength + localExtraLength;
    const dataEnd = dataOffset + entry.compressedSize;
    const localName = input.subarray(nameStart, nameStart + localNameLength);
    const usesDataDescriptor = (entry.flags & 0x0008) !== 0;
    const localFactsMatch = usesDataDescriptor
      ? [
          [localCrc32, entry.crc32],
          [localCompressedSize, entry.compressedSize],
          [localUncompressedSize, entry.uncompressedSize],
        ].every(([local, central]) => local === 0 || local === central)
      : localCrc32 === entry.crc32 &&
        localCompressedSize === entry.compressedSize &&
        localUncompressedSize === entry.uncompressedSize;

    if (
      localFlags !== entry.flags ||
      localMethod !== entry.compressionMethod ||
      !localFactsMatch ||
      localNameLength !== entry.rawName.length ||
      !localName.equals(entry.rawName) ||
      dataOffset > centralOffset ||
      dataEnd > centralOffset
    ) {
      fail('invalid_zip_structure', 'The XLSX package headers disagree.');
    }

    let localRecordEnd = dataEnd;
    if (usesDataDescriptor) {
      const descriptorEnds = [];
      if (
        dataEnd + 12 <= centralOffset &&
        input.readUInt32LE(dataEnd) === entry.crc32 &&
        input.readUInt32LE(dataEnd + 4) === entry.compressedSize &&
        input.readUInt32LE(dataEnd + 8) === entry.uncompressedSize
      ) {
        descriptorEnds.push(dataEnd + 12);
      }
      if (
        dataEnd + 16 <= centralOffset &&
        input.readUInt32LE(dataEnd) === 0x08074b50 &&
        input.readUInt32LE(dataEnd + 4) === entry.crc32 &&
        input.readUInt32LE(dataEnd + 8) === entry.compressedSize &&
        input.readUInt32LE(dataEnd + 12) === entry.uncompressedSize
      ) {
        descriptorEnds.push(dataEnd + 16);
      }
      if (descriptorEnds.length !== 1) {
        fail('invalid_zip_structure', 'The XLSX package data descriptor is invalid or ambiguous.');
      }
      [localRecordEnd] = descriptorEnds;
    }

    entry.dataOffset = dataOffset;
    entry.dataEnd = dataEnd;
    intervals.push({ end: localRecordEnd, start: localOffset });
  }

  intervals.sort((left, right) => left.start - right.start);
  let expectedOffset = 0;

  for (const interval of intervals) {
    if (interval.start !== expectedOffset || interval.end <= interval.start) {
      fail('invalid_zip_structure', 'The XLSX package contains overlapping or ambiguous records.');
    }

    expectedOffset = interval.end;
  }

  if (expectedOffset !== centralOffset) {
    fail('invalid_zip_structure', 'The XLSX package contains unaccounted archive bytes.');
  }

  let actualExpandedBytes = 0;

  for (const entry of entries.values()) {
    entry.data = expandedEntry(input, entry, limits.maxExpandedBytes - actualExpandedBytes, fail);
    actualExpandedBytes += entry.data.length;
  }

  return {
    actualExpandedBytes,
    canonicalBytes: canonicalZipPackage(input, entries),
    entries,
  };
}

module.exports = { inspectZipPackage };
