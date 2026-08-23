<?php

declare(strict_types=1);

use App\Infrastructure\Security\SecretStrength;

require_once dirname(__DIR__) . '/app/Infrastructure/Security/SecretStrength.php';

/**
 * @param list<string> $mustDifferFrom
 */
function ensureLocalBoundarySecret(string $envPath, string $key, array $mustDifferFrom): int
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

    $configured = effectiveLocalEnvironmentValue($contents, $key);
    $isDedicated = true;

    if ($configured !== '') {
        foreach ($mustDifferFrom as $otherKey) {
            $other = effectiveLocalEnvironmentValue($contents, $otherKey);

            if ($other !== '' && localBoundarySecretsMatch($configured, $other)) {
                $isDedicated = false;
                break;
            }
        }
    }

    if (localBoundarySecretIsStrong($configured) && $isDedicated) {
        echo "{$key} already configured.\n";

        return 0;
    }

    $generated = generatedLocalBoundarySecret();
    $assignment = $key . '=' . $generated;

    if (preg_match('/^' . preg_quote($key, '/') . '=/m', $contents) === 1) {
        $updated = preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $assignment, $contents);

        if (!is_string($updated)) {
            fwrite(STDERR, "Unable to update {$key} in {$envPath}.\n");

            return 1;
        }
    } else {
        $separator = str_ends_with($contents, "\n") ? '' : "\n";
        $updated = $contents . $separator . $assignment . "\n";
    }

    if (file_put_contents($envPath, $updated) === false) {
        fwrite(STDERR, "Unable to write {$key} to {$envPath}.\n");

        return 1;
    }

    echo "Generated {$key} in .env.\n";

    return 0;
}

function effectiveLocalEnvironmentValue(string $contents, string $key): string
{
    if (preg_match_all('/^' . preg_quote($key, '/') . '=(.*)$/m', $contents, $matches) < 1) {
        return '';
    }

    $values = $matches[1];
    $value = end($values);

    return is_string($value) ? localEnvironmentValue($value) : '';
}

function localEnvironmentValue(string $value): string
{
    $value = ltrim($value);

    if ($value === '') {
        return '';
    }

    if ($value[0] === "'" || $value[0] === '"') {
        return localQuotedEnvironmentValue($value, $value[0]);
    }

    $value = preg_replace('/\s+#.*$/', '', $value);

    if (!is_string($value)) {
        return '';
    }

    $value = trim($value);

    if ($value === '' || $value[0] === '#' || str_contains($value, '$')) {
        return '';
    }

    return $value;
}

function localQuotedEnvironmentValue(string $value, string $quote): string
{
    $length = strlen($value);
    $candidate = '';

    for ($index = 1; $index < $length; $index++) {
        $character = $value[$index];

        if ($character === $quote) {
            $remainder = trim(substr($value, $index + 1));

            return $remainder === '' || str_starts_with($remainder, '#') ? $candidate : '';
        }

        if ($quote === '"' && ($character === '\\' || $character === '$')) {
            return '';
        }

        $candidate .= $character;
    }

    return '';
}

function localBoundarySecretIsStrong(string $secret): bool
{
    if (!SecretStrength::isProductionSafe($secret)) {
        return false;
    }

    if (!str_starts_with($secret, 'base64:')) {
        return true;
    }

    $normalized = SecretStrength::normalized($secret);

    return is_string($normalized)
        && hash_equals(base64_encode($normalized), substr($secret, 7));
}

function localBoundarySecretsMatch(string $first, string $second): bool
{
    $firstBytes = SecretStrength::normalized($first);
    $secondBytes = SecretStrength::normalized($second);

    return is_string($firstBytes)
        && is_string($secondBytes)
        && hash_equals($firstBytes, $secondBytes);
}

function generatedLocalBoundarySecret(): string
{
    return 'base64:' . base64_encode(random_bytes(32));
}
