<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\ImageNormalizationUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

final readonly class RasterImageNormalizer
{
    public function __construct(
        private RasterImageInspector $inspector,
        private ImageArtifactLimits $limits,
        private ImageParserConfiguration $configuration,
        private ImageNormalizationAdmission $admission,
        private ImageParserResponseReader $responseReader,
    ) {
    }

    public function normalize(string $untrustedBytes, string $actorUid): string
    {
        return $this->normalizeWithInfo($untrustedBytes, $actorUid)->bytes;
    }

    public function normalizeWithInfo(string $untrustedBytes, string $actorUid): NormalizedRasterImage
    {
        $input = $this->inspector->inspectUpload($untrustedBytes);

        try {
            return $this->admission->run(
                $actorUid,
                $input,
                fn (ImageNormalizationReservation $reservation): NormalizedRasterImage => $this->normalizeUnderAdmission(
                    $untrustedBytes,
                    $input,
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

    private function normalizeUnderAdmission(
        string $untrustedBytes,
        RasterImageInfo $input,
        ImageNormalizationReservation $reservation,
    ): NormalizedRasterImage {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $maxInputBytes = $this->limits->maxUploadBytes();
        $maxOutputBytes = $this->limits->maxStoredBytes();
        $maxPixels = $this->limits->maxUploadPixels();
        $maxDimension = $this->limits->maxUploadDimension();

        try {
            $secret = $this->configuration->sharedSecret();
            $endpoint = $this->configuration->origin() . '/v1/normalize';
            $request = Http::connectTimeout($this->configuration->connectTimeoutSeconds())
                ->timeout($this->configuration->timeoutSeconds())
                ->withoutRedirecting()
                ->withOptions([
                    'stream' => true,
                    'decode_content' => false,
                ])
                ->withHeaders([
                    'Accept' => 'application/octet-stream',
                    'Accept-Encoding' => 'identity',
                    'X-ArtifactFlow-Parser-Timestamp' => $timestamp,
                    'X-ArtifactFlow-Parser-Nonce' => $nonce,
                    'X-ArtifactFlow-Parser-Max-Input-Bytes' => (string) $maxInputBytes,
                    'X-ArtifactFlow-Parser-Max-Output-Bytes' => (string) $maxOutputBytes,
                    'X-ArtifactFlow-Parser-Max-Pixels' => (string) $maxPixels,
                    'X-ArtifactFlow-Parser-Max-Dimension' => (string) $maxDimension,
                    'X-ArtifactFlow-Parser-Signature' => ImageParserProtocol::requestSignature(
                        $timestamp,
                        $nonce,
                        $input->mediaType,
                        $maxInputBytes,
                        $maxOutputBytes,
                        $maxPixels,
                        $maxDimension,
                        $untrustedBytes,
                        $secret,
                    ),
                ])
                ->withBody($untrustedBytes, $input->mediaType);
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
            $bytes = $this->responseReader->read($response, $maxOutputBytes);
        } catch (ImageParserResponseReadFailure) {
            $reservation->retainLeaseUntilExpiry();
            $this->unavailable('invalid_response', $response->status());
        }

        if ($response->status() === 422) {
            if ($this->errorCode($bytes) === 'normalized_image_too_large') {
                throw new DomainRuleViolation('Normalized image exceeds the configured artifact size limit.');
            }

            throw new DomainRuleViolation('Image could not be decoded.');
        }

        if (!$response->successful()) {
            $reason = $response->status() === 401 && $this->errorCode($bytes) === 'clock_skew'
                ? 'clock_skew'
                : match (true) {
                    $response->status() === 401 => 'authentication_failure',
                    $response->serverError() => 'service_failure',
                    default => 'unexpected_status',
                };

            $this->unavailable($reason, $response->status());
        }

        return $this->verifiedDerivative($response, $bytes, $input, $nonce, $secret);
    }

    private function verifiedDerivative(
        Response $response,
        string $bytes,
        RasterImageInfo $input,
        string $nonce,
        string $secret,
    ): NormalizedRasterImage {
        try {
            $mediaType = $response->header('X-ArtifactFlow-Parser-Media-Type');
            $width = $this->positiveHeaderInteger($response, 'X-ArtifactFlow-Parser-Width');
            $height = $this->positiveHeaderInteger($response, 'X-ArtifactFlow-Parser-Height');
            $signature = $response->header('X-ArtifactFlow-Parser-Signature');

            if (
                $bytes === ''
                || strlen($bytes) > $this->limits->maxStoredBytes()
                || $mediaType !== $input->mediaType
            ) {
                throw new DomainRuleViolation('invalid');
            }

            $output = $this->inspector->inspectStored($bytes);

            if (
                $output->mediaType !== $mediaType
                || $output->width !== $width
                || $output->height !== $height
                || !hash_equals(
                    ImageParserProtocol::responseSignature($nonce, $output, $bytes, $secret),
                    $signature,
                )
            ) {
                throw new DomainRuleViolation('invalid');
            }

            return new NormalizedRasterImage($bytes, $output);
        } catch (Throwable) {
            $this->unavailable('invalid_response', $response->status());
        }
    }

    private function errorCode(string $bytes): ?string
    {
        try {
            $decoded = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $error = $decoded['error'] ?? null;

        return is_string($error) ? $error : null;
    }

    private function positiveHeaderInteger(Response $response, string $name): int
    {
        $value = $response->header($name);

        if (!ctype_digit($value) || (int) $value < 1) {
            throw new DomainRuleViolation('invalid');
        }

        return (int) $value;
    }

    private function unavailable(string $reason, ?int $status = null): never
    {
        Log::error('image_parser.request_failed', array_filter([
            'reason' => $reason,
            'status' => $status,
        ], static fn (mixed $value): bool => $value !== null));

        throw new ImageNormalizationUnavailable();
    }
}
