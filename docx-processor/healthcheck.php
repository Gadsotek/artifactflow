<?php

declare(strict_types=1);

use ArtifactFlow\DocxProcessor\ProcessorConfiguration;
use ArtifactFlow\DocxProcessor\ProcessorHealthRequest;

require_once __DIR__.'/src/DocxProcessor.php';

const MAX_HEALTH_RESPONSE_BYTES = 8192;

try {
    $socketPath = getenv('DOCX_PROCESSOR_SOCKET_PATH');
    if (! is_string($socketPath) || ! str_starts_with($socketPath, '/')) {
        exit(1);
    }

    $configuration = ProcessorConfiguration::fromEnvironment();
    $headers = (new ProcessorHealthRequest(bin2hex(random_bytes(16))))
        ->signedHeaders($configuration);
    $nonce = $headers['X-ArtifactFlow-Processor-Nonce'];

    $errorCode = 0;
    $errorMessage = '';
    $socket = @stream_socket_client(
        'unix://'.$socketPath,
        $errorCode,
        $errorMessage,
        2.0,
        STREAM_CLIENT_CONNECT,
    );
    if (! is_resource($socket)) {
        exit(1);
    }

    stream_set_timeout($socket, 2);

    $request = "GET /health HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n";
    foreach ($headers as $name => $value) {
        $request .= $name.': '.$value."\r\n";
    }
    $request .= "\r\n";

    $remaining = $request;
    while ($remaining !== '') {
        $written = fwrite($socket, $remaining);
        if (! is_int($written) || $written < 1) {
            fclose($socket);
            exit(1);
        }

        $remaining = substr($remaining, $written);
    }

    $response = stream_get_contents($socket, MAX_HEALTH_RESPONSE_BYTES + 1);
    fclose($socket);

    if (! is_string($response) || strlen($response) > MAX_HEALTH_RESPONSE_BYTES) {
        exit(1);
    }

    $separator = strpos($response, "\r\n\r\n");
    if ($separator === false) {
        exit(1);
    }

    $head = substr($response, 0, $separator);
    $body = substr($response, $separator + 4);
    if (! str_starts_with($head, 'HTTP/1.1 200 ')) {
        exit(1);
    }

    if (
        preg_match('/(?:\A|\r\n)X-ArtifactFlow-Processor-Nonce:\s*([^\r\n]+)(?:\r\n|\z)/i', $head, $nonceMatch) !== 1
        || preg_match('/(?:\A|\r\n)X-ArtifactFlow-Processor-Signature:\s*([a-f0-9]{64})(?:\r\n|\z)/i', $head, $signatureMatch) !== 1
        || ! hash_equals($nonce, trim($nonceMatch[1]))
        || ! hash_equals(
            ProcessorHealthRequest::responseSignature($nonce, $body, $configuration->sharedSecret),
            strtolower($signatureMatch[1]),
        )
    ) {
        exit(1);
    }

    $health = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    if (! is_array($health)
        || ($health['status'] ?? null) !== 'ok'
        || ($health['containment'] ?? null) !== 'network-isolated'
        || ($health['engine'] ?? null) !== 'libreoffice'
        || ($health['profile'] ?? null) !== 'docx-passive-pdf-v1'
        || ($health['schema'] ?? null) !== 'docx-processor-response-v1'
        || ! is_string($health['version'] ?? null)
        || $health['version'] === ''
    ) {
        exit(1);
    }
} catch (Throwable) {
    exit(1);
}

exit(0);
