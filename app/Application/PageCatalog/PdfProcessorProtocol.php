<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class PdfProcessorProtocol
{
    public const string REQUEST_CONTEXT = 'artifactflow-pdf-processor-request-v1';

    public const string RESPONSE_CONTEXT = 'artifactflow-pdf-processor-response-v1';

    public static function requestSignature(
        string $timestamp,
        string $nonce,
        string $bytes,
        string $secret,
    ): string {
        return hash_hmac('sha256', implode("\n", [
            self::REQUEST_CONTEXT,
            $timestamp,
            $nonce,
            'application/pdf',
            (string) strlen($bytes),
            hash('sha256', $bytes),
        ]), $secret);
    }

    public static function responseSignature(
        string $nonce,
        string $inputSha256,
        string $responseBody,
        string $secret,
    ): string {
        return hash_hmac('sha256', implode("\n", [
            self::RESPONSE_CONTEXT,
            $nonce,
            $inputSha256,
            hash('sha256', $responseBody),
        ]), $secret);
    }
}
