<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

use App\Application\Http\BoundedResponseReader;
use App\Application\Http\BoundedResponseReadFailure;
use App\Application\Http\BoundedResponseSink;
use App\Application\PageCatalog\DocxProcessorProtocol;
use App\Application\PageCatalog\PdfProcessorProtocol;
use App\Application\PageCatalog\XlsxProcessorProtocol;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

final readonly class HttpProcessorHealthProbe implements ProcessorHealthProbe
{
    private const int MAX_RESPONSE_BYTES = 2_048;

    public function __construct(private BoundedResponseReader $responseReader)
    {
    }

    public function xlsx(ProcessorHealthTarget $target): ProcessorHealthProbeResult
    {
        return $this->probe(
            $target,
            XlsxProcessorProtocol::healthSignature(...),
            XlsxProcessorProtocol::healthResponseSignature(...),
            [
                'containment' => 'network-isolated',
                'engine' => XlsxProcessorProtocol::ENGINE_NAME,
                'engineVersion' => XlsxProcessorProtocol::ENGINE_VERSION,
                'profile' => XlsxProcessorProtocol::PROCESSOR_PROFILE,
                'schema' => XlsxProcessorProtocol::RESPONSE_SCHEMA,
                'status' => 'ok',
            ],
            'XLSX',
        );
    }

    public function docx(ProcessorHealthTarget $target): ProcessorHealthProbeResult
    {
        return $this->probe(
            $target,
            DocxProcessorProtocol::healthSignature(...),
            DocxProcessorProtocol::healthResponseSignature(...),
            [
                'containment' => 'network-isolated',
                'engine' => DocxProcessorProtocol::ENGINE_NAME,
                'profile' => DocxProcessorProtocol::PROCESSOR_PROFILE,
                'schema' => DocxProcessorProtocol::RESPONSE_SCHEMA,
                'status' => 'ok',
                'version' => DocxProcessorProtocol::ENGINE_VERSION,
            ],
            'DOCX',
        );
    }

    public function pdf(ProcessorHealthTarget $target): ProcessorHealthProbeResult
    {
        return $this->probe(
            $target,
            PdfProcessorProtocol::healthSignature(...),
            PdfProcessorProtocol::healthResponseSignature(...),
            ['status' => 'ok'],
            'PDF',
        );
    }

    /**
     * @param callable(string, string, string): string $signature
     * @param callable(string, string, string): string $responseSignature
     * @param array<string, string> $expected
     */
    private function probe(
        ProcessorHealthTarget $target,
        callable $signature,
        callable $responseSignature,
        array $expected,
        string $label,
    ): ProcessorHealthProbeResult {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        try {
            $request = Http::connectTimeout($target->connectTimeoutSeconds)
                ->timeout($target->timeoutSeconds)
                ->withoutRedirecting()
                ->withOptions($this->transportOptions($target->socketPath))
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Accept-Encoding' => 'identity',
                    'X-ArtifactFlow-Processor-Nonce' => $nonce,
                    'X-ArtifactFlow-Processor-Signature' => $signature(
                        $timestamp,
                        $nonce,
                        $target->sharedSecret,
                    ),
                    'X-ArtifactFlow-Processor-Timestamp' => $timestamp,
                ]);
            $this->useCurlForSocket($request, $target->socketPath);
            $response = $request->get($target->origin . '/health');
            $body = $this->responseReader->read($response, self::MAX_RESPONSE_BYTES);
        } catch (BoundedResponseReadFailure) {
            return ProcessorHealthProbeResult::unhealthy($label . ' processor health response violated its byte boundary.');
        } catch (Throwable) {
            return ProcessorHealthProbeResult::unhealthy($label . ' processor could not be reached.');
        }

        if (!$response->successful()) {
            return ProcessorHealthProbeResult::unhealthy(sprintf(
                '%s processor health returned HTTP %d.',
                $label,
                $response->status(),
            ));
        }

        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'), 2)[0]));
        if (
            $contentType !== 'application/json'
            || strtolower(trim($response->header('Cache-Control'))) !== 'no-store'
            || strtolower(trim($response->header('X-Content-Type-Options'))) !== 'nosniff'
        ) {
            return ProcessorHealthProbeResult::unhealthy($label . ' processor health headers are invalid.');
        }

        try {
            $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ProcessorHealthProbeResult::unhealthy($label . ' processor health body is invalid.');
        }

        if (!is_array($decoded) || $decoded !== $expected) {
            return ProcessorHealthProbeResult::unhealthy($label . ' processor profile or containment self-test is invalid.');
        }

        $responseNonce = strtolower(trim($response->header('X-ArtifactFlow-Processor-Nonce')));
        $actualResponseSignature = strtolower(trim($response->header('X-ArtifactFlow-Processor-Signature')));

        if (
            !hash_equals($nonce, $responseNonce)
            || preg_match('/\A[a-f0-9]{64}\z/', $actualResponseSignature) !== 1
            || !hash_equals(
                $responseSignature($nonce, $body, $target->sharedSecret),
                $actualResponseSignature,
            )
        ) {
            return ProcessorHealthProbeResult::unhealthy(
                $label . ' processor health response authentication failed.',
            );
        }

        return ProcessorHealthProbeResult::healthy($label . ' authenticated health challenge passed.');
    }

    private function useCurlForSocket(PendingRequest $request, ?string $socketPath): void
    {
        if ($socketPath !== null) {
            $request->setHandler(new CurlHandler());
        }
    }

    /**
     * @return array{stream: true, decode_content: false}|array{stream: true, decode_content: false, sink: BoundedResponseSink, curl: array<int, string>}
     */
    private function transportOptions(?string $socketPath): array
    {
        if ($socketPath === null) {
            return ['stream' => true, 'decode_content' => false];
        }

        return [
            'stream' => true,
            'decode_content' => false,
            'sink' => new BoundedResponseSink(self::MAX_RESPONSE_BYTES),
            'curl' => [CURLOPT_UNIX_SOCKET_PATH => $socketPath],
        ];
    }
}
