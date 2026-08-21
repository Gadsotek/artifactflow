<?php

declare(strict_types=1);

function ensurePdfProcessorSharedSecret(string $envPath): int
{
    if ($envPath === '') {
        fwrite(STDERR, "Environment file path is required.\n");

        return 1;
    }

    if (!is_file($envPath)) {
        fwrite(STDERR, "Environment file does not exist: {$envPath}\n");

        return 1;
    }

    $contents = file_get_contents($envPath);

    if (!is_string($contents)) {
        fwrite(STDERR, "Unable to read environment file: {$envPath}\n");

        return 1;
    }

    if (preg_match('/^PDF_PROCESSOR_SHARED_SECRET=(.*)$/m', $contents, $matches) === 1) {
        if (trim((string) $matches[1]) !== '') {
            echo "PDF_PROCESSOR_SHARED_SECRET already configured.\n";

            return 0;
        }

        $updated = preg_replace(
            '/^PDF_PROCESSOR_SHARED_SECRET=.*$/m',
            'PDF_PROCESSOR_SHARED_SECRET=' . generatedPdfProcessorSecret(),
            $contents,
            1,
        );

        if (!is_string($updated)) {
            fwrite(STDERR, "Unable to update PDF_PROCESSOR_SHARED_SECRET in {$envPath}.\n");

            return 1;
        }

        file_put_contents($envPath, $updated);
        echo "Generated PDF_PROCESSOR_SHARED_SECRET in .env.\n";

        return 0;
    }

    $separator = str_ends_with($contents, "\n") ? '' : "\n";
    file_put_contents(
        $envPath,
        $contents . $separator . 'PDF_PROCESSOR_SHARED_SECRET=' . generatedPdfProcessorSecret() . "\n",
    );
    echo "Generated PDF_PROCESSOR_SHARED_SECRET in .env.\n";

    return 0;
}

function generatedPdfProcessorSecret(): string
{
    return 'base64:' . base64_encode(random_bytes(32));
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $root = dirname(__DIR__);
    $envPath = $argv[1] ?? $root . '/.env';
    exit(ensurePdfProcessorSharedSecret(is_string($envPath) ? $envPath : ''));
}
