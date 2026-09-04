<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\PageCatalog\DocxProcessingAdmission;
use App\Application\PageCatalog\DocxProcessorClient;
use App\Application\PageCatalog\DocxProcessorProtocol;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\DocxProcessingBusy;
use App\Domain\PageCatalog\DocxProcessingUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\RecordingLogger;
use Tests\TestCase;

final class DocxProcessorClientTest extends TestCase
{
    private const string SECRET = 'test-docx-processor-shared-secret-00001';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'docx_processor.enabled' => true,
            'docx_processor.url' => 'http://docx-processor.test',
            'docx_processor.socket_path' => null,
            'docx_processor.shared_secret' => self::SECRET,
            'docx_processor.connect_timeout_seconds' => 2,
            'docx_processor.timeout_seconds' => 35,
        ]);
        Cache::flush();
        Cache::lock(DocxProcessingAdmission::SLOT_KEY)->forceRelease();
    }

    public function test_client_binds_exact_docx_input_and_every_pdf_response_fact(): void
    {
        $docx = "PK\x03\x04bounded-docx";
        $pdf = "%PDF-1.7\npreview\n%%EOF\n";

        Http::fake(function (Request $request, array $options) use ($docx, $pdf): \GuzzleHttp\Promise\PromiseInterface {
            $this->assertSame('http://docx-processor.test/v1/docx/previews', $request->url());
            $this->assertSame(DocxProcessorProtocol::INPUT_MEDIA_TYPE, $request->header('Content-Type')[0] ?? null);
            $this->assertSame($docx, $request->body());
            $this->assertTrue($options['stream'] ?? false);
            $timestamp = $request->header('X-ArtifactFlow-Processor-Timestamp')[0] ?? '';
            $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $signature = $request->header('X-ArtifactFlow-Processor-Signature')[0] ?? '';
            $this->assertIsString($timestamp);
            $this->assertIsString($nonce);
            $this->assertIsString($signature);
            $this->assertSame(
                DocxProcessorProtocol::requestSignature($timestamp, $nonce, $docx, self::SECRET),
                $signature,
            );

            return Http::response($pdf, 200, $this->headers($nonce, $docx, $pdf));
        });

        $result = app(DocxProcessorClient::class)->convert($docx);

        $this->assertSame($pdf, $result->pdfBytes);
        $this->assertSame(7, $result->packageEntryCount);
        $this->assertSame(2, $result->relationshipCount);
        $this->assertSame(1, $result->externalHyperlinkCount);
    }

    public function test_client_rejects_a_signed_body_when_a_fact_header_drifts(): void
    {
        $docx = "PK\x03\x04bounded-docx";
        $pdf = "%PDF-1.7\npreview\n%%EOF\n";
        Http::fake(function (Request $request) use ($docx, $pdf): \GuzzleHttp\Promise\PromiseInterface {
            $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $this->assertIsString($nonce);
            $headers = $this->headers($nonce, $docx, $pdf);
            $headers['X-ArtifactFlow-Package-Entry-Count'] = '8';

            return Http::response($pdf, 200, $headers);
        });

        $this->expectException(DocxProcessingUnavailable::class);

        app(DocxProcessorClient::class)->convert($docx);
    }

    public function test_document_rejection_is_distinct_from_retryable_service_failure(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'docx_rejected'], 422),
        ]);

        try {
            app(DocxProcessorClient::class)->convert("PK\x03\x04rejected");
            $this->fail('A rejected Word document must not be treated as a transient outage.');
        } catch (DomainRuleViolation $exception) {
            $this->assertNotInstanceOf(DocxProcessingUnavailable::class, $exception);
            $this->assertSame('DOCX could not be validated or converted.', $exception->getMessage());
        }
    }

    public function test_embedded_file_rejection_is_actionable_without_exposing_processor_details(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => 'docx_rejected',
                'reason' => 'embedded_file',
            ], 422),
        ]);

        try {
            app(DocxProcessorClient::class)->convert("PK\x03\x04embedded-file");
            $this->fail('An embedded Word object must be rejected with an actionable reason.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame(
                'This Word document contains an embedded file or OLE object, which is not supported.',
                $exception->getMessage(),
            );
        }
    }

    public function test_unknown_rejection_reason_is_not_exposed_to_the_user(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => 'docx_rejected',
                'reason' => 'private-document-detail',
            ], 422),
        ]);

        try {
            app(DocxProcessorClient::class)->convert("PK\x03\x04unknown-reason");
            $this->fail('An unknown processor reason must still reject the document.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('DOCX could not be validated or converted.', $exception->getMessage());
        }
    }

    public function test_transport_failure_logs_no_document_content(): void
    {
        $logger = new RecordingLogger();
        Log::swap($logger);
        Http::fake([
            '*' => Http::response(['error' => 'service_unavailable'], 503),
        ]);

        try {
            app(DocxProcessorClient::class)->convert("PK\x03\x04private-document-needle");
            $this->fail('A processor outage must fail closed.');
        } catch (DocxProcessingUnavailable $exception) {
            $this->assertSame(
                'Word document processing service is unavailable. Try again shortly.',
                $exception->getMessage(),
            );
        }

        $serialized = json_encode($logger->records, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('docx_processor.request_failed', $serialized);
        $this->assertStringNotContainsString('private-document-needle', $serialized);
    }

    public function test_global_admission_slot_rejects_concurrent_processing_immediately(): void
    {
        $lock = Cache::lock(DocxProcessingAdmission::SLOT_KEY, 40);
        $this->assertTrue($lock->get());
        Http::fake();

        try {
            $this->expectException(DocxProcessingBusy::class);

            app(DocxProcessorClient::class)->convert("PK\x03\x04bounded-docx");
        } finally {
            $lock->release();
        }
    }

    public function test_client_rejects_disabled_empty_and_invalidly_configured_processing(): void
    {
        config(['docx_processor.enabled' => false]);
        try {
            app(DocxProcessorClient::class)->convert("PK\x03\x04disabled");
            $this->fail('Disabled DOCX processing must fail closed.');
        } catch (DomainRuleViolation $exception) {
            $this->assertNotInstanceOf(DocxProcessingUnavailable::class, $exception);
        }

        config(['docx_processor.enabled' => true]);
        try {
            app(DocxProcessorClient::class)->convert('');
            $this->fail('Empty DOCX input must fail closed.');
        } catch (DomainRuleViolation $exception) {
            $this->assertNotInstanceOf(DocxProcessingUnavailable::class, $exception);
        }

        config(['docx_processor.url' => 'ftp://docx-processor.test']);
        Http::fake();
        $this->assertProcessorUnavailable(
            static fn () => app(DocxProcessorClient::class)->convert("PK\x03\x04invalid-configuration"),
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
            static fn () => app(DocxProcessorClient::class)->convert("PK\x03\x04connection-failure"),
        );

        try {
            app(DocxProcessorClient::class)->convert("PK\x03\x04second-dispatch");
            $this->fail('An uncertain dispatch must retain the global processing slot.');
        } catch (DocxProcessingBusy) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, $dispatches);

        Cache::lock(DocxProcessingAdmission::SLOT_KEY)->forceRelease();
        Http::fake(static function (): never {
            throw new \RuntimeException('private transport detail');
        });
        $this->assertProcessorUnavailable(
            static fn () => app(DocxProcessorClient::class)->convert("PK\x03\x04transport-failure"),
        );
        Cache::lock(DocxProcessingAdmission::SLOT_KEY)->forceRelease();
    }

    public function test_client_maps_encoded_and_unexpected_responses_to_retryable_failure(): void
    {
        Http::fake([
            '*' => Http::response('encoded', 200, ['Content-Encoding' => 'gzip']),
        ]);
        $this->assertProcessorUnavailable(
            static fn () => app(DocxProcessorClient::class)->convert("PK\x03\x04encoded-response"),
        );

        Cache::lock(DocxProcessingAdmission::SLOT_KEY)->forceRelease();
        foreach ([
            [401, '{"error":"authentication_failed"}'],
            [418, 'not-json'],
            [422, 'null'],
        ] as [$status, $body]) {
            Cache::lock(DocxProcessingAdmission::SLOT_KEY)->forceRelease();
            Http::fake(['*' => Http::response($body, $status)]);
            $this->assertProcessorUnavailable(
                static fn () => app(DocxProcessorClient::class)->convert("PK\x03\x04status-response"),
            );
        }
    }

    /** @param \Closure(): mixed $operation */
    private function assertProcessorUnavailable(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('The DOCX processor failure must remain retryable.');
        } catch (DocxProcessingUnavailable $exception) {
            $this->assertSame(
                'Word document processing service is unavailable. Try again shortly.',
                $exception->getMessage(),
            );
        }
    }

    /** @return array<string, string> */
    private function headers(string $nonce, string $docx, string $pdf): array
    {
        $inputHash = hash('sha256', $docx);

        return [
            'Cache-Control' => 'no-store',
            'Content-Type' => DocxProcessorProtocol::OUTPUT_MEDIA_TYPE,
            'X-ArtifactFlow-Processor-Nonce' => $nonce,
            'X-ArtifactFlow-Input-Bytes' => (string) strlen($docx),
            'X-ArtifactFlow-Input-SHA256' => $inputHash,
            'X-ArtifactFlow-Response-SHA256' => hash('sha256', $pdf),
            'X-ArtifactFlow-Processor-Profile' => DocxProcessorProtocol::PROCESSOR_PROFILE,
            'X-ArtifactFlow-Processor-Schema' => DocxProcessorProtocol::RESPONSE_SCHEMA,
            'X-ArtifactFlow-Processor-Engine' => DocxProcessorProtocol::ENGINE_NAME,
            'X-ArtifactFlow-Processor-Engine-Version' => DocxProcessorProtocol::ENGINE_VERSION,
            'X-ArtifactFlow-Package-Entry-Count' => '7',
            'X-ArtifactFlow-Package-Expanded-Bytes' => '2048',
            'X-ArtifactFlow-Package-Relationship-Count' => '2',
            'X-ArtifactFlow-Package-Media-Count' => '0',
            'X-ArtifactFlow-Package-External-Hyperlink-Count' => '1',
            'X-ArtifactFlow-Processor-Signature' => DocxProcessorProtocol::responseSignature(
                $nonce,
                strlen($docx),
                $inputHash,
                $pdf,
                7,
                2_048,
                2,
                0,
                1,
                self::SECRET,
            ),
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
