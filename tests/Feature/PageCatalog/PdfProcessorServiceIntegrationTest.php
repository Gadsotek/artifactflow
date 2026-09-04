<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use ArtifactFlow\PdfProcessor\EngineInspection;
use ArtifactFlow\PdfProcessor\EngineProtocolFailure;
use ArtifactFlow\PdfProcessor\EngineRejection;
use ArtifactFlow\PdfProcessor\EngineUnavailable;
use ArtifactFlow\PdfProcessor\PdfBoxEngine;
use ArtifactFlow\PdfProcessor\ProcessorAuthenticationFailure;
use ArtifactFlow\PdfProcessor\ProcessorClockSkewFailure;
use ArtifactFlow\PdfProcessor\ProcessorConfiguration;
use ArtifactFlow\PdfProcessor\ProcessorHealthRequest;
use ArtifactFlow\PdfProcessor\ProcessorRejection;
use ArtifactFlow\PdfProcessor\ProcessorRequest;
use ArtifactFlow\PdfProcessor\ProcessorResult;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PdfProcessorServiceIntegrationTest extends TestCase
{
    private const string SHARED_SECRET = 'test-pdf-processor-shared-secret-0001';

    protected function setUp(): void
    {
        parent::setUp();

        $source = base_path('pdf-processor-spike/src/PdfProcessor.php');
        $this->assertFileExists($source);

        if (!class_exists(ProcessorConfiguration::class)) {
            require_once $source;
        }
    }

    public function test_request_signature_binds_the_exact_pdf_bytes(): void
    {
        $signedBody = "%PDF-1.7\n%%EOF";
        $timestamp = (string) time();
        $nonce = str_repeat('a', 32);
        $signature = $this->requestSignature($signedBody, $timestamp, $nonce);
        $configuration = $this->configuration();

        $request = ProcessorRequest::authenticated(
            $configuration,
            $this->server($signedBody, $timestamp, $nonce, $signature),
            $signedBody,
        );

        $this->assertSame($nonce, $request->nonce);
        $this->assertSame(hash('sha256', $signedBody), $request->inputSha256);

        $this->expectException(ProcessorAuthenticationFailure::class);

        ProcessorRequest::authenticated(
            $configuration,
            $this->server($signedBody, $timestamp, $nonce, $signature),
            "%PDF-1.7\n%tampered\n%%EOF",
        );
    }

    public function test_authenticated_stale_request_reports_clock_skew(): void
    {
        $body = "%PDF-1.7\n%%EOF";
        $timestamp = (string) (time() - 31);
        $nonce = str_repeat('b', 32);

        $this->expectException(ProcessorClockSkewFailure::class);

        ProcessorRequest::authenticated(
            $this->configuration(maxClockSkewSeconds: 30),
            $this->server($body, $timestamp, $nonce, $this->requestSignature($body, $timestamp, $nonce)),
            $body,
        );
    }

    public function test_request_rejects_non_pdf_media_type_and_oversized_input(): void
    {
        $body = "%PDF-1.7\n%%EOF";
        $timestamp = (string) time();
        $nonce = str_repeat('c', 32);
        $signature = $this->requestSignature($body, $timestamp, $nonce);
        $server = $this->server($body, $timestamp, $nonce, $signature);
        $server['CONTENT_TYPE'] = 'text/html';

        try {
            ProcessorRequest::authenticated($this->configuration(), $server, $body);
            $this->fail('A non-PDF media type must be rejected.');
        } catch (ProcessorRejection) {
            $this->addToAssertionCount(1);
        }

        $oversized = str_repeat('x', 17 * 1024 * 1024);
        $server = $this->server($oversized, $timestamp, $nonce, str_repeat('0', 64));

        $this->expectException(ProcessorRejection::class);

        ProcessorRequest::authenticated($this->configuration(), $server, $oversized);
    }

    public function test_engine_output_is_strictly_validated_and_mapped_to_extraction_state(): void
    {
        $indexed = EngineInspection::fromJson(
            '{"pages":2,"pdf_version":"1.7","truncated":false,"text":"Hello world"}',
        );
        $empty = EngineInspection::fromJson(
            '{"pages":1,"pdf_version":"1.4","truncated":false,"text":"  \\n"}',
        );
        $partial = EngineInspection::fromJson(
            '{"pages":3,"pdf_version":"2.0","truncated":true,"text":"bounded"}',
        );

        $this->assertSame('indexed', $indexed->extractionState());
        $this->assertSame('no_embedded_text', $empty->extractionState());
        $this->assertSame('partially_indexed', $partial->extractionState());

        try {
            EngineInspection::fromJson(
                '{"pages":1,"pdf_version":"2.9","truncated":false,"text":"future"}',
            );
            $this->fail('An unknown PDF version must be rejected.');
        } catch (EngineProtocolFailure) {
            $this->addToAssertionCount(1);
        }

        try {
            EngineInspection::fromJson(
                '{"pages":1,"pdf_version":"1.7","truncated":false,"text":"control\u0000byte"}',
            );
            $this->fail('Control bytes must not cross the processor protocol boundary.');
        } catch (EngineProtocolFailure) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(EngineProtocolFailure::class);

        EngineInspection::fromJson(
            '{"pages":0,"pdf_version":"1.7","truncated":false,"text":"invalid"}',
        );
    }

    public function test_response_is_bounded_canonical_json_and_signed_to_the_request(): void
    {
        $inspection = EngineInspection::fromJson(
            '{"pages":2,"pdf_version":"1.7","truncated":false,"text":"café"}',
        );
        $request = new ProcessorRequest(
            nonce: str_repeat('d', 32),
            inputSha256: hash('sha256', 'pdf bytes'),
            bytes: 'pdf bytes',
        );

        $result = ProcessorResult::fromInspection($inspection);
        $json = $result->toJson();
        $signature = $result->signature($request, self::SHARED_SECRET);

        $this->assertSame(
            '{"page_count":2,"pdf_version":"1.7","extraction_state":"indexed",'
                . '"processor_profile":"pdfbox-3.0.8-native-text-v1","text":"café"}',
            $json,
        );
        $this->assertSame(64, strlen($signature));
        $this->assertSame(
            hash_hmac('sha256', implode("\n", [
                'artifactflow-pdf-processor-response-v1',
                $request->nonce,
                $request->inputSha256,
                hash('sha256', $json),
            ]), self::SHARED_SECRET),
            $signature,
        );
    }

    public function test_health_signature_binds_the_probe_timestamp_and_nonce(): void
    {
        $timestamp = (string) time();
        $nonce = str_repeat('e', 32);
        $server = [
            'HTTP_X_ARTIFACTFLOW_PROCESSOR_TIMESTAMP' => $timestamp,
            'HTTP_X_ARTIFACTFLOW_PROCESSOR_NONCE' => $nonce,
            'HTTP_X_ARTIFACTFLOW_PROCESSOR_SIGNATURE' => $this->healthSignature($timestamp, $nonce),
        ];

        $request = ProcessorHealthRequest::authenticated($this->configuration(), $server);

        $this->assertSame($nonce, $request->nonce);
        $body = '{"status":"ok"}';
        $this->assertSame(
            hash_hmac('sha256', implode("\n", [
                'artifactflow-pdf-processor-health-response-v1',
                $nonce,
                'application/json',
                (string) strlen($body),
                hash('sha256', $body),
            ]), self::SHARED_SECRET),
            ProcessorHealthRequest::responseSignature($nonce, $body, self::SHARED_SECRET),
        );

        $server['HTTP_X_ARTIFACTFLOW_PROCESSOR_NONCE'] = str_repeat('f', 32);

        $this->expectException(ProcessorAuthenticationFailure::class);

        ProcessorHealthRequest::authenticated($this->configuration(), $server);
    }

    public function test_authenticated_stale_health_probe_reports_clock_skew(): void
    {
        $timestamp = (string) (time() - 31);
        $nonce = str_repeat('f', 32);

        $this->expectException(ProcessorClockSkewFailure::class);

        ProcessorHealthRequest::authenticated(
            $this->configuration(maxClockSkewSeconds: 30),
            [
                'HTTP_X_ARTIFACTFLOW_PROCESSOR_TIMESTAMP' => $timestamp,
                'HTTP_X_ARTIFACTFLOW_PROCESSOR_NONCE' => $nonce,
                'HTTP_X_ARTIFACTFLOW_PROCESSOR_SIGNATURE' => $this->healthSignature($timestamp, $nonce),
            ],
        );
    }

    public function test_health_route_rejects_a_non_loopback_peer_with_a_forged_forwarding_header(): void
    {
        $process = new Process(
            [dirname(PHP_BINARY) . '/php-cgi', '-d', 'display_errors=0'],
            base_path(),
            [
                'REDIRECT_STATUS' => '1',
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/health',
                'SCRIPT_FILENAME' => base_path('pdf-processor-spike/public/index.php'),
                'REMOTE_ADDR' => '10.20.30.40',
                'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
                'PDF_PROCESSOR_SHARED_SECRET' => self::SHARED_SECRET,
            ],
        );
        $process->setTimeout(5);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $response = $process->getOutput();
        $separator = strpos($response, "\r\n\r\n");
        $this->assertNotFalse($separator);
        $this->assertStringStartsWith('Status: 404 ', $response);
        $this->assertSame('{"error":"not_found"}', substr($response, $separator + 4));
    }

    public function test_health_route_rejects_an_unauthenticated_request_forwarded_by_a_loopback_proxy(): void
    {
        $process = new Process(
            [dirname(PHP_BINARY) . '/php-cgi', '-d', 'display_errors=0'],
            base_path(),
            [
                'REDIRECT_STATUS' => '1',
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/health',
                'SCRIPT_FILENAME' => base_path('pdf-processor-spike/public/index.php'),
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '10.20.30.40',
                'PDF_PROCESSOR_SHARED_SECRET' => self::SHARED_SECRET,
            ],
        );
        $process->setTimeout(5);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $response = $process->getOutput();
        $separator = strpos($response, "\r\n\r\n");
        $this->assertNotFalse($separator);
        $this->assertStringStartsWith('Status: 401 ', $response);
        $this->assertSame('{"error":"unauthenticated"}', substr($response, $separator + 4));
    }

    public function test_engine_runner_uses_an_argument_array_and_bounded_temporary_input(): void
    {
        $script = <<<'PHP'
$path = $argv[count($argv) - 1];
if (file_get_contents($path) !== 'pdf bytes') {
    exit(74);
}
echo '{"pages":1,"pdf_version":"1.7","truncated":false,"text":"from engine"}';
PHP;
        $engine = new PdfBoxEngine(
            command: [PHP_BINARY, '-r', $script],
            timeoutSeconds: 2,
        );

        $inspection = $engine->inspect('pdf bytes');

        $this->assertSame(1, $inspection->pageCount);
        $this->assertSame('from engine', $inspection->text);
    }

    public function test_engine_runner_distinguishes_rejection_from_failure(): void
    {
        $rejection = new PdfBoxEngine(
            command: [PHP_BINARY, '-r', 'fwrite(STDERR, "rejected: interactive_form\\n"); exit(65);'],
            timeoutSeconds: 2,
        );

        try {
            $rejection->inspect('pdf bytes');
            $this->fail('Exit status 65 must be a document rejection.');
        } catch (EngineRejection $exception) {
            $this->assertSame('interactive_form', $exception->reason);
        }

        $unrecognizedRejection = new PdfBoxEngine(
            command: [PHP_BINARY, '-r', 'fwrite(STDERR, "private parser detail\\n"); exit(65);'],
            timeoutSeconds: 2,
        );

        try {
            $unrecognizedRejection->inspect('pdf bytes');
            $this->fail('Raw engine diagnostics must not cross the processor boundary.');
        } catch (EngineRejection $exception) {
            $this->assertSame('invalid_pdf', $exception->reason);
            $this->assertStringNotContainsString('private parser detail', $exception->getMessage());
        }

        $failure = new PdfBoxEngine(
            command: [PHP_BINARY, '-r', 'exit(74);'],
            timeoutSeconds: 2,
        );

        $this->expectException(EngineUnavailable::class);

        $failure->inspect('pdf bytes');
    }

    public function test_engine_runner_kills_a_child_at_the_wall_clock_deadline(): void
    {
        $engine = new PdfBoxEngine(
            command: [PHP_BINARY, '-r', 'sleep(10);'],
            timeoutSeconds: 1,
        );
        $startedAt = microtime(true);

        try {
            $engine->inspect('pdf bytes');
            $this->fail('The hung engine must be terminated.');
        } catch (EngineUnavailable) {
            $this->assertLessThan(3.0, microtime(true) - $startedAt);
        }
    }

    public function test_engine_admission_prevents_a_health_probe_from_starting_a_second_native_process(): void
    {
        $lockPath = tempnam(sys_get_temp_dir(), 'artifactflow-pdf-engine-lock-');
        $markerPath = tempnam(sys_get_temp_dir(), 'artifactflow-pdf-engine-marker-');
        $this->assertIsString($lockPath);
        $this->assertIsString($markerPath);
        unlink($markerPath);
        $lock = fopen($lockPath, 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        $script = sprintf(
            'file_put_contents(%s, "started"); echo %s;',
            var_export($markerPath, true),
            var_export('{"pages":1,"pdf_version":"1.7","truncated":false,"text":"unexpected"}', true),
        );
        $engine = new PdfBoxEngine(
            command: [PHP_BINARY, '-r', $script],
            timeoutSeconds: 1,
            engineLockPath: $lockPath,
        );

        try {
            $engine->inspect('pdf bytes');
            $this->fail('A second native engine process must not start while the shared slot is held.');
        } catch (EngineUnavailable) {
            $this->assertFileDoesNotExist($markerPath);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            unlink($lockPath);

            if (is_file($markerPath)) {
                unlink($markerPath);
            }
        }
    }

    private function configuration(int $maxClockSkewSeconds = 120): ProcessorConfiguration
    {
        return new ProcessorConfiguration(
            sharedSecret: self::SHARED_SECRET,
            maxClockSkewSeconds: $maxClockSkewSeconds,
        );
    }

    /**
     * @return array<string, string>
     */
    private function server(string $body, string $timestamp, string $nonce, string $signature): array
    {
        return [
            'CONTENT_TYPE' => 'application/pdf',
            'CONTENT_LENGTH' => (string) strlen($body),
            'HTTP_X_ARTIFACTFLOW_PROCESSOR_TIMESTAMP' => $timestamp,
            'HTTP_X_ARTIFACTFLOW_PROCESSOR_NONCE' => $nonce,
            'HTTP_X_ARTIFACTFLOW_PROCESSOR_SIGNATURE' => $signature,
        ];
    }

    private function requestSignature(string $body, string $timestamp, string $nonce): string
    {
        return hash_hmac('sha256', implode("\n", [
            'artifactflow-pdf-processor-request-v1',
            $timestamp,
            $nonce,
            'application/pdf',
            (string) strlen($body),
            hash('sha256', $body),
        ]), self::SHARED_SECRET);
    }

    private function healthSignature(string $timestamp, string $nonce): string
    {
        return hash_hmac('sha256', implode("\n", [
            'artifactflow-pdf-processor-health-v1',
            $timestamp,
            $nonce,
            'GET',
            '/health',
        ]), self::SHARED_SECRET);
    }
}
