<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\ImageArtifactLimits;
use App\Application\PageCatalog\ImageParserConfiguration;
use App\Application\PageCatalog\RasterImageInspector;
use App\Application\PageCatalog\RasterImageNormalizer;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\ImageNormalizationRejected;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\User;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Support\RecordingLogger;
use Tests\TestCase;

final class ImageParserClientTest extends TestCase
{
    use RefreshDatabase;

    private const string SHARED_SECRET = 'test-image-parser-shared-secret-0001';

    public function test_normalization_uses_a_signed_parser_request_and_accepts_only_its_signed_derivative(): void
    {
        $original = $this->png() . 'GPS=50.087,14.421;<script>alert(1)</script>';
        $normalized = $this->png();

        $this->configureParser();
        Http::fake(function (Request $request) use ($normalized): \GuzzleHttp\Promise\PromiseInterface {
            $this->assertSame('http://image-parser.test/v1/normalize', $request->url());
            $this->assertSame('image/png', $request->header('Content-Type')[0] ?? null);

            $timestamp = $request->header('X-ArtifactFlow-Parser-Timestamp')[0] ?? '';
            $nonce = $request->header('X-ArtifactFlow-Parser-Nonce')[0] ?? '';
            $signature = $request->header('X-ArtifactFlow-Parser-Signature')[0] ?? '';
            $maxInputBytes = $request->header('X-ArtifactFlow-Parser-Max-Input-Bytes')[0] ?? '';
            $maxOutputBytes = $request->header('X-ArtifactFlow-Parser-Max-Output-Bytes')[0] ?? '';
            $maxPixels = $request->header('X-ArtifactFlow-Parser-Max-Pixels')[0] ?? '';
            $maxDimension = $request->header('X-ArtifactFlow-Parser-Max-Dimension')[0] ?? '';
            $this->assertIsString($timestamp);
            $this->assertIsString($nonce);
            $this->assertIsString($signature);
            $this->assertIsString($maxInputBytes);
            $this->assertIsString($maxOutputBytes);
            $this->assertIsString($maxPixels);
            $this->assertIsString($maxDimension);
            $this->assertMatchesRegularExpression('/^\d{10}$/', $timestamp);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $nonce);
            $this->assertSame('1048576', $maxInputBytes);
            $this->assertSame('10485760', $maxOutputBytes);
            $this->assertSame('100', $maxPixels);
            $this->assertSame('100', $maxDimension);
            $this->assertSame(
                $this->requestSignature(
                    $timestamp,
                    $nonce,
                    'image/png',
                    $maxInputBytes,
                    $maxOutputBytes,
                    $maxPixels,
                    $maxDimension,
                    $request->body(),
                ),
                $signature,
            );

            return Http::response($normalized, 200, [
                'Content-Type' => 'image/png',
                'X-ArtifactFlow-Parser-Media-Type' => 'image/png',
                'X-ArtifactFlow-Parser-Width' => '1',
                'X-ArtifactFlow-Parser-Height' => '1',
                'X-ArtifactFlow-Parser-Signature' => $this->responseSignature(
                    $nonce,
                    'image/png',
                    1,
                    1,
                    $normalized,
                ),
            ]);
        });

        $result = app(RasterImageNormalizer::class)->normalize($original, 'actor-signed-response');

        $this->assertSame($normalized, $result);
        $this->assertStringNotContainsString('GPS=', $result);
        $this->assertStringNotContainsString('<script>', $result);
        Http::assertSentCount(1);
    }

    public function test_normalization_fails_closed_when_the_parser_is_unavailable(): void
    {
        $this->configureParser();
        Http::fake(static function (): never {
            throw new ConnectionException('private transport detail');
        });

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Image normalization service is unavailable. Try again shortly.');

        app(RasterImageNormalizer::class)->normalize($this->png(), 'actor-parser-unavailable');
    }

    public function test_parser_redirects_are_rejected_without_forwarding_the_signed_request(): void
    {
        $this->configureParser();
        Http::fake(function (Request $request, array $options): \GuzzleHttp\Promise\PromiseInterface {
            $this->assertSame('http://image-parser.test/v1/normalize', $request->url());
            $this->assertFalse($options['allow_redirects'] ?? null);

            return Http::response(status: 302, headers: [
                'Location' => 'https://attacker.example/collect',
            ]);
        });

        try {
            app(RasterImageNormalizer::class)->normalize($this->png(), 'actor-parser-redirect');
            $this->fail('Expected the parser redirect to fail closed.');
        } catch (ImageNormalizationRejected $exception) {
            $this->assertSame(
                'Image normalization service is unavailable. Try again shortly.',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount(1);
    }

    public function test_completed_parser_http_failures_are_retryable_and_clock_skew_is_diagnosable(): void
    {
        $this->configureParser();
        $logger = new RecordingLogger();
        Log::swap($logger);
        $normalizer = app(RasterImageNormalizer::class);
        /** @var list<\Closure(): \GuzzleHttp\Promise\PromiseInterface> $responses */
        $responses = [
            static fn (): \GuzzleHttp\Promise\PromiseInterface => Http::response(
                ['error' => 'service_unavailable'],
                503,
            ),
            static fn (): \GuzzleHttp\Promise\PromiseInterface => Http::response(
                ['error' => 'clock_skew'],
                401,
            ),
        ];
        Http::fake(static function () use (&$responses): \GuzzleHttp\Promise\PromiseInterface {
            $response = array_shift($responses);

            if (!$response instanceof \Closure) {
                throw new LogicException('Unexpected parser request.');
            }

            return $response();
        });

        foreach (['parser failure', 'clock skew'] as $case) {
            try {
                $normalizer->normalize($this->png(), 'actor-transient-parser-failure');
                $this->fail(sprintf('Expected %s to be retryable.', $case));
            } catch (ImageNormalizationRejected $exception) {
                $this->assertSame('Image normalization service is unavailable. Try again shortly.', $exception->getMessage());
                $this->assertSame(5, $exception->retryAfterSeconds);
            }
        }

        $this->assertTrue(collect($logger->records)->contains(
            static fn (array $record): bool => $record['level'] === 'error'
                && $record['message'] === 'image_parser.request_failed'
                && ($record['context']['reason'] ?? null) === 'clock_skew',
        ));
    }

    public function test_uncertain_transport_failure_retains_the_normalization_slot_until_its_lease_expires(): void
    {
        $this->configureParser();
        $dispatches = 0;
        Http::fake(static function () use (&$dispatches): never {
            $dispatches++;

            throw new ConnectionException('private transport detail');
        });
        $normalizer = app(RasterImageNormalizer::class);

        try {
            $normalizer->normalize($this->png(), 'actor-parser-failure-a');
            $this->fail('Expected the uncertain parser failure to reject normalization.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame(
                'Image normalization service is unavailable. Try again shortly.',
                $exception->getMessage(),
            );
        }

        try {
            $normalizer->normalize($this->png(), 'actor-parser-failure-b');
            $this->fail('Expected the retained parser lease to reject another normalization.');
        } catch (ImageNormalizationRejected $exception) {
            $this->assertSame('Image normalization is busy. Try again shortly.', $exception->getMessage());
            $this->assertSame(8, $exception->retryAfterSeconds);
        }

        $this->assertSame(1, $dispatches);
    }

    public function test_dispatched_parser_failures_consume_actor_and_installation_pixel_budgets(): void
    {
        $this->configureParser();
        config([
            'pages.max_image_pixels' => 1,
            'image_parser.user_pixel_budget_per_minute' => 1,
            'image_parser.installation_pixel_budget_per_minute' => 1,
        ]);
        Http::fake([
            '*' => Http::response(
                ['error' => 'image_rejected'],
                422,
            ),
        ]);
        $normalizer = app(RasterImageNormalizer::class);

        try {
            $normalizer->normalize($this->png(), 'actor-charged-budget');
            $this->fail('Expected the parser to reject the malformed image.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Image could not be decoded.', $exception->getMessage());
        }

        try {
            $normalizer->normalize($this->png(), 'actor-charged-budget');
            $this->fail('Expected the dispatched parser work to consume the actor budget.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Image normalization limit reached. Try again shortly.', $exception->getMessage());
        }

        try {
            $normalizer->normalize($this->png(), 'actor-installation-charged-budget');
            $this->fail('Expected the dispatched parser work to consume the installation budget.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame(
                'Image normalization capacity is temporarily exhausted. Try again shortly.',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount(1);
    }

    public function test_pre_dispatch_client_failure_refunds_pixel_budgets(): void
    {
        $this->configureParser();
        config([
            'pages.max_image_pixels' => 1,
            'image_parser.user_pixel_budget_per_minute' => 1,
            'image_parser.installation_pixel_budget_per_minute' => 1,
            'image_parser.shared_secret' => 'short',
        ]);
        $this->fakeSuccessfulParser();
        $normalizer = app(RasterImageNormalizer::class);

        try {
            $normalizer->normalize($this->png(), 'actor-pre-dispatch-failure');
            $this->fail('Expected the invalid client configuration to reject normalization.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame(
                'Image normalization service is unavailable. Try again shortly.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
        config(['image_parser.shared_secret' => self::SHARED_SECRET]);

        $this->assertSame(
            $this->png(),
            $normalizer->normalize($this->png(), 'actor-pre-dispatch-failure'),
        );
    }

    public function test_non_positive_parser_timeouts_are_rejected_instead_of_silently_changed(): void
    {
        $configuration = app(ImageParserConfiguration::class);

        foreach ([
            ['image_parser.connect_timeout_seconds', 0, 'connect timeout'],
            ['image_parser.timeout_seconds', '0', 'request timeout'],
            ['image_parser.timeout_seconds', -1, 'negative request timeout'],
        ] as [$key, $value, $case]) {
            config([$key => $value]);

            try {
                str_contains($key, 'connect_')
                    ? $configuration->connectTimeoutSeconds()
                    : $configuration->timeoutSeconds();
                $this->fail(sprintf('Expected %s to be rejected.', $case));
            } catch (LogicException $exception) {
                $this->assertSame(
                    sprintf('Image parser setting [%s] must be a positive integer.', $key),
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_invalid_admission_configuration_is_mapped_to_retryable_parser_unavailability(): void
    {
        $this->configureParser();
        config(['image_parser.timeout_seconds' => 0]);
        Http::fake();

        try {
            app(RasterImageNormalizer::class)->normalize($this->png(), 'actor-invalid-admission-config');
            $this->fail('Expected invalid admission configuration to fail closed.');
        } catch (ImageNormalizationRejected $exception) {
            $this->assertSame(
                'Image normalization service is unavailable. Try again shortly.',
                $exception->getMessage(),
            );
            $this->assertSame(5, $exception->retryAfterSeconds);
        }

        Http::assertNothingSent();
    }

    public function test_normalization_rejects_unsigned_or_inconsistent_parser_output(): void
    {
        $this->configureParser();
        $normalized = $this->png();

        Http::fakeSequence()
            ->push($normalized, 200, [
                'Content-Type' => 'image/png',
                'X-ArtifactFlow-Parser-Media-Type' => 'image/png',
                'X-ArtifactFlow-Parser-Width' => '1',
                'X-ArtifactFlow-Parser-Height' => '1',
            ])
            ->push($normalized, 200, [
                'Content-Type' => 'image/jpeg',
                'X-ArtifactFlow-Parser-Media-Type' => 'image/jpeg',
                'X-ArtifactFlow-Parser-Width' => '1',
                'X-ArtifactFlow-Parser-Height' => '1',
                'X-ArtifactFlow-Parser-Signature' => str_repeat('0', 64),
            ]);

        foreach (['unsigned response', 'media mismatch'] as $case) {
            try {
                app(RasterImageNormalizer::class)->normalize($this->png(), 'actor-invalid-output');
                $this->fail(sprintf('Expected %s to be rejected.', $case));
            } catch (ImageNormalizationRejected $exception) {
                $this->assertSame(
                    'Image normalization service is unavailable. Try again shortly.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_parser_response_is_streamed_without_decompression_and_aborted_at_the_byte_limit(): void
    {
        $this->configureParser();
        config([
            'pages.artifact_max_bytes' => 1024,
            'pages.max_html_bytes' => 1024,
            'pages.max_markdown_bytes' => 1024,
        ]);
        $remaining = 2048;
        $produced = 0;
        $acceptEncoding = null;
        /** @var array<string, mixed> $requestOptions */
        $requestOptions = [];
        $body = new PumpStream(static function (int $length) use (&$remaining, &$produced): ?string {
            if ($remaining === 0) {
                return null;
            }

            $bytes = str_repeat('x', min($length, $remaining));
            $remaining -= strlen($bytes);
            $produced += strlen($bytes);

            return $bytes;
        });

        Http::fake(static function (Request $request, array $options) use (
            $body,
            &$acceptEncoding,
            &$requestOptions,
        ): \GuzzleHttp\Promise\PromiseInterface {
            $requestOptions = $options;
            $acceptEncoding = $request->header('Accept-Encoding')[0] ?? null;

            return Create::promiseFor(new PsrResponse(200, [
                'Content-Type' => 'image/png',
                'X-ArtifactFlow-Parser-Media-Type' => 'image/png',
                'X-ArtifactFlow-Parser-Width' => '1',
                'X-ArtifactFlow-Parser-Height' => '1',
                'X-ArtifactFlow-Parser-Signature' => str_repeat('0', 64),
            ], $body));
        });

        try {
            app(RasterImageNormalizer::class)->normalize($this->png(), 'actor-bounded-parser-response');
            $this->fail('Expected the oversized parser response to fail closed.');
        } catch (ImageNormalizationRejected $exception) {
            $this->assertSame(
                'Image normalization service is unavailable. Try again shortly.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue($requestOptions['stream'] ?? false);
        $this->assertFalse($requestOptions['decode_content'] ?? true);
        $this->assertSame('identity', $acceptEncoding);
        $this->assertLessThanOrEqual(1025, $produced);

        try {
            app(RasterImageNormalizer::class)->normalize($this->png(), 'actor-bounded-parser-response-b');
            $this->fail('Expected the uncertain response stream to retain the normalization slot.');
        } catch (ImageNormalizationRejected $exception) {
            $this->assertSame('Image normalization is busy. Try again shortly.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_parser_response_rejects_non_identity_content_encoding(): void
    {
        $this->configureParser();
        Http::fake([
            '*' => Http::response('compressed bytes are never decoded', 200, [
                'Content-Encoding' => 'gzip',
            ]),
        ]);

        try {
            app(RasterImageNormalizer::class)->normalize($this->png(), 'actor-encoded-parser-response');
            $this->fail('Expected the encoded parser response to fail closed.');
        } catch (ImageNormalizationRejected $exception) {
            $this->assertSame(
                'Image normalization service is unavailable. Try again shortly.',
                $exception->getMessage(),
            );
        }
    }

    public function test_parser_response_rejects_oversized_declared_length_before_reading_the_stream(): void
    {
        $this->configureParser();
        config([
            'pages.artifact_max_bytes' => 1024,
            'pages.max_html_bytes' => 1024,
            'pages.max_markdown_bytes' => 1024,
        ]);
        $produced = 0;
        $body = new PumpStream(static function (int $length) use (&$produced): string {
            $produced += $length;

            return str_repeat('x', $length);
        });
        Http::fake(static fn (): \GuzzleHttp\Promise\PromiseInterface => Create::promiseFor(
            new PsrResponse(200, ['Content-Length' => '1025'], $body),
        ));

        try {
            app(RasterImageNormalizer::class)->normalize($this->png(), 'actor-declared-parser-response');
            $this->fail('Expected the declared oversized parser response to fail closed.');
        } catch (ImageNormalizationRejected $exception) {
            $this->assertSame(
                'Image normalization service is unavailable. Try again shortly.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, $produced);
    }

    public function test_normalization_rejects_a_response_signed_for_a_different_request_nonce(): void
    {
        $this->configureParser();
        $normalized = $this->png();
        Http::fake(function (Request $request) use ($normalized): \GuzzleHttp\Promise\PromiseInterface {
            $requestNonce = $request->header('X-ArtifactFlow-Parser-Nonce')[0] ?? '';
            $this->assertIsString($requestNonce);
            $wrongNonce = ($requestNonce[0] === '0' ? '1' : '0') . substr($requestNonce, 1);

            return Http::response($normalized, 200, [
                'Content-Type' => 'image/png',
                'X-ArtifactFlow-Parser-Media-Type' => 'image/png',
                'X-ArtifactFlow-Parser-Width' => '1',
                'X-ArtifactFlow-Parser-Height' => '1',
                'X-ArtifactFlow-Parser-Signature' => $this->responseSignature(
                    $wrongNonce,
                    'image/png',
                    1,
                    1,
                    $normalized,
                ),
            ]);
        });

        $this->expectException(ImageNormalizationRejected::class);
        $this->expectExceptionMessage('Image normalization service is unavailable. Try again shortly.');

        app(RasterImageNormalizer::class)->normalize($this->png(), 'actor-wrong-response-nonce');
    }

    public function test_normalization_reports_when_the_safe_derivative_exceeds_the_artifact_limit(): void
    {
        $this->configureParser();
        Http::fake([
            '*' => Http::response(['error' => 'normalized_image_too_large'], 422),
        ]);

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Normalized image exceeds the configured artifact size limit.');

        app(RasterImageNormalizer::class)->normalize($this->png(), 'actor-output-too-large');
    }

    public function test_jpeg_marker_floods_are_rejected_before_parser_dispatch(): void
    {
        $this->configureParser();
        config(['pages.max_image_bytes' => 5 * 1024 * 1024]);
        Http::fake();
        $jpeg = $this->jpeg();
        $markerFloodBytes = (5 * 1024 * 1024) - strlen($jpeg);
        $payloads = [
            'restart marker before scan data' => substr($jpeg, 0, 2) . "\xff\xd0" . substr($jpeg, 2),
            'maximum-size standalone marker flood' => substr($jpeg, 0, 2)
                . str_repeat("\xff\x01", intdiv($markerFloodBytes, 2))
                . substr($jpeg, 2),
        ];

        foreach ($payloads as $case => $payload) {
            try {
                app(RasterImageNormalizer::class)->normalize($payload, 'actor-marker-flood');
                $this->fail(sprintf('Expected %s to be rejected.', $case));
            } catch (DomainRuleViolation $exception) {
                $this->assertSame('Image header is invalid.', $exception->getMessage(), $case);
            }
        }

        Http::assertNothingSent();
    }

    public function test_jpeg_start_of_frame_may_follow_a_large_but_bounded_metadata_envelope(): void
    {
        config(['pages.max_image_bytes' => 5 * 1024 * 1024]);
        $appSegment = "\xff\xe1" . pack('n', 65535) . str_repeat('m', 65533);
        $jpeg = $this->jpeg();
        $lateStartOfFrame = substr($jpeg, 0, 2)
            . str_repeat($appSegment, 17)
            . substr($jpeg, 2);

        $this->assertGreaterThan(1024 * 1024, strlen($lateStartOfFrame));
        $info = app(RasterImageInspector::class)->inspectUpload($lateStartOfFrame);

        $this->assertSame('image/jpeg', $info->mediaType);
        $this->assertSame(1, $info->width);
        $this->assertSame(1, $info->height);
    }

    public function test_png_ancillary_and_chunk_limits_reject_before_parser_dispatch(): void
    {
        $this->configureParser();
        config(['pages.max_image_bytes' => 2 * 1024 * 1024]);
        Http::fake();
        $payloads = [
            'ancillary bytes' => [
                $this->pngWithTextMetadata((1024 * 1024) + 1, 0),
                'PNG metadata exceeds the supported work limit.',
            ],
            'chunk fanout' => [
                $this->pngWithTextMetadata(0, 1021),
                'PNG structure exceeds the supported work limit.',
            ],
        ];

        foreach ($payloads as $case => [$payload, $message]) {
            try {
                app(RasterImageNormalizer::class)->normalize($payload, 'actor-png-work-limit');
                $this->fail(sprintf('Expected %s to be rejected.', $case));
            } catch (DomainRuleViolation $exception) {
                $this->assertSame($message, $exception->getMessage(), $case);
            }
        }

        Http::assertNothingSent();
    }

    public function test_upload_pixel_limit_can_be_16_megapixels_while_retained_images_keep_the_40_megapixel_envelope(): void
    {
        config(['pages.max_image_pixels' => 16 * 1024 * 1024]);

        $this->assertSame(16 * 1024 * 1024, config('pages.max_image_pixels'));
        $this->assertSame(40 * 1024 * 1024, ImageArtifactLimits::STORED_MAX_PIXELS);

        $uploadEnvelope = $this->pngWithDeclaredDimensions(8192, 2048);
        $storedEnvelope = $this->pngWithDeclaredDimensions(16384, 2560);
        $inspector = app(RasterImageInspector::class);

        $this->assertSame(16 * 1024 * 1024, $inspector->inspectUpload($uploadEnvelope)->pixels());
        $this->assertSame(40 * 1024 * 1024, $inspector->inspectStored($storedEnvelope)->pixels());

        try {
            $inspector->inspectUpload($storedEnvelope);
            $this->fail('Expected a retained 40-megapixel image to be rejected as a new upload.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Image pixel count exceeds the configured limit.', $exception->getMessage());
        }
    }

    public function test_normalization_fails_fast_without_parser_dispatch_when_the_shared_slot_is_occupied(): void
    {
        $this->configureParser();
        config([
            'pages.max_image_pixels' => 1,
            'image_parser.user_pixel_budget_per_minute' => 1,
            'image_parser.installation_pixel_budget_per_minute' => 1,
        ]);
        $this->fakeSuccessfulParser();
        $lock = Cache::lock('artifactflow:image-normalization:slot:v1', 30);
        $this->assertTrue($lock->get());

        try {
            app(RasterImageNormalizer::class)->normalize($this->png(), 'actor-busy-slot');
            $this->fail('Expected the occupied parser slot to reject immediately.');
        } catch (ImageNormalizationRejected $exception) {
            $this->assertSame('Image normalization is busy. Try again shortly.', $exception->getMessage());
            $this->assertSame(8, $exception->retryAfterSeconds);
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();

        app(RasterImageNormalizer::class)->normalize($this->png(), 'actor-busy-slot');
        Http::assertSentCount(1);
    }

    public function test_pixel_work_budget_is_weighted_per_actor_without_blocking_another_actor(): void
    {
        $this->configureParser();
        config([
            'pages.max_image_pixels' => 4,
            'image_parser.user_pixel_budget_per_minute' => 4,
            'image_parser.installation_pixel_budget_per_minute' => 100,
        ]);
        $this->fakeSuccessfulParser();
        $normalizer = app(RasterImageNormalizer::class);

        $normalizer->normalize($this->png(2, 2), 'actor-weighted-a');

        try {
            $normalizer->normalize($this->png(), 'actor-weighted-a');
            $this->fail('Expected the actor pixel budget to reject the next normalization.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Image normalization limit reached. Try again shortly.', $exception->getMessage());
        }

        $normalizer->normalize($this->png(), 'actor-weighted-b');

        Http::assertSentCount(2);
    }

    public function test_installation_pixel_work_budget_is_shared_across_actors(): void
    {
        $this->configureParser();
        config([
            'pages.max_image_pixels' => 4,
            'image_parser.user_pixel_budget_per_minute' => 4,
            'image_parser.installation_pixel_budget_per_minute' => 4,
        ]);
        $this->fakeSuccessfulParser();
        $normalizer = app(RasterImageNormalizer::class);

        $normalizer->normalize($this->png(2, 2), 'actor-global-a');

        try {
            $normalizer->normalize($this->png(), 'actor-global-b');
            $this->fail('Expected the installation pixel budget to reject the next normalization.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame(
                'Image normalization capacity is temporarily exhausted. Try again shortly.',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount(1);
    }

    public function test_input_work_budget_charges_png_metadata_and_chunk_processing_independently_of_pixels(): void
    {
        $this->configureParser();
        $metadataBytes = 768 * 1024;
        $extraChunks = 512;
        $input = $this->pngWithTextMetadata($metadataBytes, $extraChunks);
        $workUnits = strlen($input) + $metadataBytes + ((4 + $extraChunks) * 1024);
        config([
            'pages.max_image_bytes' => 1024 * 1024,
            'pages.max_image_pixels' => 100,
            'image_parser.user_pixel_budget_per_minute' => 100,
            'image_parser.installation_pixel_budget_per_minute' => 1_000,
            'image_parser.user_work_budget_per_minute' => 3 * 1024 * 1024,
            'image_parser.installation_work_budget_per_minute' => 9 * 1024 * 1024,
        ]);
        $this->assertGreaterThan((3 * 1024 * 1024) / 2, $workUnits);
        $this->fakeSuccessfulParser();
        $normalizer = app(RasterImageNormalizer::class);

        $normalizer->normalize($input, 'actor-input-work-a');

        try {
            $normalizer->normalize($input, 'actor-input-work-a');
            $this->fail('Expected metadata work to exhaust the actor input-work budget.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Image normalization limit reached. Try again shortly.', $exception->getMessage());
        }

        $normalizer->normalize($input, 'actor-input-work-b');

        Http::assertSentCount(2);
    }

    public function test_image_replacement_completes_parser_io_before_opening_the_database_transaction(): void
    {
        Storage::fake('artifacts');
        $this->configureParser();
        $normalized = $this->png();
        $transactionLevels = [];
        $baselineTransactionLevel = DB::transactionLevel();

        Http::fake(function (Request $request) use ($normalized, &$transactionLevels): \GuzzleHttp\Promise\PromiseInterface {
            $transactionLevels[] = DB::transactionLevel();
            $nonce = $request->header('X-ArtifactFlow-Parser-Nonce')[0] ?? '';
            $this->assertIsString($nonce);

            return Http::response($normalized, 200, [
                'Content-Type' => 'image/png',
                'X-ArtifactFlow-Parser-Media-Type' => 'image/png',
                'X-ArtifactFlow-Parser-Width' => '1',
                'X-ArtifactFlow-Parser-Height' => '1',
                'X-ArtifactFlow-Parser-Signature' => $this->responseSignature(
                    $nonce,
                    'image/png',
                    1,
                    1,
                    $normalized,
                ),
            ]);
        });

        $actor = User::query()->create([
            'name' => 'Parser Transaction Editor',
            'email' => 'parser-transaction@example.test',
            'password' => Hash::make('password'),
        ]);
        $workspace = app(CreateSharedWorkspace::class)->handle($actor, 'Parser Transaction Team');
        $page = app(CreatePage::class)->handle($actor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Image,
            title: 'Parser Transaction Screenshot',
            description: null,
            content: $this->png(),
            source: PageVersionSource::Upload,
        ));

        app(UpdatePageContent::class)->handle($actor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: $this->png(),
            baseVersionUid: $page->current_version_uid,
            source: PageVersionSource::Upload,
        ));

        $this->assertSame(
            [$baselineTransactionLevel, $baselineTransactionLevel],
            $transactionLevels,
        );
    }

    private function configureParser(): void
    {
        config([
            'image_parser.url' => 'http://image-parser.test',
            'image_parser.shared_secret' => self::SHARED_SECRET,
            'image_parser.connect_timeout_seconds' => 1,
            'image_parser.timeout_seconds' => 3,
            'image_parser.user_pixel_budget_per_minute' => 64 * 1024 * 1024,
            'image_parser.installation_pixel_budget_per_minute' => 256 * 1024 * 1024,
            'image_parser.user_work_budget_per_minute' => 64 * 1024 * 1024,
            'image_parser.installation_work_budget_per_minute' => 256 * 1024 * 1024,
            'pages.max_image_bytes' => 1024 * 1024,
            'pages.max_image_pixels' => 100,
            'pages.max_image_dimension' => 100,
        ]);
    }

    private function fakeSuccessfulParser(): void
    {
        $normalized = $this->png();

        Http::fake(function (Request $request) use ($normalized): \GuzzleHttp\Promise\PromiseInterface {
            $nonce = $request->header('X-ArtifactFlow-Parser-Nonce')[0] ?? '';
            $this->assertIsString($nonce);

            return Http::response($normalized, 200, [
                'Content-Type' => 'image/png',
                'X-ArtifactFlow-Parser-Media-Type' => 'image/png',
                'X-ArtifactFlow-Parser-Width' => '1',
                'X-ArtifactFlow-Parser-Height' => '1',
                'X-ArtifactFlow-Parser-Signature' => $this->responseSignature(
                    $nonce,
                    'image/png',
                    1,
                    1,
                    $normalized,
                ),
            ]);
        });
    }

    private function requestSignature(
        string $timestamp,
        string $nonce,
        string $mediaType,
        string $maxInputBytes,
        string $maxOutputBytes,
        string $maxPixels,
        string $maxDimension,
        string $bytes,
    ): string {
        return hash_hmac('sha256', implode("\n", [
            'artifactflow-image-parser-request-v3',
            $timestamp,
            $nonce,
            $mediaType,
            $maxInputBytes,
            $maxOutputBytes,
            $maxPixels,
            $maxDimension,
            hash('sha256', $bytes),
        ]), self::SHARED_SECRET);
    }

    private function responseSignature(
        string $nonce,
        string $mediaType,
        int $width,
        int $height,
        string $bytes,
    ): string {
        return hash_hmac('sha256', implode("\n", [
            'artifactflow-image-parser-response-v1',
            $nonce,
            $mediaType,
            (string) $width,
            (string) $height,
            hash('sha256', $bytes),
        ]), self::SHARED_SECRET);
    }

    private function png(int $width = 1, int $height = 1): string
    {
        $scanlines = '';

        for ($row = 0; $row < $height; $row++) {
            $scanlines .= "\x00" . str_repeat("\x23\x78\xdd\xff", $width);
        }

        $compressed = gzcompress($scanlines);
        $this->assertIsString($compressed);

        return "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            . $this->pngChunk('IDAT', $compressed)
            . $this->pngChunk('IEND', '');
    }

    private function pngWithDeclaredDimensions(int $width, int $height): string
    {
        $compressed = gzcompress("\x00\x23\x78\xdd\xff");
        $this->assertIsString($compressed);

        return "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            . $this->pngChunk('IDAT', $compressed)
            . $this->pngChunk('IEND', '');
    }

    private function pngWithTextMetadata(int $metadataBytes, int $extraChunks): string
    {
        $png = $this->png();
        $metadata = $this->pngChunk('tEXt', str_repeat('m', $metadataBytes));

        for ($chunk = 0; $chunk < $extraChunks; $chunk++) {
            $metadata .= $this->pngChunk('tEXt', '');
        }

        return substr($png, 0, 33)
            . $metadata
            . substr($png, 33);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(1, 1);
        $this->assertInstanceOf(\GdImage::class, $image);
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            . $type
            . $data
            . pack('N', crc32($type . $data));
    }
}
