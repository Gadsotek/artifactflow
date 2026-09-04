<?php

declare(strict_types=1);

require_once __DIR__ . '/local-boundary-secret.php';

function ensurePdfProcessorSharedSecret(string $envPath): int
{
    return ensureLocalBoundarySecret(
        $envPath,
        'PDF_PROCESSOR_SHARED_SECRET',
        [
            'APP_KEY',
            'ARTIFACT_URL_SIGNING_KEY',
            'IMAGE_PARSER_SHARED_SECRET',
            'XLSX_PROCESSOR_SHARED_SECRET',
            'DOCX_PROCESSOR_SHARED_SECRET',
        ],
    );
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $root = dirname(__DIR__);
    $envPath = $argv[1] ?? $root . '/.env';
    exit(ensurePdfProcessorSharedSecret(is_string($envPath) ? $envPath : ''));
}
