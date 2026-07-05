<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$envPath = $argv[1] ?? $root . '/.env';

if (!is_string($envPath) || $envPath === '') {
    fwrite(STDERR, "Environment file path is required.\n");
    exit(1);
}

if (!is_file($envPath)) {
    fwrite(STDERR, "Environment file does not exist: {$envPath}\n");
    exit(1);
}

$contents = file_get_contents($envPath);

if (!is_string($contents)) {
    fwrite(STDERR, "Unable to read environment file: {$envPath}\n");
    exit(1);
}

if (preg_match('/^ARTIFACT_URL_SIGNING_KEY=(.*)$/m', $contents, $matches) === 1) {
    if (trim((string) $matches[1]) !== '') {
        echo "ARTIFACT_URL_SIGNING_KEY already configured.\n";
        exit(0);
    }

    $updated = preg_replace(
        '/^ARTIFACT_URL_SIGNING_KEY=.*$/m',
        'ARTIFACT_URL_SIGNING_KEY=' . generatedSigningKey(),
        $contents,
        1,
    );

    if (!is_string($updated)) {
        fwrite(STDERR, "Unable to update ARTIFACT_URL_SIGNING_KEY in {$envPath}.\n");
        exit(1);
    }

    file_put_contents($envPath, $updated);
    echo "Generated ARTIFACT_URL_SIGNING_KEY in .env.\n";
    exit(0);
}

$separator = str_ends_with($contents, "\n") ? '' : "\n";
file_put_contents($envPath, $contents . $separator . 'ARTIFACT_URL_SIGNING_KEY=' . generatedSigningKey() . "\n");
echo "Generated ARTIFACT_URL_SIGNING_KEY in .env.\n";

function generatedSigningKey(): string
{
    return 'base64:' . base64_encode(random_bytes(32));
}
