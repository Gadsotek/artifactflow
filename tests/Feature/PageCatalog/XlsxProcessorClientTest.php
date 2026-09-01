<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\PageCatalog\XlsxProcessingAdmission;
use App\Application\PageCatalog\XlsxProcessorClient;
use App\Application\PageCatalog\XlsxProcessorProtocol;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\XlsxProcessingBusy;
use App\Domain\PageCatalog\XlsxProcessingUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\RecordingLogger;
use Tests\TestCase;

final class XlsxProcessorClientTest extends TestCase
{
    private const string SHARED_SECRET = 'test-xlsx-processor-shared-secret-0001';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'xlsx_processor.enabled' => true,
            'xlsx_processor.url' => 'http://xlsx-processor.test',
            'xlsx_processor.socket_path' => null,
            'xlsx_processor.shared_secret' => self::SHARED_SECRET,
            'xlsx_processor.connect_timeout_seconds' => 2,
            'xlsx_processor.timeout_seconds' => 15,
        ]);
        Cache::flush();
        Cache::lock(XlsxProcessingAdmission::SLOT_KEY)->forceRelease();
    }

    public function test_client_sends_exact_signed_xlsx_bytes_and_accepts_only_the_bound_response(): void
    {
        $xlsx = "PK\x03\x04bounded-xlsx";

        Http::fake(function (Request $request, array $options) use ($xlsx): \GuzzleHttp\Promise\PromiseInterface {
            $this->assertSame('http://xlsx-processor.test/v1/xlsx/manifests', $request->url());
            $this->assertSame(XlsxProcessorProtocol::INPUT_MEDIA_TYPE, $request->header('Content-Type')[0] ?? null);
            $this->assertSame($xlsx, $request->body());
            $this->assertTrue($options['stream'] ?? false);
            $timestamp = $request->header('X-ArtifactFlow-Processor-Timestamp')[0] ?? '';
            $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $signature = $request->header('X-ArtifactFlow-Processor-Signature')[0] ?? '';
            $this->assertIsString($timestamp);
            $this->assertIsString($nonce);
            $this->assertIsString($signature);
            $this->assertSame('xlsx-typed-view-v1', $request->header('X-ArtifactFlow-Processor-Profile')[0] ?? null);
            $this->assertSame(hash('sha256', $xlsx), $request->header('X-ArtifactFlow-Input-SHA256')[0] ?? null);
            $this->assertSame(
                XlsxProcessorProtocol::requestSignature($timestamp, $nonce, $xlsx, self::SHARED_SECRET),
                $signature,
            );
            $body = $this->validResponse($xlsx);

            return Http::response($body, 200, $this->responseHeaders($nonce, $xlsx, $body));
        });

        $result = app(XlsxProcessorClient::class)->project($xlsx);

        $this->assertSame(1, $result->visibleSheetCount);
        $this->assertSame('Visible', data_get(json_decode(
            $result->manifestJson,
            true,
            flags: JSON_THROW_ON_ERROR,
        ), 'sheets.0.name'));
        Http::assertSentCount(1);
    }

    public function test_client_rejects_a_signed_response_with_inconsistent_headers(): void
    {
        $xlsx = "PK\x03\x04bounded-xlsx";

        Http::fake(function (Request $request) use ($xlsx): \GuzzleHttp\Promise\PromiseInterface {
            $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $this->assertIsString($nonce);
            $body = $this->validResponse($xlsx);
            $headers = $this->responseHeaders($nonce, $xlsx, $body);
            $headers['X-ArtifactFlow-Processor-Engine-Version'] = '0.20.4';

            return Http::response($body, 200, $headers);
        });

        $this->expectException(XlsxProcessingUnavailable::class);

        app(XlsxProcessorClient::class)->project($xlsx);
    }

    public function test_document_rejection_is_distinct_from_retryable_service_failure(): void
    {
        $requests = 0;
        $acceptedXlsx = "PK\x03\x04accepted-after-rejection";
        Http::fake(function (Request $request) use (&$requests, $acceptedXlsx): \GuzzleHttp\Promise\PromiseInterface {
            ++$requests;
            if ($requests === 1) {
                return Http::response(['error' => 'xlsx_rejected'], 422);
            }

            $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $this->assertIsString($nonce);
            $body = $this->validResponse($acceptedXlsx);

            return Http::response($body, 200, $this->responseHeaders($nonce, $acceptedXlsx, $body));
        });

        try {
            app(XlsxProcessorClient::class)->project("PK\x03\x04rejected");
            $this->fail('A rejected workbook must not be treated as a transient outage.');
        } catch (DomainRuleViolation $exception) {
            $this->assertNotInstanceOf(XlsxProcessingUnavailable::class, $exception);
            $this->assertSame('XLSX could not be validated or processed.', $exception->getMessage());
        }

        $result = app(XlsxProcessorClient::class)->project($acceptedXlsx);
        $this->assertSame(1, $result->visibleSheetCount);
        $this->assertSame(2, $requests, 'A document rejection must release the global XLSX processing lease.');
    }

    public function test_transport_failure_logs_no_workbook_content(): void
    {
        $logger = new RecordingLogger();
        Log::swap($logger);
        Http::fake([
            '*' => Http::response(['error' => 'service_unavailable'], 503),
        ]);

        try {
            app(XlsxProcessorClient::class)->project("PK\x03\x04private-workbook-needle");
            $this->fail('A processor outage must fail closed.');
        } catch (XlsxProcessingUnavailable $exception) {
            $this->assertSame(
                'XLSX processing service is unavailable. Try again shortly.',
                $exception->getMessage(),
            );
        }

        $serialized = json_encode($logger->records, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('xlsx_processor.request_failed', $serialized);
        $this->assertStringNotContainsString('private-workbook-needle', $serialized);
    }

    public function test_global_admission_slot_rejects_concurrent_processing_immediately(): void
    {
        $lock = Cache::lock(XlsxProcessingAdmission::SLOT_KEY, 30);
        $this->assertTrue($lock->get());
        Http::fake();

        try {
            $this->expectException(XlsxProcessingBusy::class);

            app(XlsxProcessorClient::class)->project("PK\x03\x04bounded-xlsx");
        } finally {
            $lock->release();
        }
    }

    public function test_client_rejects_disabled_empty_and_invalidly_configured_processing(): void
    {
        config(['xlsx_processor.enabled' => false]);
        try {
            app(XlsxProcessorClient::class)->project("PK\x03\x04disabled");
            $this->fail('Disabled XLSX processing must fail closed.');
        } catch (DomainRuleViolation $exception) {
            $this->assertNotInstanceOf(XlsxProcessingUnavailable::class, $exception);
        }

        config(['xlsx_processor.enabled' => true]);
        try {
            app(XlsxProcessorClient::class)->project('');
            $this->fail('Empty XLSX input must fail closed.');
        } catch (DomainRuleViolation $exception) {
            $this->assertNotInstanceOf(XlsxProcessingUnavailable::class, $exception);
        }

        config(['xlsx_processor.url' => 'http://xlsx-processor.test/path']);
        Http::fake();
        $this->assertProcessorUnavailable(
            static fn () => app(XlsxProcessorClient::class)->project("PK\x03\x04invalid-configuration"),
        );
        Http::assertNothingSent();
    }

    public function test_uncertain_connection_and_transport_failures_retain_the_global_slot(): void
    {
        $dispatches = 0;
        Http::fake(static function () use (&$dispatches): never {
            $dispatches++;

            throw new ConnectionException('private connection detail');
        });
        $this->assertProcessorUnavailable(
            static fn () => app(XlsxProcessorClient::class)->project("PK\x03\x04connection-failure"),
        );

        try {
            app(XlsxProcessorClient::class)->project("PK\x03\x04second-dispatch");
            $this->fail('An uncertain dispatch must retain the global processing slot.');
        } catch (XlsxProcessingBusy) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, $dispatches);

        Cache::lock(XlsxProcessingAdmission::SLOT_KEY)->forceRelease();
        Http::fake(static function (): never {
            throw new \RuntimeException('private transport detail');
        });
        $this->assertProcessorUnavailable(
            static fn () => app(XlsxProcessorClient::class)->project("PK\x03\x04transport-failure"),
        );
        Cache::lock(XlsxProcessingAdmission::SLOT_KEY)->forceRelease();
    }

    public function test_client_maps_encoded_and_unexpected_responses_to_retryable_failure(): void
    {
        Http::fake([
            '*' => Http::response('encoded', 200, ['Content-Encoding' => 'gzip']),
        ]);
        $this->assertProcessorUnavailable(
            static fn () => app(XlsxProcessorClient::class)->project("PK\x03\x04encoded-response"),
        );

        Cache::lock(XlsxProcessingAdmission::SLOT_KEY)->forceRelease();
        foreach ([
            [401, '{"error":"authentication_failed"}'],
            [418, 'not-json'],
            [422, 'null'],
        ] as [$status, $body]) {
            Cache::lock(XlsxProcessingAdmission::SLOT_KEY)->forceRelease();
            Http::fake(['*' => Http::response($body, $status)]);
            $this->assertProcessorUnavailable(
                static fn () => app(XlsxProcessorClient::class)->project("PK\x03\x04status-response"),
            );
        }
    }

    /** @param \Closure(): mixed $operation */
    private function assertProcessorUnavailable(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('The XLSX processor failure must remain retryable.');
        } catch (XlsxProcessingUnavailable $exception) {
            $this->assertSame(
                'XLSX processing service is unavailable. Try again shortly.',
                $exception->getMessage(),
            );
        }
    }

    private function validResponse(string $xlsx): string
    {
        return json_encode([
            'schema' => 'xlsx-processor-response-v1',
            'profile' => 'xlsx-typed-view-v1',
            'engine' => ['name' => 'sheetjs-ce', 'version' => '0.20.3'],
            'input' => ['bytes' => strlen($xlsx), 'sha256' => hash('sha256', $xlsx)],
            'package' => ['entryCount' => 8, 'expandedBytes' => 1_024],
            'manifest' => [
                'schema' => 'xlsx-view-manifest-v1',
                'profile' => 'xlsx-typed-view-v1',
                'workbook' => [
                    'visibleSheetCount' => 1,
                    'omittedHiddenSheetCount' => 0,
                    'cellCount' => 1,
                    'formulaCount' => 0,
                    'formulasWithoutCachedResultCount' => 0,
                    'linkCount' => 0,
                    'mergeCount' => 0,
                    'truncated' => false,
                ],
                'sheets' => [[
                    'name' => 'Visible',
                    'rowExtent' => 1,
                    'columnExtent' => 1,
                    'omittedHiddenRowCount' => 0,
                    'omittedHiddenColumnCount' => 0,
                    'merges' => [],
                    'cells' => [[
                        'coordinate' => 'A1',
                        'kind' => 'string',
                        'display' => 'hello',
                        'value' => 'hello',
                    ]],
                ]],
                'searchText' => '[Visible] A1 hello',
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, string>
     */
    private function responseHeaders(string $nonce, string $xlsx, string $body): array
    {
        $inputSha256 = hash('sha256', $xlsx);

        return [
            'Cache-Control' => 'no-store',
            'Content-Type' => XlsxProcessorProtocol::MANIFEST_MEDIA_TYPE,
            'Content-Length' => (string) strlen($body),
            'X-ArtifactFlow-Processor-Nonce' => $nonce,
            'X-ArtifactFlow-Input-Bytes' => (string) strlen($xlsx),
            'X-ArtifactFlow-Input-SHA256' => $inputSha256,
            'X-ArtifactFlow-Response-SHA256' => hash('sha256', $body),
            'X-ArtifactFlow-Processor-Profile' => 'xlsx-typed-view-v1',
            'X-ArtifactFlow-Processor-Schema' => 'xlsx-processor-response-v1',
            'X-ArtifactFlow-Processor-Engine' => 'sheetjs-ce',
            'X-ArtifactFlow-Processor-Engine-Version' => '0.20.3',
            'X-ArtifactFlow-Processor-Signature' => XlsxProcessorProtocol::responseSignature(
                $nonce,
                strlen($xlsx),
                $inputSha256,
                $body,
                self::SHARED_SECRET,
            ),
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
