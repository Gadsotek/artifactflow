<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Http\BoundedResponseReader;
use App\Application\Http\BoundedResponseReadFailure;
use App\Application\Http\BoundedResponseSink;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\XlsxProcessingUnavailable;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

final readonly class XlsxProcessorClient
{
    public function __construct(
        private XlsxProcessorConfiguration $configuration,
        private XlsxProcessingAdmission $admission,
        private BoundedResponseReader $responseReader,
    ) {
    }

    public function project(string $untrustedBytes): XlsxProcessingResult
    {
        if (!$this->configuration->enabled()) {
            throw new DomainRuleViolation('XLSX artifacts are disabled for this installation.');
        }

        if ($untrustedBytes === '' || strlen($untrustedBytes) > XlsxProcessorConfiguration::MAX_INPUT_BYTES) {
            throw new DomainRuleViolation('XLSX exceeds the configured size limit.');
        }

        try {
            return $this->admission->run(
                fn (XlsxProcessingReservation $reservation): XlsxProcessingResult => $this->projectUnderAdmission(
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

    private function projectUnderAdmission(
        string $untrustedBytes,
        XlsxProcessingReservation $reservation,
    ): XlsxProcessingResult {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $inputBytes = strlen($untrustedBytes);
        $inputSha256 = hash('sha256', $untrustedBytes);

        try {
            $secret = $this->configuration->sharedSecret();
            $endpoint = $this->configuration->origin() . '/v1/xlsx/manifests';
            $request = Http::connectTimeout($this->configuration->connectTimeoutSeconds())
                ->timeout($this->configuration->timeoutSeconds())
                ->withoutRedirecting()
                ->withOptions($this->transportOptions(XlsxProcessorConfiguration::MAX_RESPONSE_BYTES))
                ->withHeaders([
                    'Accept' => XlsxProcessorProtocol::MANIFEST_MEDIA_TYPE,
                    'Accept-Encoding' => 'identity',
                    'X-ArtifactFlow-Input-SHA256' => $inputSha256,
                    'X-ArtifactFlow-Processor-Nonce' => $nonce,
                    'X-ArtifactFlow-Processor-Profile' => XlsxProcessorProtocol::PROCESSOR_PROFILE,
                    'X-ArtifactFlow-Processor-Signature' => XlsxProcessorProtocol::requestSignature(
                        $timestamp,
                        $nonce,
                        $untrustedBytes,
                        $secret,
                    ),
                    'X-ArtifactFlow-Processor-Timestamp' => $timestamp,
                ])
                ->withBody($untrustedBytes, XlsxProcessorProtocol::INPUT_MEDIA_TYPE);

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
            $body = $this->responseReader->read($response, XlsxProcessorConfiguration::MAX_RESPONSE_BYTES);
        } catch (BoundedResponseReadFailure) {
            $reservation->retainLeaseUntilExpiry();
            $this->unavailable('invalid_response', $response->status());
        }

        if ($response->status() === 422 && $this->errorCode($body) === 'xlsx_rejected') {
            throw new DomainRuleViolation('XLSX could not be validated or processed.');
        }

        if (!$response->successful()) {
            $reason = match (true) {
                $response->status() === 401 => 'authentication_failure',
                $response->serverError() => 'service_failure',
                default => 'unexpected_status',
            };

            $this->unavailable($reason, $response->status());
        }

        try {
            return $this->verifiedResult(
                $response,
                $body,
                $nonce,
                $inputBytes,
                $inputSha256,
                $secret,
            );
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
    ): XlsxProcessingResult {
        $responseSha256 = hash('sha256', $body);
        $signature = $response->header('X-ArtifactFlow-Processor-Signature');

        if (
            strtolower(trim($response->header('Content-Type'))) !== XlsxProcessorProtocol::MANIFEST_MEDIA_TYPE
            || strtolower(trim($response->header('Cache-Control'))) !== 'no-store'
            || strtolower(trim($response->header('X-Content-Type-Options'))) !== 'nosniff'
            || $response->header('X-ArtifactFlow-Processor-Nonce') !== $nonce
            || $response->header('X-ArtifactFlow-Input-Bytes') !== (string) $inputBytes
            || $response->header('X-ArtifactFlow-Input-SHA256') !== $inputSha256
            || $response->header('X-ArtifactFlow-Response-SHA256') !== $responseSha256
            || $response->header('X-ArtifactFlow-Processor-Profile') !== XlsxProcessorProtocol::PROCESSOR_PROFILE
            || $response->header('X-ArtifactFlow-Processor-Schema') !== XlsxProcessorProtocol::RESPONSE_SCHEMA
            || $response->header('X-ArtifactFlow-Processor-Engine') !== XlsxProcessorProtocol::ENGINE_NAME
            || $response->header('X-ArtifactFlow-Processor-Engine-Version') !== XlsxProcessorProtocol::ENGINE_VERSION
            || preg_match('/\A[a-f0-9]{64}\z/', $signature) !== 1
            || !hash_equals(
                XlsxProcessorProtocol::responseSignature(
                    $nonce,
                    $inputBytes,
                    $inputSha256,
                    $body,
                    $secret,
                ),
                $signature,
            )
        ) {
            throw new LogicException('Invalid XLSX processor response.');
        }

        return XlsxProcessingResult::fromJson($body, $inputBytes, $inputSha256);
    }

    private function errorCode(string $body): ?string
    {
        try {
            $decoded = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
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
        Log::error('xlsx_processor.request_failed', array_filter([
            'reason' => $reason,
            'status' => $status,
        ], static fn (mixed $value): bool => $value !== null));

        throw new XlsxProcessingUnavailable();
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
