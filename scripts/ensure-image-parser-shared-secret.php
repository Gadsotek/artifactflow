<?php

declare(strict_types=1);

require_once __DIR__ . '/local-boundary-secret.php';

function ensureImageParserSharedSecret(string $envPath): int
{
    return ensureLocalBoundarySecret(
        $envPath,
        'IMAGE_PARSER_SHARED_SECRET',
        ['APP_KEY', 'ARTIFACT_URL_SIGNING_KEY', 'PDF_PROCESSOR_SHARED_SECRET'],
    );
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $root = dirname(__DIR__);
    $envPath = $argv[1] ?? $root . '/.env';
    exit(ensureImageParserSharedSecret(is_string($envPath) ? $envPath : ''));
}
