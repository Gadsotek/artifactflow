<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Http\BoundedResponseReader;
use App\Application\Http\BoundedResponseReadFailure;
use App\Application\Http\BoundedResponseSink;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\DocxProcessingUnavailable;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

final readonly class DocxProcessorClient
{
    public function __construct(
        private DocxProcessorConfiguration $configuration,
        private DocxProcessingAdmission $admission,
        private BoundedResponseReader $responseReader,
    ) {
    }

    public function convert(string $untrustedBytes): DocxConversionResult
    {
        if (!$this->configuration->enabled()) {
            throw new DomainRuleViolation('Word document artifacts are disabled for this installation.');
        }
        if ($untrustedBytes === '' || strlen($untrustedBytes) > DocxProcessorConfiguration::MAX_INPUT_BYTES) {
            throw new DomainRuleViolation('DOCX exceeds the configured size limit.');
        }

        try {
            return $this->admission->run(
                fn (DocxProcessingReservation $reservation): DocxConversionResult => $this->convertUnderAdmission(
                    $untrustedBytes,
                    $reservation,
                ),
            );
        } catch (LogicException $exception) {
            if ($exception instanceof DomainRuleViolation) {
                throw $exception;
            }
            $this->unavailable('invalid_client_configuration');
        }
    }

    private function convertUnderAdmission(
        string $bytes,
        DocxProcessingReservation $reservation,
    ): DocxConversionResult {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $inputBytes = strlen($bytes);
        $inputSha256 = hash('sha256', $bytes);

        try {
            $secret = $this->configuration->sharedSecret();
            $request = Http::connectTimeout($this->configuration->connectTimeoutSeconds())
                ->timeout($this->configuration->timeoutSeconds())
                ->withoutRedirecting()
                ->withOptions($this->transportOptions())
                ->withHeaders([
                    'Accept' => DocxProcessorProtocol::OUTPUT_MEDIA_TYPE,
                    'Accept-Encoding' => 'identity',
                    'X-ArtifactFlow-Input-SHA256' => $inputSha256,
                    'X-ArtifactFlow-Processor-Nonce' => $nonce,
                    'X-ArtifactFlow-Processor-Profile' => DocxProcessorProtocol::PROCESSOR_PROFILE,
                    'X-ArtifactFlow-Processor-Signature' => DocxProcessorProtocol::requestSignature(
                        $timestamp,
                        $nonce,
                        $bytes,
                        $secret,
                    ),
                    'X-ArtifactFlow-Processor-Timestamp' => $timestamp,
                ])
                ->withBody($bytes, DocxProcessorProtocol::INPUT_MEDIA_TYPE);
            if ($this->configuration->socketPath() !== null) {
                $request->setHandler(new CurlHandler());
            }
        } catch (LogicException) {
            $this->unavailable('invalid_client_configuration');
        }

        $reservation->markDispatched();
        try {
            $response = $request->post($this->configuration->origin() . '/v1/docx/previews');
        } catch (ConnectionException) {
            $reservation->retainLeaseUntilExpiry();
            $this->unavailable('connection_failure');
        } catch (Throwable) {
            $reservation->retainLeaseUntilExpiry();
            $this->unavailable('transport_failure');
        }

        try {
            $body = $this->responseReader->read($response, DocxProcessorConfiguration::MAX_RESPONSE_BYTES);
        } catch (BoundedResponseReadFailure) {
            $reservation->retainLeaseUntilExpiry();
            $this->unavailable('invalid_response', $response->status());
        }

        $rejection = $response->status() === 422 ? $this->rejectionPayload($body) : null;
        if ($rejection !== null) {
            throw new DomainRuleViolation(match ($rejection['reason']) {
                'embedded_file' => 'This Word document contains an embedded file or OLE object, which is not supported.',
                default => 'DOCX could not be validated or converted.',
            });
        }
        if (!$response->successful()) {
            $this->unavailable(match (true) {
                $response->status() === 401 => 'authentication_failure',
                $response->serverError() => 'service_failure',
                default => 'unexpected_status',
            }, $response->status());
        }

        try {
            return $this->verifiedResult($response, $body, $nonce, $inputBytes, $inputSha256, $secret);
        } catch (Throwable) {
            $reservation->retainLeaseUntilExpiry();
            $this->unavailable('invalid_response', $response->status());
        }
    }

    private function verifiedResult(
        Response $response,
        string $body,
        string $nonce,
        int $inputBytes,
        string $inputSha256,
        string $secret,
    ): DocxConversionResult {
        $entryCount = $this->integerHeader($response, 'X-ArtifactFlow-Package-Entry-Count', 1, 2_000);
        $expandedBytes = $this->integerHeader($response, 'X-ArtifactFlow-Package-Expanded-Bytes', 1, 64 * 1024 * 1024);
        $relationshipCount = $this->integerHeader($response, 'X-ArtifactFlow-Package-Relationship-Count', 0, 4_000);
        $mediaCount = $this->integerHeader(
            $response,
            'X-ArtifactFlow-Package-Media-Count',
            0,
            DocxProcessorProtocol::MAX_MEDIA_COUNT,
        );
        $externalLinkCount = $this->integerHeader($response, 'X-ArtifactFlow-Package-External-Hyperlink-Count', 0, 1_000);
        $signature = $response->header('X-ArtifactFlow-Processor-Signature');

        if (
            strtolower(trim($response->header('Content-Type'))) !== DocxProcessorProtocol::OUTPUT_MEDIA_TYPE
            || strtolower(trim($response->header('Cache-Control'))) !== 'no-store'
            || strtolower(trim($response->header('X-Content-Type-Options'))) !== 'nosniff'
            || $response->header('X-ArtifactFlow-Processor-Nonce') !== $nonce
            || $response->header('X-ArtifactFlow-Input-Bytes') !== (string) $inputBytes
            || $response->header('X-ArtifactFlow-Input-SHA256') !== $inputSha256
            || $response->header('X-ArtifactFlow-Response-SHA256') !== hash('sha256', $body)
            || $response->header('X-ArtifactFlow-Processor-Profile') !== DocxProcessorProtocol::PROCESSOR_PROFILE
            || $response->header('X-ArtifactFlow-Processor-Schema') !== DocxProcessorProtocol::RESPONSE_SCHEMA
            || $response->header('X-ArtifactFlow-Processor-Engine') !== DocxProcessorProtocol::ENGINE_NAME
            || $response->header('X-ArtifactFlow-Processor-Engine-Version') !== DocxProcessorProtocol::ENGINE_VERSION
            || strlen($body) < 6
            || strlen($body) > DocxProcessorConfiguration::MAX_RESPONSE_BYTES
            || !str_starts_with($body, '%PDF-')
            || preg_match('/%%EOF\s*\z/D', $body) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $signature) !== 1
            || !hash_equals(DocxProcessorProtocol::responseSignature(
                $nonce,
                $inputBytes,
                $inputSha256,
                $body,
                $entryCount,
                $expandedBytes,
                $relationshipCount,
                $mediaCount,
                $externalLinkCount,
                $secret,
            ), $signature)
        ) {
            throw new LogicException('Invalid DOCX processor response.');
        }

        return new DocxConversionResult(
            pdfBytes: $body,
            packageEntryCount: $entryCount,
            expandedBytes: $expandedBytes,
            relationshipCount: $relationshipCount,
            mediaCount: $mediaCount,
            externalHyperlinkCount: $externalLinkCount,
        );
    }

    private function integerHeader(Response $response, string $name, int $minimum, int $maximum): int
    {
        $value = $response->header($name);
        if (preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/', $value) !== 1) {
            throw new LogicException('DOCX processor fact header is invalid.');
        }
        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) {
            throw new LogicException('DOCX processor fact header is outside its bound.');
        }

        return $integer;
    }

    /** @return array{reason: ?string}|null */
    private function rejectionPayload(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || ($decoded['error'] ?? null) !== 'docx_rejected') {
            return null;
        }

        return ['reason' => is_string($decoded['reason'] ?? null) ? $decoded['reason'] : null];
    }

    private function unavailable(string $reason, ?int $status = null): never
    {
        Log::error('docx_processor.request_failed', array_filter([
            'reason' => $reason,
            'status' => $status,
        ], static fn (mixed $value): bool => $value !== null));

        throw new DocxProcessingUnavailable();
    }

    /** @return array{stream: true, decode_content: false}|array{stream: true, decode_content: false, sink: BoundedResponseSink, curl: array<int, string>} */
    private function transportOptions(): array
    {
        $socketPath = $this->configuration->socketPath();
        if ($socketPath === null) {
            return ['stream' => true, 'decode_content' => false];
        }

        return [
            'stream' => true,
            'decode_content' => false,
            'sink' => new BoundedResponseSink(DocxProcessorConfiguration::MAX_RESPONSE_BYTES),
            'curl' => [CURLOPT_UNIX_SOCKET_PATH => $socketPath],
        ];
    }
}
