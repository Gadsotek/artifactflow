<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostics;

use App\Application\Diagnostics\HttpProcessorHealthProbe;
use App\Application\Diagnostics\ProcessorHealthTarget;
use App\Application\Http\BoundedResponseReader;
use App\Application\PageCatalog\DocxProcessorProtocol;
use App\Application\PageCatalog\PdfProcessorProtocol;
use App\Application\PageCatalog\XlsxProcessorProtocol;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpProcessorHealthProbeTest extends TestCase
{
    public function test_xlsx_probe_authenticates_and_requires_the_exact_live_profile(): void
    {
        $secret = str_repeat('x', 32);
        $payloads = [
            [
                'containment' => 'network-isolated',
                'engine' => XlsxProcessorProtocol::ENGINE_NAME,
                'engineVersion' => XlsxProcessorProtocol::ENGINE_VERSION,
                'profile' => XlsxProcessorProtocol::PROCESSOR_PROFILE,
                'schema' => XlsxProcessorProtocol::RESPONSE_SCHEMA,
                'status' => 'ok',
            ],
            [
                    'containment' => 'network-isolated',
                    'engine' => XlsxProcessorProtocol::ENGINE_NAME,
                    'engineVersion' => 'unexpected',
                    'profile' => XlsxProcessorProtocol::PROCESSOR_PROFILE,
                    'schema' => XlsxProcessorProtocol::RESPONSE_SCHEMA,
                    'status' => 'ok',
            ],
        ];
        $responseIndex = 0;
        Http::fake(function (Request $request) use (&$responseIndex, $payloads, $secret) {
            return $this->signedHealthResponse(
                $request,
                $payloads[$responseIndex++],
                $secret,
                XlsxProcessorProtocol::healthResponseSignature(...),
            );
        });

        $result = $this->probe()->xlsx($this->target('https://xlsx.internal', $secret));

        $this->assertTrue($result->healthy, $result->detail);
        Http::assertSent(function (Request $request) use ($secret): bool {
            $timestamp = $request->header('X-ArtifactFlow-Processor-Timestamp')[0] ?? '';
            $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $signature = $request->header('X-ArtifactFlow-Processor-Signature')[0] ?? '';

            if (!is_string($timestamp) || !is_string($nonce) || !is_string($signature)) {
                return false;
            }

            return $request->method() === 'GET'
                && $request->url() === 'https://xlsx.internal/health'
                && $signature === XlsxProcessorProtocol::healthSignature($timestamp, $nonce, $secret);
        });

        $mismatch = $this->probe()->xlsx($this->target('https://xlsx.internal', $secret));
        $this->assertFalse($mismatch->healthy);
        $this->assertStringContainsString('profile or containment', $mismatch->detail);
    }

    public function test_docx_and_pdf_probes_validate_their_distinct_contracts(): void
    {
        $docxSecret = str_repeat('d', 32);
        $pdfSecret = str_repeat('p', 32);
        Http::fake(function (Request $request) use ($docxSecret, $pdfSecret) {
            if ($request->url() === 'https://docx.internal/health') {
                return $this->signedHealthResponse($request, [
                'containment' => 'network-isolated',
                'engine' => DocxProcessorProtocol::ENGINE_NAME,
                'profile' => DocxProcessorProtocol::PROCESSOR_PROFILE,
                'schema' => DocxProcessorProtocol::RESPONSE_SCHEMA,
                'status' => 'ok',
                'version' => DocxProcessorProtocol::ENGINE_VERSION,
                ], $docxSecret, DocxProcessorProtocol::healthResponseSignature(...));
            }

            return $this->signedHealthResponse(
                $request,
                ['status' => 'ok'],
                $pdfSecret,
                PdfProcessorProtocol::healthResponseSignature(...),
            );
        });

        $docx = $this->probe()->docx($this->target('https://docx.internal', $docxSecret));
        $pdf = $this->probe()->pdf($this->target('https://pdf.internal', $pdfSecret));

        $this->assertTrue($docx->healthy, $docx->detail);
        $this->assertTrue($pdf->healthy, $pdf->detail);
    }

    public function test_probe_fails_closed_for_status_headers_body_and_size(): void
    {
        $secret = str_repeat('x', 32);
        $target = $this->target('https://xlsx.internal', $secret);

        Http::fake([
            'https://xlsx.internal/health' => Http::sequence()
                ->push(['error' => 'unavailable'], 503, $this->healthHeaders())
                ->push(['status' => 'ok'], 200, ['Content-Type' => 'application/json'])
                ->push('{', 200, $this->healthHeaders())
                ->push(str_repeat('x', 2_049), 200, $this->healthHeaders()),
        ]);
        $status = $this->probe()->xlsx($target);
        $this->assertFalse($status->healthy);
        $this->assertSame('XLSX processor health returned HTTP 503.', $status->detail);

        $headers = $this->probe()->xlsx($target);
        $this->assertFalse($headers->healthy);
        $this->assertSame('XLSX processor health headers are invalid.', $headers->detail);

        $body = $this->probe()->xlsx($target);
        $this->assertFalse($body->healthy);
        $this->assertSame('XLSX processor health body is invalid.', $body->detail);

        $oversized = $this->probe()->xlsx($target);
        $this->assertFalse($oversized->healthy);
        $this->assertSame('XLSX processor health response violated its byte boundary.', $oversized->detail);
    }

    public function test_probe_rejects_an_unsigned_or_nonce_mismatched_health_response(): void
    {
        $payload = [
            'containment' => 'network-isolated',
            'engine' => XlsxProcessorProtocol::ENGINE_NAME,
            'engineVersion' => XlsxProcessorProtocol::ENGINE_VERSION,
            'profile' => XlsxProcessorProtocol::PROCESSOR_PROFILE,
            'schema' => XlsxProcessorProtocol::RESPONSE_SCHEMA,
            'status' => 'ok',
        ];
        Http::fake([
            'https://xlsx.internal/health' => Http::sequence()
                ->push($payload, 200, $this->healthHeaders())
                ->push($payload, 200, [
                    ...$this->healthHeaders(),
                    'X-ArtifactFlow-Processor-Nonce' => str_repeat('0', 32),
                    'X-ArtifactFlow-Processor-Signature' => str_repeat('0', 64),
                ]),
        ]);
        $target = $this->target('https://xlsx.internal', str_repeat('x', 32));

        $unsigned = $this->probe()->xlsx($target);
        $mismatched = $this->probe()->xlsx($target);

        $this->assertFalse($unsigned->healthy);
        $this->assertSame('XLSX processor health response authentication failed.', $unsigned->detail);
        $this->assertFalse($mismatched->healthy);
        $this->assertSame('XLSX processor health response authentication failed.', $mismatched->detail);
    }

    /** @return array<string, string> */
    private function healthHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store',
            'Content-Type' => 'application/json',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /**
     * @param array<string, string> $payload
     * @param callable(string, string, string): string $signature
     */
    private function signedHealthResponse(
        Request $request,
        array $payload,
        string $secret,
        callable $signature,
    ): PromiseInterface {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
        $this->assertIsString($nonce);

        return Http::response($body, 200, [
            ...$this->healthHeaders(),
            'X-ArtifactFlow-Processor-Nonce' => $nonce,
            'X-ArtifactFlow-Processor-Signature' => $signature($nonce, $body, $secret),
        ]);
    }

    private function probe(): HttpProcessorHealthProbe
    {
        return new HttpProcessorHealthProbe(new BoundedResponseReader());
    }

    private function target(string $origin, string $secret): ProcessorHealthTarget
    {
        return new ProcessorHealthTarget(
            origin: $origin,
            socketPath: null,
            sharedSecret: $secret,
            connectTimeoutSeconds: 2,
            timeoutSeconds: 5,
        );
    }
}
