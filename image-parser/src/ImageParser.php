<?php

declare(strict_types=1);

namespace ArtifactFlow\ImageParser;

use GdImage;
use RuntimeException;

class ParserRejection extends RuntimeException
{
}

final class ParserOutputTooLarge extends ParserRejection
{
}

class ParserAuthenticationFailure extends RuntimeException
{
}

final class ParserClockSkewFailure extends ParserAuthenticationFailure
{
}

final readonly class ParserConfiguration
{
    private const int MAX_INPUT_BYTES = 5 * 1024 * 1024;

    private const int MAX_OUTPUT_BYTES = 64 * 1024 * 1024;

    private const int MAX_PIXELS = 16 * 1024 * 1024;

    private const int MAX_DIMENSION = 16384;

    public function __construct(
        public string $sharedSecret,
        public int $maxInputBytes,
        public int $maxOutputBytes,
        public int $maxPixels,
        public int $maxDimension,
        public int $maxClockSkewSeconds,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $configuredSecret = self::environmentString('IMAGE_PARSER_SHARED_SECRET');
        $sharedSecret = self::normalizedSecret($configuredSecret);

        if ($sharedSecret === null || strlen($sharedSecret) < 32) {
            throw new RuntimeException('Image parser authentication is not configured.');
        }

        return new self(
            sharedSecret: $sharedSecret,
            maxInputBytes: self::MAX_INPUT_BYTES,
            maxOutputBytes: self::MAX_OUTPUT_BYTES,
            maxPixels: self::MAX_PIXELS,
            maxDimension: self::MAX_DIMENSION,
            maxClockSkewSeconds: min(
                300,
                self::positiveEnvironmentInteger('IMAGE_PARSER_MAX_CLOCK_SKEW_SECONDS', 120),
            ),
        );
    }

    private static function environmentString(string $key): string
    {
        $value = getenv($key);

        return is_string($value) ? trim($value) : '';
    }

    private static function positiveEnvironmentInteger(string $key, int $default): int
    {
        $configured = self::environmentString($key);

        if ($configured === '') {
            return $default;
        }

        $normalized = ltrim($configured, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximumAsString = (string) PHP_INT_MAX;

        if (
            !ctype_digit($configured)
            || strlen($normalized) > strlen($maximumAsString)
            || (strlen($normalized) === strlen($maximumAsString) && strcmp($normalized, $maximumAsString) > 0)
        ) {
            throw new RuntimeException('Image parser limit configuration is invalid.');
        }

        $value = (int) $normalized;

        if ($value < 1) {
            throw new RuntimeException('Image parser limit configuration is invalid.');
        }

        return $value;
    }

    private static function normalizedSecret(string $secret): ?string
    {
        if ($secret === '') {
            return null;
        }

        if (!str_starts_with($secret, 'base64:')) {
            return $secret;
        }

        $decoded = base64_decode(substr($secret, 7), true);

        return $decoded === false ? null : $decoded;
    }
}

final readonly class ParserRequest
{
    public function __construct(
        public string $nonce,
        public string $mediaType,
        public string $bytes,
        public int $maxOutputBytes,
        public int $maxInputBytes = 5 * 1024 * 1024,
        public int $maxPixels = 16 * 1024 * 1024,
        public int $maxDimension = 16384,
    ) {
    }

    public static function authenticated(ParserConfiguration $configuration): self
    {
        $timestamp = self::header('X-ArtifactFlow-Parser-Timestamp');
        $nonce = self::header('X-ArtifactFlow-Parser-Nonce');
        $signature = self::header('X-ArtifactFlow-Parser-Signature');
        $maxInputBytes = self::header('X-ArtifactFlow-Parser-Max-Input-Bytes');
        $maxOutputBytes = self::header('X-ArtifactFlow-Parser-Max-Output-Bytes');
        $maxPixels = self::header('X-ArtifactFlow-Parser-Max-Pixels');
        $maxDimension = self::header('X-ArtifactFlow-Parser-Max-Dimension');
        $mediaType = strtolower(trim((string) strtok(self::contentType(), ';')));
        $contentLength = self::contentLength();
        $timestampSeconds = filter_var(
            $timestamp,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (
            !is_int($timestampSeconds)
            || preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1
            || !ctype_digit($maxInputBytes)
            || !ctype_digit($maxOutputBytes)
            || !ctype_digit($maxPixels)
            || !ctype_digit($maxDimension)
        ) {
            throw new ParserAuthenticationFailure('Unauthenticated parser request.');
        }

        $maxInputByteCount = (int) $maxInputBytes;
        $maxOutputByteCount = (int) $maxOutputBytes;
        $maxPixelCount = (int) $maxPixels;
        $maxDimensionCount = (int) $maxDimension;

        if (
            !in_array($mediaType, ['image/png', 'image/jpeg'], true)
            || $contentLength < 1
            || $maxInputByteCount < 1
            || $maxInputByteCount > $configuration->maxInputBytes
            || $maxOutputByteCount < 1
            || $maxOutputByteCount > $configuration->maxOutputBytes
            || $maxPixelCount < 1
            || $maxPixelCount > $configuration->maxPixels
            || $maxDimensionCount < 1
            || $maxDimensionCount > $configuration->maxDimension
            || (string) $maxInputByteCount !== $maxInputBytes
            || (string) $maxOutputByteCount !== $maxOutputBytes
            || (string) $maxPixelCount !== $maxPixels
            || (string) $maxDimensionCount !== $maxDimension
            || $contentLength > $maxInputByteCount
        ) {
            throw new ParserRejection('Invalid parser request envelope.');
        }

        $bytes = file_get_contents('php://input', false, null, 0, $maxInputByteCount + 1);

        if (!is_string($bytes) || strlen($bytes) !== $contentLength) {
            throw new ParserRejection('Invalid parser request body.');
        }

        $expected = hash_hmac('sha256', implode("\n", [
            'artifactflow-image-parser-request-v3',
            $timestamp,
            $nonce,
            $mediaType,
            $maxInputBytes,
            $maxOutputBytes,
            $maxPixels,
            $maxDimension,
            hash('sha256', $bytes),
        ]), $configuration->sharedSecret);

        if (!hash_equals($expected, $signature)) {
            throw new ParserAuthenticationFailure('Unauthenticated parser request.');
        }

        // Diagnose clock synchronization only after the complete request HMAC
        // proves the caller knows the shared secret. This preserves a bounded
        // replay window without hiding split-host clock drift behind a generic
        // authentication failure.
        if (abs(time() - $timestampSeconds) > $configuration->maxClockSkewSeconds) {
            throw new ParserClockSkewFailure('Authenticated parser request is outside the clock-skew window.');
        }

        return new self(
            nonce: $nonce,
            mediaType: $mediaType,
            bytes: $bytes,
            maxOutputBytes: $maxOutputByteCount,
            maxInputBytes: $maxInputByteCount,
            maxPixels: $maxPixelCount,
            maxDimension: $maxDimensionCount,
        );
    }

    private static function header(string $name): string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$serverKey] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private static function contentType(): string
    {
        $value = $_SERVER['CONTENT_TYPE'] ?? '';

        return is_string($value) ? $value : '';
    }

    private static function contentLength(): int
    {
        $value = $_SERVER['CONTENT_LENGTH'] ?? '';

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }
}

final readonly class RasterInfo
{
    public function __construct(
        public string $mediaType,
        public int $width,
        public int $height,
    ) {
    }
}

final class PngDatastreamValidator
{
    private const string SIGNATURE = "\x89PNG\r\n\x1a\n";

    private const int MAX_CHUNKS = 4096;

    /**
     * libpng may materialize or inflate these metadata payloads during native
     * decode. They do not contribute pixels and are discarded by normalization,
     * so remove them after structural validation instead of letting them escape
     * pixel-based admission through native allocations.
     *
     * @var list<string>
     */
    private const array NATIVE_DECODE_EXCLUDED_METADATA_CHUNKS = ['zTXt', 'iTXt', 'iCCP'];

    /**
     * @var list<array{int, int, int, int}>
     */
    private const array ADAM7_PASSES = [
        [0, 0, 8, 8],
        [4, 0, 8, 8],
        [0, 4, 4, 8],
        [2, 0, 4, 4],
        [0, 2, 2, 4],
        [1, 0, 2, 2],
        [0, 1, 1, 2],
    ];

    public static function validatedForDecode(string $bytes, RasterInfo $expected): string
    {
        if (!str_starts_with($bytes, self::SIGNATURE)) {
            throw new ParserRejection('PNG image data is invalid.');
        }

        $length = strlen($bytes);
        $offset = strlen(self::SIGNATURE);
        $decodeBytes = self::SIGNATURE;
        $chunkCount = 0;
        $header = null;
        $paletteLength = null;
        $idatParts = [];
        $seenIdat = false;
        $idatEnded = false;
        $seenEnd = false;

        while ($offset < $length) {
            $chunkCount++;

            if ($chunkCount > self::MAX_CHUNKS || $length - $offset < 12) {
                throw new ParserRejection('PNG image data is invalid.');
            }

            $unpackedLength = unpack('Nlength', substr($bytes, $offset, 4));
            $chunkLength = is_array($unpackedLength) ? ($unpackedLength['length'] ?? null) : null;

            if (!is_int($chunkLength)) {
                throw new ParserRejection('PNG image data is invalid.');
            }

            $type = substr($bytes, $offset + 4, 4);
            $dataOffset = $offset + 8;
            $remaining = $length - $dataOffset;

            if (
                preg_match('/^[A-Za-z]{4}$/D', $type) !== 1
                || $remaining < 4
                || $chunkLength > $remaining - 4
            ) {
                throw new ParserRejection('PNG image data is invalid.');
            }

            $data = substr($bytes, $dataOffset, $chunkLength);
            $storedCrc = substr($bytes, $dataOffset + $chunkLength, 4);
            $crc = hash_init('crc32b');
            hash_update($crc, $type);
            hash_update($crc, $data);

            if (!hash_equals(hash_final($crc, true), $storedCrc)) {
                throw new ParserRejection('PNG image data is invalid.');
            }

            if ($chunkCount === 1 && ($type !== 'IHDR' || $chunkLength !== 13)) {
                throw new ParserRejection('PNG image data is invalid.');
            }

            if ($type === 'IHDR') {
                if ($header !== null || $chunkCount !== 1) {
                    throw new ParserRejection('PNG image data is invalid.');
                }

                $header = $data;
            } elseif ($type === 'PLTE') {
                if ($header === null || $paletteLength !== null || $seenIdat) {
                    throw new ParserRejection('PNG image data is invalid.');
                }

                $paletteLength = $chunkLength;
            } elseif ($type === 'IDAT') {
                if ($header === null || $idatEnded) {
                    throw new ParserRejection('PNG image data is invalid.');
                }

                $seenIdat = true;
                $idatParts[] = $data;
            } elseif ($type === 'IEND') {
                if ($chunkLength !== 0 || !$seenIdat) {
                    throw new ParserRejection('PNG image data is invalid.');
                }

                $seenEnd = true;
            } elseif ((ord($type[0]) & 0x20) === 0) {
                throw new ParserRejection('PNG image data is invalid.');
            } elseif ($seenIdat) {
                $idatEnded = true;
            }

            if (!in_array($type, self::NATIVE_DECODE_EXCLUDED_METADATA_CHUNKS, true)) {
                $decodeBytes .= substr($bytes, $offset, $chunkLength + 12);
            }

            $offset = $dataOffset + $chunkLength + 4;

            if ($seenEnd) {
                break;
            }
        }

        if ($header === null || !$seenEnd || $idatParts === []) {
            throw new ParserRejection('PNG image data is invalid.');
        }

        self::validateInflatedScanlines(
            header: $header,
            paletteLength: $paletteLength,
            compressed: implode('', $idatParts),
            expected: $expected,
        );

        return $decodeBytes;
    }

    private static function validateInflatedScanlines(
        string $header,
        ?int $paletteLength,
        string $compressed,
        RasterInfo $expected,
    ): void {
        $fields = unpack(
            'Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace',
            $header,
        );

        if (!is_array($fields)) {
            throw new ParserRejection('PNG image data is invalid.');
        }

        $width = $fields['width'] ?? null;
        $height = $fields['height'] ?? null;
        $bitDepth = $fields['bitDepth'] ?? null;
        $colorType = $fields['colorType'] ?? null;
        $compression = $fields['compression'] ?? null;
        $filter = $fields['filter'] ?? null;
        $interlace = $fields['interlace'] ?? null;

        if (
            !is_int($width)
            || !is_int($height)
            || !is_int($bitDepth)
            || !is_int($colorType)
            || !is_int($compression)
            || !is_int($filter)
            || !is_int($interlace)
            || $width !== $expected->width
            || $height !== $expected->height
            || $compression !== 0
            || $filter !== 0
            || !in_array($interlace, [0, 1], true)
        ) {
            throw new ParserRejection('PNG image data is invalid.');
        }

        $channels = match ($colorType) {
            0 => 1,
            2 => 3,
            3 => 1,
            4 => 2,
            6 => 4,
            default => 0,
        };
        $allowedBitDepths = match ($colorType) {
            0 => [1, 2, 4, 8, 16],
            2, 4, 6 => [8, 16],
            3 => [1, 2, 4, 8],
            default => [],
        };

        if ($channels === 0 || !in_array($bitDepth, $allowedBitDepths, true)) {
            throw new ParserRejection('PNG image data is invalid.');
        }

        self::validatePalette($colorType, $bitDepth, $paletteLength);

        $bitsPerPixel = $channels * $bitDepth;
        $expectedBytes = self::expectedInflatedBytes($width, $height, $bitsPerPixel, $interlace);
        $inflated = @zlib_decode($compressed, $expectedBytes + 1);

        if (!is_string($inflated) || strlen($inflated) !== $expectedBytes) {
            throw new ParserRejection('PNG image data is invalid.');
        }

        self::validateFilterBytes($inflated, $width, $height, $bitsPerPixel, $interlace);
    }

    private static function validatePalette(int $colorType, int $bitDepth, ?int $paletteLength): void
    {
        if ($colorType === 3) {
            if (
                $paletteLength === null
                || $paletteLength < 3
                || $paletteLength % 3 !== 0
                || $paletteLength > min(256, 1 << $bitDepth) * 3
            ) {
                throw new ParserRejection('PNG image data is invalid.');
            }

            return;
        }

        if (in_array($colorType, [0, 4], true) && $paletteLength !== null) {
            throw new ParserRejection('PNG image data is invalid.');
        }

        if ($paletteLength !== null && ($paletteLength < 3 || $paletteLength > 768 || $paletteLength % 3 !== 0)) {
            throw new ParserRejection('PNG image data is invalid.');
        }
    }

    private static function expectedInflatedBytes(
        int $width,
        int $height,
        int $bitsPerPixel,
        int $interlace,
    ): int {
        if ($interlace === 0) {
            return (self::scanlineBytes($width, $bitsPerPixel) + 1) * $height;
        }

        $total = 0;

        foreach (self::ADAM7_PASSES as [$startX, $startY, $stepX, $stepY]) {
            $passWidth = self::passDimension($width, $startX, $stepX);
            $passHeight = self::passDimension($height, $startY, $stepY);

            if ($passWidth > 0 && $passHeight > 0) {
                $total += (self::scanlineBytes($passWidth, $bitsPerPixel) + 1) * $passHeight;
            }
        }

        return $total;
    }

    private static function validateFilterBytes(
        string $inflated,
        int $width,
        int $height,
        int $bitsPerPixel,
        int $interlace,
    ): void {
        $offset = 0;

        if ($interlace === 0) {
            self::validatePassFilters($inflated, $offset, $width, $height, $bitsPerPixel);
        } else {
            foreach (self::ADAM7_PASSES as [$startX, $startY, $stepX, $stepY]) {
                $passWidth = self::passDimension($width, $startX, $stepX);
                $passHeight = self::passDimension($height, $startY, $stepY);

                if ($passWidth > 0 && $passHeight > 0) {
                    self::validatePassFilters($inflated, $offset, $passWidth, $passHeight, $bitsPerPixel);
                }
            }
        }

        if ($offset !== strlen($inflated)) {
            throw new ParserRejection('PNG image data is invalid.');
        }
    }

    private static function validatePassFilters(
        string $inflated,
        int &$offset,
        int $width,
        int $height,
        int $bitsPerPixel,
    ): void {
        $rowBytes = self::scanlineBytes($width, $bitsPerPixel);

        for ($row = 0; $row < $height; $row++) {
            if (ord($inflated[$offset]) > 4) {
                throw new ParserRejection('PNG image data is invalid.');
            }

            $offset += $rowBytes + 1;
        }
    }

    private static function scanlineBytes(int $width, int $bitsPerPixel): int
    {
        return intdiv(($width * $bitsPerPixel) + 7, 8);
    }

    private static function passDimension(int $dimension, int $start, int $step): int
    {
        return $dimension <= $start ? 0 : intdiv(($dimension - $start) + $step - 1, $step);
    }
}

final readonly class RasterNormalizer
{
    private const string HEALTHCHECK_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNQrrj7HwAEsgJ4Iys0WwAAAABJRU5ErkJggg==';

    public function __construct(
        private ParserConfiguration $configuration,
    ) {
    }

    public function verifyHealth(): void
    {
        $probe = base64_decode(self::HEALTHCHECK_PNG, true);

        if (!is_string($probe)) {
            throw new RuntimeException('Image parser health probe is invalid.');
        }

        [$image] = $this->normalize(new ParserRequest(
            nonce: str_repeat('0', 32),
            mediaType: 'image/png',
            bytes: $probe,
            maxOutputBytes: $this->configuration->maxOutputBytes,
            maxInputBytes: $this->configuration->maxInputBytes,
            maxPixels: $this->configuration->maxPixels,
            maxDimension: $this->configuration->maxDimension,
        ));

        if ($image->width !== 1 || $image->height !== 1 || $image->mediaType !== 'image/png') {
            throw new RuntimeException('Image parser health probe failed.');
        }
    }

    /**
     * @return array{RasterInfo, string}
     */
    public function normalize(ParserRequest $request): array
    {
        $input = $this->inspect(
            $request->bytes,
            min($request->maxInputBytes, $this->configuration->maxInputBytes),
            min($request->maxPixels, $this->configuration->maxPixels),
            min($request->maxDimension, $this->configuration->maxDimension),
        );
        $decodeBytes = $request->bytes;

        if ($input->mediaType !== $request->mediaType) {
            throw new ParserRejection('Image media type does not match its decoded format.');
        }

        if ($input->mediaType === 'image/png') {
            $decodeBytes = PngDatastreamValidator::validatedForDecode($request->bytes, $input);
        }

        $image = @imagecreatefromstring($decodeBytes);

        if (!$image instanceof GdImage) {
            throw new ParserRejection('Image could not be decoded.');
        }

        try {
            $image = $this->applyExifOrientation($image, $request->bytes, $input->mediaType);
            $normalized = $this->encode($image, $input->mediaType);
        } finally {
            unset($image);
        }

        if (strlen($normalized) > $request->maxOutputBytes) {
            throw new ParserOutputTooLarge('Normalized image exceeds the configured size limit.');
        }

        $output = $this->inspect(
            $normalized,
            min($request->maxOutputBytes, $this->configuration->maxOutputBytes),
            min($request->maxPixels, $this->configuration->maxPixels),
            min($request->maxDimension, $this->configuration->maxDimension),
        );

        if ($output->mediaType !== $input->mediaType) {
            throw new ParserRejection('Normalized image format changed unexpectedly.');
        }

        return [$output, $normalized];
    }

    private function inspect(string $bytes, int $maxBytes, int $maxPixels, int $maxDimension): RasterInfo
    {
        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            throw new ParserRejection('Image exceeds the configured size limit.');
        }

        $details = @getimagesizefromstring($bytes);

        if (!is_array($details)) {
            throw new ParserRejection('Image could not be decoded.');
        }

        $width = $details[0];
        $height = $details[1];
        $mediaType = $details['mime'];

        if (!in_array($mediaType, ['image/png', 'image/jpeg'], true)) {
            throw new ParserRejection('Only PNG and JPEG images are supported.');
        }

        if (
            $width < 1
            || $height < 1
            || $width > $maxDimension
            || $height > $maxDimension
            || $width > intdiv($maxPixels, $height)
        ) {
            throw new ParserRejection('Image dimensions exceed the configured limit.');
        }

        return new RasterInfo($mediaType, $width, $height);
    }

    private function encode(GdImage $image, string $mediaType): string
    {
        ob_start();

        try {
            if ($mediaType === 'image/jpeg') {
                $encoded = imagejpeg($image, null, 90);
            } else {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                $encoded = imagepng($image, null, 6, PNG_ALL_FILTERS);
            }

            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        if ($encoded !== true || $bytes === '') {
            throw new ParserRejection('Image normalization failed.');
        }

        return $bytes;
    }

    private function applyExifOrientation(GdImage $image, string $bytes, string $mediaType): GdImage
    {
        if ($mediaType !== 'image/jpeg') {
            return $image;
        }

        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new ParserRejection('Image normalization failed.');
        }

        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || rewind($stream) === false) {
                throw new ParserRejection('Image normalization failed.');
            }

            $metadata = @exif_read_data($stream, 'IFD0', true, false);
        } finally {
            fclose($stream);
        }

        $ifd = is_array($metadata) ? ($metadata['IFD0'] ?? null) : null;
        $orientation = is_array($ifd) ? ($ifd['Orientation'] ?? 1) : 1;
        $normalizedOrientation = filter_var(
            $orientation,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 8]],
        );

        return $this->orient($image, is_int($normalizedOrientation) ? $normalizedOrientation : 1);
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            $mode = $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL;

            imageflip($image, $mode);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        if (!$rotated instanceof GdImage) {
            throw new ParserRejection('Image orientation normalization failed.');
        }

        unset($image);

        return $rotated;
    }
}
