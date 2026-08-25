<?php

declare(strict_types=1);

use ArtifactFlow\PdfProcessor\ProcessorConfiguration;

require __DIR__ . '/src/PdfProcessor.php';

$socket = null;
$healthy = false;

try {
    ProcessorConfiguration::fromEnvironment();

    $configuredPort = getenv('PORT');
    $portText = is_string($configuredPort) && $configuredPort !== '' ? $configuredPort : '8080';

    if (!ctype_digit($portText) || strlen($portText) > 5) {
        throw new RuntimeException('Invalid healthcheck port.');
    }

    $port = (int) $portText;

    if ($port < 1 || $port > 65_535) {
        throw new RuntimeException('Invalid healthcheck port.');
    }

    $socket = @stream_socket_client(
        sprintf('tcp://127.0.0.1:%d', $port),
        $errorCode,
        $errorMessage,
        1,
        STREAM_CLIENT_CONNECT,
    );

    if (!is_resource($socket)) {
        throw new RuntimeException('Healthcheck listener unavailable.');
    }

    stream_set_timeout($socket, 14);
    $request = "GET /health HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n";

    if (fwrite($socket, $request) !== strlen($request)) {
        throw new RuntimeException('Healthcheck request failed.');
    }

    $response = stream_get_contents($socket);

    if (!is_string($response)) {
        throw new RuntimeException('Healthcheck response unavailable.');
    }

    $separator = strpos($response, "\r\n\r\n");

    if ($separator === false) {
        throw new RuntimeException('Healthcheck response malformed.');
    }

    $headers = substr($response, 0, $separator);
    $body = substr($response, $separator + 4);
    $healthy = str_starts_with($headers, 'HTTP/1.1 200 ') && $body === '{"status":"ok"}';
} catch (Throwable) {
    $healthy = false;
}

if (is_resource($socket)) {
    fclose($socket);
}

exit($healthy ? 0 : 1);
