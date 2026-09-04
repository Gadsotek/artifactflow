<?php

declare(strict_types=1);

use ArtifactFlow\PdfProcessor\ProcessorConfiguration;
use ArtifactFlow\PdfProcessor\ProcessorHealthRequest;

require __DIR__ . '/src/PdfProcessor.php';

try {
    $configuration = ProcessorConfiguration::fromEnvironment();
    $socketPath = getenv('PDF_PROCESSOR_SOCKET_PATH') ?: '/run/artifactflow/pdf-processor/processor.sock';
    $socket = stream_socket_client('unix://' . $socketPath, $errorCode, $errorMessage, 1);

    if (!is_resource($socket)) {
        exit(1);
    }

    stream_set_timeout($socket, 1);
    $request = "GET /health HTTP/1.1\r\nHost: localhost\r\n";
    $headers = ProcessorHealthRequest::signedHeaders($configuration);
    $nonce = $headers['X-ArtifactFlow-Processor-Nonce'];

    foreach ($headers as $name => $value) {
        $request .= $name . ': ' . $value . "\r\n";
    }

    $request .= "Connection: close\r\n\r\n";
    fwrite($socket, $request);
    $response = stream_get_contents($socket);
    fclose($socket);

    if (!is_string($response) || strlen($response) > 8_192) {
        exit(1);
    }

    $separator = strpos($response, "\r\n\r\n");

    if ($separator === false) {
        exit(1);
    }

    $head = substr($response, 0, $separator);
    $body = substr($response, $separator + 4);

    if (
        !str_starts_with($head, 'HTTP/1.1 200 ')
        || $body !== '{"status":"ok"}'
        || preg_match('/(?:\A|\r\n)X-ArtifactFlow-Processor-Nonce:\s*([^\r\n]+)(?:\r\n|\z)/i', $head, $nonceMatch) !== 1
        || preg_match('/(?:\A|\r\n)X-ArtifactFlow-Processor-Signature:\s*([a-f0-9]{64})(?:\r\n|\z)/i', $head, $signatureMatch) !== 1
        || !hash_equals($nonce, trim($nonceMatch[1]))
        || !hash_equals(
            ProcessorHealthRequest::responseSignature($nonce, $body, $configuration->sharedSecret),
            strtolower($signatureMatch[1]),
        )
    ) {
        exit(1);
    }
} catch (Throwable) {
    exit(1);
}

exit(0);
