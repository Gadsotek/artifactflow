<?php

declare(strict_types=1);

use ArtifactFlow\DocxProcessor\DocxPackageInspector;
use ArtifactFlow\DocxProcessor\DocxConversionSanitizer;
use ArtifactFlow\DocxProcessor\LibreOfficeConverter;
use ArtifactFlow\DocxProcessor\ProcessorAuthenticationFailure;
use ArtifactFlow\DocxProcessor\ProcessorContainment;
use ArtifactFlow\DocxProcessor\ProcessorConfiguration;
use ArtifactFlow\DocxProcessor\ProcessorHealthRequest;
use ArtifactFlow\DocxProcessor\ProcessorRejection;
use ArtifactFlow\DocxProcessor\ProcessorRequest;
use ArtifactFlow\DocxProcessor\ProcessorResult;
use ArtifactFlow\DocxProcessor\ProcessorUnavailable;

require dirname(__DIR__) . '/src/DocxProcessor.php';

/** @param array<string, string> $headers */
function respond(int $status, string $body, array $headers = []): never
{
    http_response_code($status);
    foreach (['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', ...$headers] as $name => $value) {
        header($name . ': ' . $value);
    }
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

try {
    $configuration = ProcessorConfiguration::fromEnvironment();
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $target = $_SERVER['REQUEST_URI'] ?? '';
    $path = is_string($target) ? parse_url($target, PHP_URL_PATH) : null;

    if ($method === 'GET' && $path === '/health') {
        $healthRequest = ProcessorHealthRequest::fromGlobals($configuration);
        ProcessorContainment::verifyNetworkIsolation();
        (new LibreOfficeConverter())->verifyHealth();
        $body = '{"containment":"network-isolated","engine":"libreoffice","profile":"docx-passive-pdf-v1","schema":"docx-processor-response-v1","status":"ok","version":"25.8.7.3"}';
        respond(200, $body, [
            'Content-Type' => 'application/json',
            'X-ArtifactFlow-Processor-Nonce' => $healthRequest->nonce,
            'X-ArtifactFlow-Processor-Signature' => ProcessorHealthRequest::responseSignature(
                $healthRequest->nonce,
                $body,
                $configuration->sharedSecret,
            ),
        ]);
    }

    if ($method !== 'POST' || $path !== '/v1/docx/previews') {
        respond(404, '{"error":"not_found"}', ['Content-Type' => 'application/json']);
    }

    $request = ProcessorRequest::fromGlobals($configuration);
    ProcessorContainment::verifyNetworkIsolation();
    $inspector = new DocxPackageInspector();
    $facts = $inspector->inspect($request->bytes);
    $conversionCopy = (new DocxConversionSanitizer())->stripForConversion($request->bytes);
    $inspector->inspect($conversionCopy);
    $conversion = (new LibreOfficeConverter())->convert($conversionCopy);
    $result = new ProcessorResult($conversion->pdfBytes, $facts);
    respond(200, $result->pdfBytes, $result->headers($request, $configuration->sharedSecret));
} catch (ProcessorAuthenticationFailure) {
    respond(401, '{"error":"unauthenticated"}', ['Content-Type' => 'application/json']);
} catch (ProcessorRejection $exception) {
    $context = $exception->diagnosticContextCode();
    error_log(
        'ArtifactFlow DOCX rejection '
        . $exception->diagnosticCode()
        . ($context === null ? '' : ' context ' . $context),
    );
    $body = match ($exception->publicReasonCode()) {
        'embedded_file' => '{"error":"docx_rejected","reason":"embedded_file"}',
        default => '{"error":"docx_rejected"}',
    };
    respond(422, $body, ['Content-Type' => 'application/json']);
} catch (ProcessorUnavailable $exception) {
    error_log('ArtifactFlow DOCX unavailable ' . $exception->diagnosticCode());
    respond(503, '{"error":"service_unavailable"}', ['Content-Type' => 'application/json']);
} catch (Throwable $exception) {
    error_log('ArtifactFlow DOCX unexpected ' . substr(hash('sha256', $exception::class), 0, 12));
    respond(503, '{"error":"service_unavailable"}', ['Content-Type' => 'application/json']);
}
