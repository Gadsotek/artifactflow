<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use ArtifactFlow\ImageParser\ParserConfiguration;
use ArtifactFlow\ImageParser\ParserOutputTooLarge;
use ArtifactFlow\ImageParser\ParserRejection;
use ArtifactFlow\ImageParser\ParserRequest;
use ArtifactFlow\ImageParser\PngDatastreamValidator;
use ArtifactFlow\ImageParser\RasterInfo;
use ArtifactFlow\ImageParser\RasterNormalizer;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ImageParserServiceIntegrationTest extends TestCase
{
    private const string SHARED_SECRET = 'test-image-parser-shared-secret-0001';

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(ParserConfiguration::class)) {
            require_once base_path('image-parser/src/ImageParser.php');
        }
    }

    public function test_parser_request_signature_binds_the_exact_body_bytes(): void
    {
        $signedBody = $this->png();
        $tamperedBody = $signedBody;
        $tamperedBody[32] = $tamperedBody[32] === "\x00" ? "\x01" : "\x00";
        $timestamp = (string) time();
        $nonce = str_repeat('a', 32);
        $maxInputBytes = '1048576';
        $maxOutputBytes = '1048576';
        $maxPixels = '100';
        $maxDimension = '100';
        $signature = hash_hmac('sha256', implode("\n", [
            'artifactflow-image-parser-request-v3',
            $timestamp,
            $nonce,
            'image/png',
            $maxInputBytes,
            $maxOutputBytes,
            $maxPixels,
            $maxDimension,
            hash('sha256', $signedBody),
        ]), self::SHARED_SECRET);

        $accepted = $this->parserRequest(
            $signedBody,
            $timestamp,
            $nonce,
            $maxInputBytes,
            $maxOutputBytes,
            $maxPixels,
            $maxDimension,
            $signature,
        );
        $rejected = $this->parserRequest(
            $tamperedBody,
            $timestamp,
            $nonce,
            $maxInputBytes,
            $maxOutputBytes,
            $maxPixels,
            $maxDimension,
            $signature,
        );

        $this->assertSame(200, $this->parserStatus($accepted));
        $this->assertMatchesRegularExpression(
            '/^X-ArtifactFlow-Parser-Signature: [a-f0-9]{64}\r?$/m',
            $accepted,
        );
        $this->assertMatchesRegularExpression('/^X-ArtifactFlow-Parser-Width: 1\r?$/m', $accepted);
        $this->assertMatchesRegularExpression('/^X-ArtifactFlow-Parser-Height: 1\r?$/m', $accepted);
        $this->assertSame(401, $this->parserStatus($rejected));
        $this->assertStringContainsString('{"error":"unauthenticated"}', $rejected);
    }

    public function test_parser_uses_the_signed_output_budget_instead_of_the_upload_budget(): void
    {
        $input = $this->png();
        $configuration = new ParserConfiguration(
            sharedSecret: str_repeat('s', 32),
            maxInputBytes: strlen($input),
            maxOutputBytes: 1024 * 1024,
            maxPixels: 100,
            maxDimension: 100,
            maxClockSkewSeconds: 30,
        );
        $request = new ParserRequest(
            nonce: str_repeat('a', 32),
            mediaType: 'image/png',
            bytes: $input,
            maxOutputBytes: 1024 * 1024,
        );

        [$image, $normalized] = (new RasterNormalizer($configuration))->normalize($request);

        $this->assertSame('image/png', $image->mediaType);
        $this->assertNotSame('', $normalized);
        $this->assertGreaterThan(strlen($input), strlen($normalized));
    }

    public function test_parser_distinguishes_a_derivative_that_exceeds_the_output_budget(): void
    {
        $input = $this->png();
        $configuration = new ParserConfiguration(
            sharedSecret: str_repeat('s', 32),
            maxInputBytes: strlen($input),
            maxOutputBytes: 1024 * 1024,
            maxPixels: 100,
            maxDimension: 100,
            maxClockSkewSeconds: 30,
        );

        $this->expectException(ParserOutputTooLarge::class);

        (new RasterNormalizer($configuration))->normalize(new ParserRequest(
            nonce: str_repeat('a', 32),
            mediaType: 'image/png',
            bytes: $input,
            maxOutputBytes: 1,
        ));
    }

    public function test_parser_rejects_png_data_that_inflates_beyond_the_declared_scanlines(): void
    {
        $compressed = gzcompress(
            "\x00\x23\x78\xdd\xff" . str_repeat("\x00", 8 * 1024 * 1024),
            9,
        );
        $this->assertIsString($compressed);
        $input = "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0))
            . $this->pngChunk('IDAT', $compressed)
            . $this->pngChunk('IEND', '');
        $configuration = new ParserConfiguration(
            sharedSecret: str_repeat('s', 32),
            maxInputBytes: strlen($input),
            maxOutputBytes: 1024 * 1024,
            maxPixels: 100,
            maxDimension: 100,
            maxClockSkewSeconds: 30,
        );

        $this->expectException(ParserRejection::class);

        (new RasterNormalizer($configuration))->normalize(new ParserRequest(
            nonce: str_repeat('a', 32),
            mediaType: 'image/png',
            bytes: $input,
            maxOutputBytes: 1024 * 1024,
        ));
    }

    public function test_parser_strips_compressed_png_metadata_before_native_decode(): void
    {
        $compressedMetadata = gzcompress(str_repeat('A', 7_900_000), 9);
        $compressedPixels = gzcompress("\x00\x23\x78\xdd\xff");
        $this->assertIsString($compressedMetadata);
        $this->assertIsString($compressedPixels);
        $input = "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0));

        for ($index = 0; $index < 150; $index++) {
            $input .= $this->pngChunk('zTXt', 'key-' . $index . "\x00\x00" . $compressedMetadata);
        }

        $input .= $this->pngChunk('iTXt', "localized\x00\x01\x00\x00\x00" . $compressedMetadata)
            . $this->pngChunk('iCCP', "profile\x00\x00" . $compressedMetadata)
            . $this->pngChunk('IDAT', $compressedPixels)
            . $this->pngChunk('IEND', '');
        $this->assertGreaterThan(1024 * 1024, strlen($input));

        $safeInput = PngDatastreamValidator::validatedForDecode(
            $input,
            new RasterInfo('image/png', 1, 1),
        );

        $this->assertSame(['IHDR', 'IDAT', 'IEND'], $this->pngChunkTypes($safeInput));
        $this->assertLessThan(1024, strlen($safeInput));
        $configuration = new ParserConfiguration(
            sharedSecret: str_repeat('s', 32),
            maxInputBytes: strlen($input),
            maxOutputBytes: 1024 * 1024,
            maxPixels: 100,
            maxDimension: 100,
            maxClockSkewSeconds: 30,
        );

        [$image] = (new RasterNormalizer($configuration))->normalize(new ParserRequest(
            nonce: str_repeat('a', 32),
            mediaType: 'image/png',
            bytes: $input,
            maxOutputBytes: 1024 * 1024,
        ));

        $this->assertSame(1, $image->width);
        $this->assertSame(1, $image->height);
    }

    public function test_parser_accepts_a_seven_pass_adam7_png_with_consecutive_idat_chunks(): void
    {
        $scanlines = '';

        foreach ([[1, 1], [1, 1], [2, 1], [2, 2], [4, 2], [4, 4], [8, 4]] as [$passWidth, $passHeight]) {
            for ($row = 0; $row < $passHeight; $row++) {
                $scanlines .= "\x00" . str_repeat("\x23\x78\xdd\xff", $passWidth);
            }
        }

        $compressed = gzcompress($scanlines);
        $this->assertIsString($compressed);
        $split = intdiv(strlen($compressed), 2);
        $input = "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', pack('NNCCCCC', 8, 8, 8, 6, 0, 0, 1))
            . $this->pngChunk('IDAT', substr($compressed, 0, $split))
            . $this->pngChunk('IDAT', substr($compressed, $split))
            . $this->pngChunk('IEND', '');
        $configuration = new ParserConfiguration(
            sharedSecret: str_repeat('s', 32),
            maxInputBytes: strlen($input),
            maxOutputBytes: 1024 * 1024,
            maxPixels: 100,
            maxDimension: 100,
            maxClockSkewSeconds: 30,
        );

        [$image] = (new RasterNormalizer($configuration))->normalize(new ParserRequest(
            nonce: str_repeat('a', 32),
            mediaType: 'image/png',
            bytes: $input,
            maxOutputBytes: 1024 * 1024,
        ));

        $this->assertSame('image/png', $image->mediaType);
        $this->assertSame(8, $image->width);
        $this->assertSame(8, $image->height);
    }

    public function test_parser_accepts_supported_png_color_depth_scanline_shapes(): void
    {
        $cases = [
            'one-bit grayscale' => [
                'width' => 8,
                'height' => 1,
                'bit_depth' => 1,
                'color_type' => 0,
                'scanlines' => "\x00\xaa",
                'palette' => null,
            ],
            'two-bit indexed color' => [
                'width' => 4,
                'height' => 1,
                'bit_depth' => 2,
                'color_type' => 3,
                'scanlines' => "\x00\x1b",
                'palette' => "\x00\x00\x00\xff\x00\x00\x00\xff\x00\x00\x00\xff",
            ],
            'sixteen-bit truecolor alpha' => [
                'width' => 1,
                'height' => 1,
                'bit_depth' => 16,
                'color_type' => 6,
                'scanlines' => "\x00" . str_repeat("\xff", 8),
                'palette' => null,
            ],
        ];

        foreach ($cases as $case => $values) {
            $compressed = gzcompress($values['scanlines']);
            $this->assertIsString($compressed, $case);
            $input = "\x89PNG\r\n\x1a\n"
                . $this->pngChunk('IHDR', pack(
                    'NNCCCCC',
                    $values['width'],
                    $values['height'],
                    $values['bit_depth'],
                    $values['color_type'],
                    0,
                    0,
                    0,
                ));

            if (is_string($values['palette'])) {
                $input .= $this->pngChunk('PLTE', $values['palette']);
            }

            $input .= $this->pngChunk('IDAT', $compressed)
                . $this->pngChunk('IEND', '');
            $configuration = new ParserConfiguration(
                sharedSecret: str_repeat('s', 32),
                maxInputBytes: strlen($input),
                maxOutputBytes: 1024 * 1024,
                maxPixels: 100,
                maxDimension: 100,
                maxClockSkewSeconds: 30,
            );

            [$image] = (new RasterNormalizer($configuration))->normalize(new ParserRequest(
                nonce: str_repeat('a', 32),
                mediaType: 'image/png',
                bytes: $input,
                maxOutputBytes: 1024 * 1024,
            ));

            $this->assertSame($values['width'], $image->width, $case);
            $this->assertSame($values['height'], $image->height, $case);
        }
    }

    public function test_parser_health_probe_exercises_decode_and_reencode(): void
    {
        $configuration = new ParserConfiguration(
            sharedSecret: str_repeat('s', 32),
            maxInputBytes: 1024,
            maxOutputBytes: 1024 * 1024,
            maxPixels: 100,
            maxDimension: 100,
            maxClockSkewSeconds: 30,
        );

        (new RasterNormalizer($configuration))->verifyHealth();

        $this->addToAssertionCount(1);
    }

    public function test_parser_admission_limits_are_fixed_protocol_ceilings_not_environment_copies(): void
    {
        $previousSecret = getenv('IMAGE_PARSER_SHARED_SECRET');
        $previousMaxBytes = getenv('PAGE_IMAGE_MAX_BYTES');

        try {
            putenv('IMAGE_PARSER_SHARED_SECRET=' . str_repeat('s', 32));
            putenv('PAGE_IMAGE_MAX_BYTES=999999999999999999999999999999');

            $configuration = ParserConfiguration::fromEnvironment();

            $this->assertSame(5 * 1024 * 1024, $configuration->maxInputBytes);
            $this->assertSame(16 * 1024 * 1024, $configuration->maxPixels);
            $this->assertSame(16384, $configuration->maxDimension);
        } finally {
            $this->restoreEnvironment('IMAGE_PARSER_SHARED_SECRET', $previousSecret);
            $this->restoreEnvironment('PAGE_IMAGE_MAX_BYTES', $previousMaxBytes);
        }
    }

    public function test_parser_clock_skew_default_tolerates_split_hosts_but_remains_bounded(): void
    {
        $previousSecret = getenv('IMAGE_PARSER_SHARED_SECRET');
        $previousClockSkew = getenv('IMAGE_PARSER_MAX_CLOCK_SKEW_SECONDS');

        try {
            putenv('IMAGE_PARSER_SHARED_SECRET=' . str_repeat('s', 32));
            putenv('IMAGE_PARSER_MAX_CLOCK_SKEW_SECONDS');
            $this->assertSame(120, ParserConfiguration::fromEnvironment()->maxClockSkewSeconds);

            putenv('IMAGE_PARSER_MAX_CLOCK_SKEW_SECONDS=999');
            $this->assertSame(300, ParserConfiguration::fromEnvironment()->maxClockSkewSeconds);
        } finally {
            $this->restoreEnvironment('IMAGE_PARSER_SHARED_SECRET', $previousSecret);
            $this->restoreEnvironment('IMAGE_PARSER_MAX_CLOCK_SKEW_SECONDS', $previousClockSkew);
        }
    }

    private function png(): string
    {
        $compressed = gzcompress("\x00\x23\x78\xdd\xff");
        $this->assertIsString($compressed);

        return "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0))
            . $this->pngChunk('IDAT', $compressed)
            . $this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            . $type
            . $data
            . pack('N', crc32($type . $data));
    }

    /**
     * @return list<string>
     */
    private function pngChunkTypes(string $bytes): array
    {
        $types = [];
        $offset = 8;

        while (strlen($bytes) - $offset >= 12) {
            $length = unpack('Nlength', substr($bytes, $offset, 4));
            $this->assertIsArray($length);
            $chunkLength = $length['length'] ?? null;
            $this->assertIsInt($chunkLength);
            $type = substr($bytes, $offset + 4, 4);
            $types[] = $type;
            $offset += 12 + $chunkLength;

            if ($type === 'IEND') {
                break;
            }
        }

        return $types;
    }

    private function restoreEnvironment(string $key, string|false $value): void
    {
        putenv($value === false ? $key : $key . '=' . $value);
    }

    private function parserRequest(
        string $bytes,
        string $timestamp,
        string $nonce,
        string $maxInputBytes,
        string $maxOutputBytes,
        string $maxPixels,
        string $maxDimension,
        string $signature,
    ): string {
        $process = new Process(
            [dirname(PHP_BINARY) . '/php-cgi', '-d', 'display_errors=0'],
            base_path(),
            [
                'REDIRECT_STATUS' => '1',
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/v1/normalize',
                'SCRIPT_FILENAME' => base_path('image-parser/public/index.php'),
                'CONTENT_TYPE' => 'image/png',
                'CONTENT_LENGTH' => (string) strlen($bytes),
                'HTTP_X_ARTIFACTFLOW_PARSER_TIMESTAMP' => $timestamp,
                'HTTP_X_ARTIFACTFLOW_PARSER_NONCE' => $nonce,
                'HTTP_X_ARTIFACTFLOW_PARSER_MAX_INPUT_BYTES' => $maxInputBytes,
                'HTTP_X_ARTIFACTFLOW_PARSER_MAX_OUTPUT_BYTES' => $maxOutputBytes,
                'HTTP_X_ARTIFACTFLOW_PARSER_MAX_PIXELS' => $maxPixels,
                'HTTP_X_ARTIFACTFLOW_PARSER_MAX_DIMENSION' => $maxDimension,
                'HTTP_X_ARTIFACTFLOW_PARSER_SIGNATURE' => $signature,
                'IMAGE_PARSER_SHARED_SECRET' => self::SHARED_SECRET,
            ],
        );
        $process->setInput($bytes);
        $process->setTimeout(10);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return $process->getOutput();
    }

    private function parserStatus(string $response): int
    {
        $matches = [];

        return preg_match('/\AStatus: (?<status>\d{3}) /', $response, $matches) === 1
            ? (int) $matches['status']
            : 200;
    }
}
