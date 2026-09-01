<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class DocxProcessorProtocol
{
    public const string INPUT_MEDIA_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    public const string OUTPUT_MEDIA_TYPE = 'application/pdf';
    public const string PROCESSOR_PROFILE = 'docx-passive-pdf-v1';
    public const string RESPONSE_SCHEMA = 'docx-processor-response-v1';
    public const string ENGINE_NAME = 'libreoffice';
    public const string ENGINE_VERSION = '26.2.5';
    public const string REQUEST_CONTEXT = 'artifactflow-docx-processor-request-v1';
    public const string RESPONSE_CONTEXT = 'artifactflow-docx-processor-response-v1';
    public const string HEALTH_CONTEXT = 'artifactflow-docx-processor-health-v1';
    public const string HEALTH_RESPONSE_CONTEXT = 'artifactflow-docx-processor-health-response-v1';
    public const int MAX_MEDIA_COUNT = 1_024;

    public static function requestSignature(string $timestamp, string $nonce, string $bytes, string $secret): string
    {
        return hash_hmac('sha256', implode("\n", [
            self::REQUEST_CONTEXT,
            $timestamp,
            $nonce,
            self::PROCESSOR_PROFILE,
            self::INPUT_MEDIA_TYPE,
            (string) strlen($bytes),
            hash('sha256', $bytes),
        ]), $secret);
    }

    public static function responseSignature(
        string $nonce,
        int $inputBytes,
        string $inputSha256,
        string $responseBody,
        int $entryCount,
        int $expandedBytes,
        int $relationshipCount,
        int $mediaCount,
        int $externalHyperlinkCount,
        string $secret,
    ): string {
        return hash_hmac('sha256', implode("\n", [
            self::RESPONSE_CONTEXT,
            $nonce,
            (string) $inputBytes,
            $inputSha256,
            self::OUTPUT_MEDIA_TYPE,
            (string) strlen($responseBody),
            hash('sha256', $responseBody),
            self::RESPONSE_SCHEMA,
            self::PROCESSOR_PROFILE,
            self::ENGINE_NAME,
            self::ENGINE_VERSION,
            (string) $entryCount,
            (string) $expandedBytes,
            (string) $relationshipCount,
            (string) $mediaCount,
            (string) $externalHyperlinkCount,
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
