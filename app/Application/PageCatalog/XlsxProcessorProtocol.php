<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class XlsxProcessorProtocol
{
    public const string INPUT_MEDIA_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public const string MANIFEST_MEDIA_TYPE = 'application/vnd.artifactflow.xlsx-manifest+json; charset=utf-8';

    public const string PROCESSOR_PROFILE = 'xlsx-typed-view-v1';

    public const string RESPONSE_SCHEMA = 'xlsx-processor-response-v1';

    public const string ENGINE_NAME = 'sheetjs-ce';

    public const string ENGINE_VERSION = '0.20.3';

    public const string REQUEST_CONTEXT = 'artifactflow-xlsx-processor-request-v1';

    public const string RESPONSE_CONTEXT = 'artifactflow-xlsx-processor-response-v1';

    public const string HEALTH_CONTEXT = 'artifactflow-xlsx-processor-health-v1';

    public const string HEALTH_RESPONSE_CONTEXT = 'artifactflow-xlsx-processor-health-response-v1';

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
        string $secret,
    ): string {
        return hash_hmac('sha256', implode("\n", [
            self::RESPONSE_CONTEXT,
            $nonce,
            (string) $inputBytes,
            $inputSha256,
            self::MANIFEST_MEDIA_TYPE,
            (string) strlen($responseBody),
            hash('sha256', $responseBody),
            self::RESPONSE_SCHEMA,
            self::PROCESSOR_PROFILE,
            self::ENGINE_NAME,
            self::ENGINE_VERSION,
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
