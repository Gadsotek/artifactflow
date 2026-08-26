<?php

declare(strict_types=1);

use ArtifactFlow\PdfProcessor\EngineRejection;
use ArtifactFlow\PdfProcessor\PdfBoxEngine;
use ArtifactFlow\PdfProcessor\ProcessorAuthenticationFailure;
use ArtifactFlow\PdfProcessor\ProcessorClockSkewFailure;
use ArtifactFlow\PdfProcessor\ProcessorConfiguration;
use ArtifactFlow\PdfProcessor\ProcessorRejection;
use ArtifactFlow\PdfProcessor\ProcessorRequest;
use ArtifactFlow\PdfProcessor\ProcessorResult;

require dirname(__DIR__) . '/src/PdfProcessor.php';

/**
 * @param array<string, string> $headers
 */
function respond(int $status, string $body, array $headers = []): never
{
    http_response_code($status);
    $headers = [
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
        ...$headers,
    ];

    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }

    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

try {
    $configuration = ProcessorConfiguration::fromEnvironment();
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $requestTarget = $_SERVER['REQUEST_URI'] ?? '';
    $path = is_string($requestTarget) ? parse_url($requestTarget, PHP_URL_PATH) : null;

    if ($method === 'GET' && $path === '/health') {
        $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $loopbackAddresses = ['127.0.0.1', '::1'];

        if (!is_string($remoteAddress) || !in_array($remoteAddress, $loopbackAddresses, true)) {
            respond(404, '{"error":"not_found"}', ['Content-Type' => 'application/json']);
        }

        PdfBoxEngine::production()->verifyHealth();
        respond(200, '{"status":"ok"}', ['Content-Type' => 'application/json']);
    }

    if ($method !== 'POST' || $path !== '/v1/inspect') {
        respond(404, '{"error":"not_found"}', ['Content-Type' => 'application/json']);
    }

    $request = ProcessorRequest::fromGlobals($configuration);
    $result = ProcessorResult::fromInspection(PdfBoxEngine::production()->inspect($request->bytes));
    $body = $result->toJson();

    respond(200, $body, [
        'Content-Type' => 'application/json; charset=utf-8',
        'X-ArtifactFlow-Processor-Signature' => $result->signature($request, $configuration->sharedSecret),
    ]);
} catch (ProcessorClockSkewFailure) {
    respond(401, '{"error":"clock_skew"}', ['Content-Type' => 'application/json']);
} catch (ProcessorAuthenticationFailure) {
    respond(401, '{"error":"unauthenticated"}', ['Content-Type' => 'application/json']);
} catch (EngineRejection $exception) {
    respond(422, sprintf(
        '{"error":"pdf_rejected","reason":"%s"}',
        $exception->reason,
    ), ['Content-Type' => 'application/json']);
} catch (ProcessorRejection) {
    respond(422, '{"error":"pdf_rejected"}', ['Content-Type' => 'application/json']);
} catch (Throwable) {
    respond(503, '{"error":"service_unavailable"}', ['Content-Type' => 'application/json']);
}
