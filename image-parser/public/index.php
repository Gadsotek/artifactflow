<?php

declare(strict_types=1);

use ArtifactFlow\ImageParser\ParserAuthenticationFailure;
use ArtifactFlow\ImageParser\ParserClockSkewFailure;
use ArtifactFlow\ImageParser\ParserConfiguration;
use ArtifactFlow\ImageParser\ParserOutputTooLarge;
use ArtifactFlow\ImageParser\ParserRejection;
use ArtifactFlow\ImageParser\ParserRequest;
use ArtifactFlow\ImageParser\RasterNormalizer;

require dirname(__DIR__) . '/src/ImageParser.php';

/**
 * @param array<string, string> $headers
 */
function respond(int $status, string $body, array $headers = []): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }

    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

try {
    $configuration = ParserConfiguration::fromEnvironment();
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $requestTarget = $_SERVER['REQUEST_URI'] ?? '';
    $path = is_string($requestTarget) ? parse_url($requestTarget, PHP_URL_PATH) : null;

    if ($method === 'GET' && $path === '/health') {
        (new RasterNormalizer($configuration))->verifyHealth();
        respond(200, '{"status":"ok"}', ['Content-Type' => 'application/json']);
    }

    if ($method !== 'POST' || $path !== '/v1/normalize') {
        respond(404, '{"error":"not_found"}', ['Content-Type' => 'application/json']);
    }

    $request = ParserRequest::authenticated($configuration);
    [$image, $normalized] = (new RasterNormalizer($configuration))->normalize($request);
    $signature = hash_hmac('sha256', implode("\n", [
        'artifactflow-image-parser-response-v1',
        $request->nonce,
        $image->mediaType,
        (string) $image->width,
        (string) $image->height,
        hash('sha256', $normalized),
    ]), $configuration->sharedSecret);

    respond(200, $normalized, [
        'Content-Type' => $image->mediaType,
        'X-ArtifactFlow-Parser-Media-Type' => $image->mediaType,
        'X-ArtifactFlow-Parser-Width' => (string) $image->width,
        'X-ArtifactFlow-Parser-Height' => (string) $image->height,
        'X-ArtifactFlow-Parser-Signature' => $signature,
    ]);
} catch (ParserClockSkewFailure) {
    respond(401, '{"error":"clock_skew"}', ['Content-Type' => 'application/json']);
} catch (ParserAuthenticationFailure) {
    respond(401, '{"error":"unauthenticated"}', ['Content-Type' => 'application/json']);
} catch (ParserOutputTooLarge) {
    respond(422, '{"error":"normalized_image_too_large"}', ['Content-Type' => 'application/json']);
} catch (ParserRejection) {
    respond(422, '{"error":"image_rejected"}', ['Content-Type' => 'application/json']);
} catch (Throwable) {
    respond(503, '{"error":"service_unavailable"}', ['Content-Type' => 'application/json']);
}
