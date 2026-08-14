<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\DomainRuleViolation;

final readonly class RasterImageInspector
{
    /**
     * JPEG start-of-frame markers that carry dimensions.
     *
     * @var list<int>
     */
    private const array JPEG_START_OF_FRAME_MARKERS = [
        0xc0,
        0xc1,
        0xc2,
        0xc3,
        0xc5,
        0xc6,
        0xc7,
        0xc9,
        0xca,
        0xcb,
        0xcd,
        0xce,
        0xcf,
    ];

    /**
     * Bound marker processing independently of metadata byte length. Segment
     * payloads are skipped from their declared lengths, while fill-byte runs
     * use PHP's native strspn() rather than a byte-at-a-time PHP loop.
     */
    private const int JPEG_MAX_HEADER_MARKERS = 256;

    public function __construct(
        private ImageArtifactLimits $limits,
    ) {
    }

    public function inspectUpload(string $bytes): RasterImageInfo
    {
        return $this->inspect(
            bytes: $bytes,
            maxBytes: $this->limits->maxUploadBytes(),
            maxPixels: $this->limits->maxUploadPixels(),
            maxDimension: $this->limits->maxUploadDimension(),
        );
    }

    public function inspectStored(string $bytes): RasterImageInfo
    {
        return $this->inspect(
            bytes: $bytes,
            maxBytes: $this->limits->maxStoredBytes(),
            maxPixels: ImageArtifactLimits::STORED_MAX_PIXELS,
            maxDimension: ImageArtifactLimits::STORED_MAX_DIMENSION,
        );
    }

    private function inspect(
        string $bytes,
        int $maxBytes,
        int $maxPixels,
        int $maxDimension,
    ): RasterImageInfo {
        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            throw new DomainRuleViolation('Image exceeds the configured size limit.');
        }

        [$mediaType, $width, $height] = str_starts_with($bytes, "\x89PNG\r\n\x1a\n")
            ? $this->pngHeader($bytes)
            : $this->jpegHeader($bytes);

        if ($width < 1 || $height < 1) {
            throw new DomainRuleViolation('Image dimensions must be positive.');
        }

        if ($width > $maxDimension || $height > $maxDimension) {
            throw new DomainRuleViolation('Image dimensions exceed the configured limit.');
        }

        if ($width > intdiv($maxPixels, $height)) {
            throw new DomainRuleViolation('Image pixel count exceeds the configured limit.');
        }

        return new RasterImageInfo(
            mediaType: $mediaType,
            width: $width,
            height: $height,
            workload: $mediaType === 'image/png'
                ? $this->pngWorkload($bytes)
                : $this->jpegWorkload($bytes),
        );
    }

    /**
     * Dimension extraction is deliberately fixed-offset. The separate workload
     * walk below processes only length-prefixed chunk envelopes; native
     * decompression and pixel decoding belong exclusively to the isolated parser.
     *
     * @return array{string, int, int}
     */
    private function pngHeader(string $bytes): array
    {
        if (
            strlen($bytes) < 33
            || substr($bytes, 8, 8) !== "\x00\x00\x00\rIHDR"
        ) {
            throw new DomainRuleViolation('Image header is invalid.');
        }

        $dimensions = unpack('Nwidth/Nheight', substr($bytes, 16, 8));

        if (!is_array($dimensions)) {
            throw new DomainRuleViolation('Image header is invalid.');
        }

        $width = $dimensions['width'] ?? null;
        $height = $dimensions['height'] ?? null;

        if (!is_int($width) || !is_int($height)) {
            throw new DomainRuleViolation('Image header is invalid.');
        }

        return ['image/png', $width, $height];
    }

    private function pngWorkload(string $bytes): ImageNormalizationWorkload
    {
        $length = strlen($bytes);
        $offset = 8;
        $chunkCount = 0;
        $ancillaryBytes = 0;
        $seenEnd = false;

        while ($offset < $length) {
            $chunkCount++;

            if ($chunkCount > ImageArtifactLimits::MAX_PNG_CHUNKS || $length - $offset < 12) {
                throw new DomainRuleViolation('PNG structure exceeds the supported work limit.');
            }

            $unpackedLength = unpack('Nlength', substr($bytes, $offset, 4));
            $chunkLength = is_array($unpackedLength) ? ($unpackedLength['length'] ?? null) : null;

            if (!is_int($chunkLength)) {
                throw new DomainRuleViolation('Image header is invalid.');
            }

            $type = substr($bytes, $offset + 4, 4);
            $dataOffset = $offset + 8;
            $remaining = $length - $dataOffset;

            if (
                preg_match('/^[A-Za-z]{4}$/D', $type) !== 1
                || $remaining < 4
                || $chunkLength > $remaining - 4
            ) {
                throw new DomainRuleViolation('Image header is invalid.');
            }

            if ((ord($type[0]) & 0x20) !== 0) {
                $ancillaryBytes += $chunkLength;

                if ($ancillaryBytes > ImageArtifactLimits::MAX_PNG_ANCILLARY_BYTES) {
                    throw new DomainRuleViolation('PNG metadata exceeds the supported work limit.');
                }
            }

            $offset = $dataOffset + $chunkLength + 4;

            if ($type === 'IEND') {
                $seenEnd = true;

                break;
            }
        }

        if (!$seenEnd) {
            throw new DomainRuleViolation('Image header is invalid.');
        }

        return new ImageNormalizationWorkload($length, $ancillaryBytes, $chunkCount);
    }

    private function jpegWorkload(string $bytes): ImageNormalizationWorkload
    {
        $length = strlen($bytes);
        $offset = 2;
        $markerCount = 0;
        $metadataBytes = 0;

        while ($offset < $length && $markerCount < self::JPEG_MAX_HEADER_MARKERS) {
            if (ord($bytes[$offset]) !== 0xff) {
                break;
            }

            $offset += strspn($bytes, "\xff", $offset);

            if ($offset >= $length) {
                break;
            }

            $marker = ord($bytes[$offset]);
            $offset++;
            $markerCount++;

            if ($marker === 0xd9 || $marker === 0xda || ($marker >= 0xd0 && $marker <= 0xd7)) {
                break;
            }

            if ($marker === 0x01) {
                continue;
            }

            if ($offset + 2 > $length) {
                break;
            }

            $segmentLength = unpack('nlength', substr($bytes, $offset, 2));
            $unpackedSize = is_array($segmentLength) ? ($segmentLength['length'] ?? null) : null;
            $size = is_int($unpackedSize) ? $unpackedSize : 0;

            if ($size < 2 || $offset + $size > $length) {
                break;
            }

            if (($marker >= 0xe0 && $marker <= 0xef) || $marker === 0xfe) {
                $metadataBytes += $size - 2;
            }

            $offset += $size;
        }

        return new ImageNormalizationWorkload($length, $metadataBytes, $markerCount);
    }

    /**
     * Walk only JPEG's bounded length-prefixed marker envelope until a
     * start-of-frame segment provides dimensions. No entropy-coded scan data is
     * interpreted here; the isolated parser owns that native decode.
     *
     * @return array{string, int, int}
     */
    private function jpegHeader(string $bytes): array
    {
        $length = strlen($bytes);

        if ($length < 4 || substr($bytes, 0, 2) !== "\xff\xd8") {
            throw new DomainRuleViolation('Only PNG and JPEG images are supported.');
        }

        $offset = 2;
        $markerCount = 0;

        while ($offset < $length) {
            if (ord($bytes[$offset]) !== 0xff) {
                throw new DomainRuleViolation('Image header is invalid.');
            }

            $offset += strspn($bytes, "\xff", $offset);

            if ($offset >= $length) {
                break;
            }

            $marker = ord($bytes[$offset]);
            $offset++;
            $markerCount++;

            if ($markerCount > self::JPEG_MAX_HEADER_MARKERS) {
                break;
            }

            if ($marker === 0xd9 || $marker === 0xda) {
                break;
            }

            if ($marker >= 0xd0 && $marker <= 0xd7) {
                break;
            }

            if ($marker === 0x01) {
                continue;
            }

            if ($offset + 2 > $length) {
                break;
            }

            $segmentLength = unpack('nlength', substr($bytes, $offset, 2));
            $unpackedSize = is_array($segmentLength) ? ($segmentLength['length'] ?? null) : null;
            $size = is_int($unpackedSize) ? $unpackedSize : 0;

            if (
                $size < 2
                || $offset + $size > $length
            ) {
                break;
            }

            if (in_array($marker, self::JPEG_START_OF_FRAME_MARKERS, true)) {
                if ($size < 7) {
                    break;
                }

                $dimensions = unpack('nheight/nwidth', substr($bytes, $offset + 3, 4));

                if (!is_array($dimensions)) {
                    break;
                }

                $width = $dimensions['width'] ?? null;
                $height = $dimensions['height'] ?? null;

                if (!is_int($width) || !is_int($height)) {
                    break;
                }

                return ['image/jpeg', $width, $height];
            }

            $offset += $size;
        }

        throw new DomainRuleViolation('Image header is invalid.');
    }
}
