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

    foreach (ProcessorHealthRequest::signedHeaders($configuration) as $name => $value) {
        $request .= $name . ': ' . $value . "\r\n";
    }

    $request .= "Connection: close\r\n\r\n";
    fwrite($socket, $request);
    $response = stream_get_contents($socket);
    fclose($socket);

    if (!is_string($response) || !str_starts_with($response, 'HTTP/1.1 200')) {
        exit(1);
    }
} catch (Throwable) {
    exit(1);
}

exit(0);
