<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$envPath = $argv[1] ?? $root . '/.env';

if (!is_string($envPath) || $envPath === '') {
    fwrite(STDERR, "Environment file path is required.\n");
    exit(1);
}

require_once __DIR__ . '/ensure-image-parser-shared-secret.php';
require_once __DIR__ . '/ensure-pdf-processor-shared-secret.php';

if (ensureLocalBoundarySecret($envPath, 'ARTIFACT_URL_SIGNING_KEY', ['APP_KEY']) !== 0) {
    exit(1);
}

if (ensureImageParserSharedSecret($envPath) !== 0) {
    exit(1);
}

if (ensurePdfProcessorSharedSecret($envPath) !== 0) {
    exit(1);
}
