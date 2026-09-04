<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class PdfProcessorProtocol
{
    public const string REQUEST_CONTEXT = 'artifactflow-pdf-processor-request-v1';

    public const string RESPONSE_CONTEXT = 'artifactflow-pdf-processor-response-v1';

    public const string DOCX_PREVIEW_PROFILE = 'pdfbox-3.0.8-docx-preview-v1';

    public const string DOCX_PREVIEW_REQUEST_CONTEXT = 'artifactflow-pdf-processor-docx-preview-request-v1';

    public const string DOCX_PREVIEW_RESPONSE_CONTEXT = 'artifactflow-pdf-processor-docx-preview-response-v1';

    public const string HEALTH_CONTEXT = 'artifactflow-pdf-processor-health-v1';

    public const string HEALTH_RESPONSE_CONTEXT = 'artifactflow-pdf-processor-health-response-v1';

    public static function requestSignature(
        string $timestamp,
        string $nonce,
        string $bytes,
        string $secret,
        ?string $profile = null,
    ): string {
        $parts = [
            $profile === self::DOCX_PREVIEW_PROFILE ? self::DOCX_PREVIEW_REQUEST_CONTEXT : self::REQUEST_CONTEXT,
            $timestamp,
            $nonce,
            ...($profile === self::DOCX_PREVIEW_PROFILE ? [$profile] : []),
            'application/pdf',
            (string) strlen($bytes),
            hash('sha256', $bytes),
        ];

        return hash_hmac('sha256', implode("\n", $parts), $secret);
    }

    public static function responseSignature(
        string $nonce,
        string $inputSha256,
        string $responseBody,
        string $secret,
        ?string $profile = null,
    ): string {
        return hash_hmac('sha256', implode("\n", [
            $profile === self::DOCX_PREVIEW_PROFILE ? self::DOCX_PREVIEW_RESPONSE_CONTEXT : self::RESPONSE_CONTEXT,
            $nonce,
            $inputSha256,
            ...($profile === self::DOCX_PREVIEW_PROFILE ? [$profile] : []),
            hash('sha256', $responseBody),
        ]), $secret);
    }

    public static function healthSignature(string $timestamp, string $nonce, string $secret): string
    {
        return hash_hmac('sha256', implode("\n", [
            self::HEALTH_CONTEXT,
            $timestamp,
            $nonce,
            'GET',
            '/health',
        ]), $secret);
    }

    public static function healthResponseSignature(string $nonce, string $responseBody, string $secret): string
    {
        return hash_hmac('sha256', implode("\n", [
            self::HEALTH_RESPONSE_CONTEXT,
            $nonce,
            'application/json',
            (string) strlen($responseBody),
            hash('sha256', $responseBody),
        ]), $secret);
    }
}
