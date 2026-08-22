<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Http\BoundedResponseReader;
use App\Application\Http\BoundedResponseReadFailure;
use App\Application\Http\BoundedResponseSink;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PdfProcessingUnavailable;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

final readonly class PdfProcessorClient
{
    public function __construct(
        private PdfProcessorConfiguration $configuration,
        private PdfProcessingAdmission $admission,
        private BoundedResponseReader $responseReader,
    ) {
    }

    public function inspect(string $untrustedBytes): PdfProcessingResult
    {
        if (!$this->configuration->enabled()) {
            throw new DomainRuleViolation('PDF artifacts are disabled for this installation.');
        }

        if ($untrustedBytes === '' || strlen($untrustedBytes) > PdfProcessorConfiguration::MAX_INPUT_BYTES) {
            throw new DomainRuleViolation('PDF exceeds the configured size limit.');
        }

        try {
            return $this->admission->run(
                fn (PdfProcessingReservation $reservation): PdfProcessingResult => $this->inspectUnderAdmission(
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

    private function inspectUnderAdmission(
        string $untrustedBytes,
        PdfProcessingReservation $reservation,
    ): PdfProcessingResult {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        try {
            $secret = $this->configuration->sharedSecret();
            $endpoint = $this->configuration->origin() . '/v1/inspect';
            $request = Http::connectTimeout($this->configuration->connectTimeoutSeconds())
                ->timeout($this->configuration->timeoutSeconds())
                ->withoutRedirecting()
                ->withOptions($this->transportOptions(PdfProcessorConfiguration::MAX_RESPONSE_BYTES))
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Accept-Encoding' => 'identity',
                    'X-ArtifactFlow-Processor-Timestamp' => $timestamp,
                    'X-ArtifactFlow-Processor-Nonce' => $nonce,
                    'X-ArtifactFlow-Processor-Signature' => PdfProcessorProtocol::requestSignature(
                        $timestamp,
                        $nonce,
                        $untrustedBytes,
                        $secret,
                    ),
                ])
                ->withBody($untrustedBytes, 'application/pdf');

            if ($this->configuration->socketPath() !== null) {
                $request->setHandler(new CurlHandler());
            }
        } catch (LogicException) {
            $this->unavailable('invalid_client_configuration');
        }

        $reservation->markDispatched();

        try {
            $response = $request->post($endpoint);
        } catch (ConnectionException) {
            $reservation->retainLeaseUntilExpiry();
            $this->unavailable('connection_failure');
        } catch (Throwable) {
            $reservation->retainLeaseUntilExpiry();
            $this->unavailable('transport_failure');
        }

        try {
            $body = $this->responseReader->read($response, PdfProcessorConfiguration::MAX_RESPONSE_BYTES);
        } catch (BoundedResponseReadFailure) {
            $reservation->retainLeaseUntilExpiry();
            $this->unavailable('invalid_response', $response->status());
        }

        if ($response->status() === 422 && $this->errorCode($body) === 'pdf_rejected') {
            throw new DomainRuleViolation('PDF could not be validated or processed.');
        }

        if (!$response->successful()) {
            $reason = $response->status() === 401 && $this->errorCode($body) === 'clock_skew'
                ? 'clock_skew'
                : match (true) {
                    $response->status() === 401 => 'authentication_failure',
                    $response->serverError() => 'service_failure',
                    default => 'unexpected_status',
                };

            $this->unavailable($reason, $response->status());
        }

        return $this->verifiedResult($response, $body, $nonce, hash('sha256', $untrustedBytes), $secret);
    }

    private function verifiedResult(
        Response $response,
        string $body,
        string $nonce,
        string $inputSha256,
        string $secret,
    ): PdfProcessingResult {
        try {
            $contentType = strtolower(trim((string) strtok($response->header('Content-Type'), ';')));
            $signature = $response->header('X-ArtifactFlow-Processor-Signature');

            if (
                $contentType !== 'application/json'
                || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1
                || !hash_equals(
                    PdfProcessorProtocol::responseSignature($nonce, $inputSha256, $body, $secret),
                    $signature,
                )
            ) {
                throw new LogicException('Invalid PDF processor response.');
            }

            return PdfProcessingResult::fromJson($body);
        } catch (Throwable) {
            $this->unavailable('invalid_response', $response->status());
        }
    }

    private function errorCode(string $body): ?string
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $error = $decoded['error'] ?? null;

        return is_string($error) ? $error : null;
    }

    private function unavailable(string $reason, ?int $status = null): never
    {
        Log::error('pdf_processor.request_failed', array_filter([
            'reason' => $reason,
            'status' => $status,
        ], static fn (mixed $value): bool => $value !== null));

        throw new PdfProcessingUnavailable();
    }

    /**
     * @return array{stream: true, decode_content: false}|array{stream: true, decode_content: false, sink: BoundedResponseSink, curl: array<int, string>}
     */
    private function transportOptions(int $maximumResponseBytes): array
    {
        $socketPath = $this->configuration->socketPath();

        if ($socketPath === null) {
            return [
                'stream' => true,
                'decode_content' => false,
            ];
        }

        return [
            'stream' => true,
            'decode_content' => false,
            'sink' => new BoundedResponseSink($maximumResponseBytes),
            'curl' => [CURLOPT_UNIX_SOCKET_PATH => $socketPath],
        ];
    }
}
