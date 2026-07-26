<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class ImageParserProtocol
{
    public const string REQUEST_CONTEXT = 'artifactflow-image-parser-request-v3';

    public const string RESPONSE_CONTEXT = 'artifactflow-image-parser-response-v1';

    public static function requestSignature(
        string $timestamp,
        string $nonce,
        string $mediaType,
        int $maxInputBytes,
        int $maxOutputBytes,
        int $maxPixels,
        int $maxDimension,
        string $bytes,
        string $secret,
    ): string {
        return hash_hmac('sha256', implode("\n", [
            self::REQUEST_CONTEXT,
            $timestamp,
            $nonce,
            $mediaType,
            (string) $maxInputBytes,
            (string) $maxOutputBytes,
            (string) $maxPixels,
            (string) $maxDimension,
            hash('sha256', $bytes),
        ]), $secret);
    }

    public static function responseSignature(
        string $nonce,
        RasterImageInfo $image,
        string $bytes,
        string $secret,
    ): string {
        return hash_hmac('sha256', implode("\n", [
            self::RESPONSE_CONTEXT,
            $nonce,
            $image->mediaType,
            (string) $image->width,
            (string) $image->height,
            hash('sha256', $bytes),
        ]), $secret);
    }
}
