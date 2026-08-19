<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\PageCatalog\PdfProcessingAdmission;
use App\Application\PageCatalog\PdfProcessorClient;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PdfProcessingBusy;
use App\Domain\PageCatalog\PdfProcessingUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\RecordingLogger;
use Tests\TestCase;

final class PdfProcessorClientTest extends TestCase
{
    private const string SHARED_SECRET = 'test-pdf-processor-shared-secret-0001';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pdf_processor.enabled' => true,
            'pdf_processor.url' => 'http://pdf-processor.test',
            'pdf_processor.shared_secret' => self::SHARED_SECRET,
            'pdf_processor.connect_timeout_seconds' => 2,
            'pdf_processor.timeout_seconds' => 15,
        ]);
        Cache::flush();
    }

    public function test_client_sends_exact_signed_pdf_bytes_and_accepts_only_a_bound_signed_response(): void
    {
        $pdf = "%PDF-1.7\nembedded text\n%%EOF";

        Http::fake(function (Request $request) use ($pdf): \GuzzleHttp\Promise\PromiseInterface {
            $this->assertSame('http://pdf-processor.test/v1/inspect', $request->url());
            $this->assertSame('application/pdf', $request->header('Content-Type')[0] ?? null);
            $this->assertSame($pdf, $request->body());
            $timestamp = $request->header('X-ArtifactFlow-Processor-Timestamp')[0] ?? '';
            $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $signature = $request->header('X-ArtifactFlow-Processor-Signature')[0] ?? '';
            $this->assertIsString($timestamp);
            $this->assertIsString($nonce);
            $this->assertIsString($signature);
            $this->assertMatchesRegularExpression('/^\d{10}$/', $timestamp);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $nonce);
            $this->assertSame(
                $this->requestSignature($timestamp, $nonce, $pdf),
                $signature,
            );
            $body = '{"page_count":2,"pdf_version":"1.7","extraction_state":"indexed",'
                . '"processor_profile":"pdfbox-3.0.8-native-text-v1","text":"café"}';

            return Http::response($body, 200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-ArtifactFlow-Processor-Signature' => $this->responseSignature($nonce, $pdf, $body),
            ]);
        });

        $result = app(PdfProcessorClient::class)->inspect($pdf);

        $this->assertSame(2, $result->pageCount);
        $this->assertSame('1.7', $result->pdfVersion);
        $this->assertSame('indexed', $result->extractionState->value);
        $this->assertSame('pdfbox-3.0.8-native-text-v1', $result->processorProfile);
        $this->assertSame('café', $result->text);
        Http::assertSentCount(1);
    }

    public function test_client_rejects_an_unsigned_or_inconsistent_success_response(): void
    {
        $pdf = "%PDF-1.7\n%%EOF";
        $body = '{"page_count":1,"pdf_version":"1.7","extraction_state":"indexed",'
            . '"processor_profile":"pdfbox-3.0.8-native-text-v1","text":"private text"}';
        Http::fake([
            '*' => Http::response($body, 200, [
                'Content-Type' => 'application/json',
                'X-ArtifactFlow-Processor-Signature' => str_repeat('0', 64),
            ]),
        ]);

        $this->expectException(PdfProcessingUnavailable::class);

        app(PdfProcessorClient::class)->inspect($pdf);
    }

    public function test_client_rejects_signed_text_that_cannot_be_stored_safely(): void
    {
        $pdf = "%PDF-1.7\n%%EOF";

        Http::fake(function (Request $request) use ($pdf): \GuzzleHttp\Promise\PromiseInterface {
            $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $this->assertIsString($nonce);
            $body = '{"page_count":1,"pdf_version":"1.7","extraction_state":"indexed",'
                . '"processor_profile":"pdfbox-3.0.8-native-text-v1","text":"control\u0000byte"}';

            return Http::response($body, 200, [
                'Content-Type' => 'application/json',
                'X-ArtifactFlow-Processor-Signature' => $this->responseSignature($nonce, $pdf, $body),
            ]);
        });

        $this->expectException(PdfProcessingUnavailable::class);

        app(PdfProcessorClient::class)->inspect($pdf);
    }

    public function test_document_rejection_is_distinct_from_retryable_service_failure(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'pdf_rejected'], 422),
        ]);

        try {
            app(PdfProcessorClient::class)->inspect("%PDF-1.7\n%%EOF");
            $this->fail('A rejected document must not be treated as a transient outage.');
        } catch (DomainRuleViolation $exception) {
            $this->assertNotInstanceOf(PdfProcessingUnavailable::class, $exception);
            $this->assertSame('PDF could not be validated or processed.', $exception->getMessage());
        }
    }

    public function test_transport_failure_logs_only_bounded_metadata(): void
    {
        $logger = new RecordingLogger();
        Log::swap($logger);
        Http::fake([
            '*' => Http::response(['error' => 'service_unavailable'], 503),
        ]);

        try {
            app(PdfProcessorClient::class)->inspect("%PDF-1.7\nsecret-document-needle\n%%EOF");
            $this->fail('A processor outage must fail closed.');
        } catch (PdfProcessingUnavailable $exception) {
            $this->assertSame('PDF processing service is unavailable. Try again shortly.', $exception->getMessage());
        }

        $serialized = json_encode($logger->records, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('pdf_processor.request_failed', $serialized);
        $this->assertStringNotContainsString('secret-document-needle', $serialized);
    }

    public function test_global_admission_slot_rejects_concurrent_processing_immediately(): void
    {
        $lock = Cache::lock(PdfProcessingAdmission::SLOT_KEY, 30);
        $this->assertTrue($lock->get());
        Http::fake();

        try {
            $this->expectException(PdfProcessingBusy::class);

            app(PdfProcessorClient::class)->inspect("%PDF-1.7\n%%EOF");
        } finally {
            $lock->release();
        }
    }

    private function requestSignature(string $timestamp, string $nonce, string $pdf): string
    {
        return hash_hmac('sha256', implode("\n", [
            'artifactflow-pdf-processor-request-v1',
            $timestamp,
            $nonce,
            'application/pdf',
            (string) strlen($pdf),
            hash('sha256', $pdf),
        ]), self::SHARED_SECRET);
    }

    private function responseSignature(string $nonce, string $pdf, string $body): string
    {
        return hash_hmac('sha256', implode("\n", [
            'artifactflow-pdf-processor-response-v1',
            $nonce,
            hash('sha256', $pdf),
            hash('sha256', $body),
        ]), self::SHARED_SECRET);
    }
}
