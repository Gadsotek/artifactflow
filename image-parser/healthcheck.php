<?php

declare(strict_types=1);

use ArtifactFlow\ImageParser\ParserConfiguration;

require __DIR__ . '/src/ImageParser.php';

try {
    ParserConfiguration::fromEnvironment();
    $socketPath = getenv('IMAGE_PARSER_SOCKET_PATH') ?: '/run/artifactflow/image-parser/parser.sock';
    $socket = stream_socket_client('unix://' . $socketPath, $errorCode, $errorMessage, 1);

    if (!is_resource($socket)) {
        exit(1);
    }

    stream_set_timeout($socket, 1);
    fwrite($socket, "GET /health HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $response = stream_get_contents($socket);
    fclose($socket);

    if (!is_string($response) || !str_starts_with($response, 'HTTP/1.1 200')) {
        exit(1);
    }
} catch (Throwable) {
    exit(1);
}

exit(0);
