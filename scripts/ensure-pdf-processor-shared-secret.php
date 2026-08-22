<?php

declare(strict_types=1);

use App\Infrastructure\Security\SecretStrength;

require_once dirname(__DIR__) . '/app/Infrastructure/Security/SecretStrength.php';

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

    if (preg_match_all('/^PDF_PROCESSOR_SHARED_SECRET=(.*)$/m', $contents, $matches) >= 1) {
        $configuredValues = $matches[1];
        $configuredValue = end($configuredValues);

        if (is_string($configuredValue) && SecretStrength::isStrong(pdfProcessorEnvironmentValue($configuredValue))) {
            echo "PDF_PROCESSOR_SHARED_SECRET already configured.\n";

            return 0;
        }

        $generatedSecret = generatedPdfProcessorSecret();
        $updated = preg_replace(
            '/^PDF_PROCESSOR_SHARED_SECRET=.*$/m',
            'PDF_PROCESSOR_SHARED_SECRET=' . $generatedSecret,
            $contents,
        );

        if (!is_string($updated)) {
            fwrite(STDERR, "Unable to update PDF_PROCESSOR_SHARED_SECRET in {$envPath}.\n");

            return 1;
        }

        if (file_put_contents($envPath, $updated) === false) {
            fwrite(STDERR, "Unable to write PDF_PROCESSOR_SHARED_SECRET to {$envPath}.\n");

            return 1;
        }

        echo "Generated PDF_PROCESSOR_SHARED_SECRET in .env.\n";

        return 0;
    }

    $separator = str_ends_with($contents, "\n") ? '' : "\n";
    if (file_put_contents(
        $envPath,
        $contents . $separator . 'PDF_PROCESSOR_SHARED_SECRET=' . generatedPdfProcessorSecret() . "\n",
    ) === false) {
        fwrite(STDERR, "Unable to write PDF_PROCESSOR_SHARED_SECRET to {$envPath}.\n");

        return 1;
    }

    echo "Generated PDF_PROCESSOR_SHARED_SECRET in .env.\n";

    return 0;
}

function pdfProcessorEnvironmentValue(string $value): string
{
    $value = ltrim($value);

    if ($value === '') {
        return '';
    }

    if ($value[0] === "'" || $value[0] === '"') {
        return pdfProcessorQuotedEnvironmentValue($value, $value[0]);
    }

    $value = preg_replace('/\s+#.*$/', '', $value);

    if (!is_string($value)) {
        return '';
    }

    $value = trim($value);

    // Compose expands unquoted variables before placing this value into both
    // containers. The host bootstrap cannot safely know that environment, so
    // replace interpolated values with one unambiguous literal secret.
    if ($value === '' || $value[0] === '#' || str_contains($value, '$')) {
        return '';
    }

    return $value;
}

function pdfProcessorQuotedEnvironmentValue(string $value, string $quote): string
{
    $length = strlen($value);
    $candidate = '';

    for ($index = 1; $index < $length; $index++) {
        $character = $value[$index];

        if ($character === $quote) {
            $remainder = trim(substr($value, $index + 1));

            return $remainder === '' || str_starts_with($remainder, '#') ? $candidate : '';
        }

        // Quoted escapes and interpolation require the complete Compose parser
        // to evaluate exactly. Repair those ambiguous local values rather than
        // risk validating different bytes from the two containers.
        if ($quote === '"' && ($character === '\\' || $character === '$')) {
            return '';
        }

        $candidate .= $character;
    }

    return '';
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
