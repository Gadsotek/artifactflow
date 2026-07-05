<?php

declare(strict_types=1);

/**
 * Provision the Reverb realtime credentials in .env for an operator who opted
 * into realtime presence (artifactflow:install --reverb). It fills only what is
 * missing, so it is safe to re-run: REVERB_APP_ID / REVERB_APP_KEY /
 * REVERB_APP_SECRET are generated when blank, VITE_REVERB_APP_KEY is kept in
 * sync with REVERB_APP_KEY so the browser client and server agree, and
 * BROADCAST_CONNECTION is switched from the shipped `null` default to `reverb`.
 *
 * The generated REVERB_APP_SECRET is a 48-character hex token: 24 bytes (192 bits)
 * of entropy stored as a 48-byte string. SecretStrength counts the raw string
 * length for non-base64 secrets, so those 48 bytes clear the boot gate's 32-byte
 * floor (decoded entropy is 24 bytes, comfortably above 128 bits). The reverb
 * worker container reads APP_KEY at `docker compose up` time; this script does
 * not manage APP_KEY (artisan key:generate already does), but keeping realtime
 * provisioning in install is why the compose APP_KEY mapping can fail closed.
 */

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

$changed = false;

$appKey = envValue($contents, 'REVERB_APP_KEY');

if ($appKey === '') {
    $appKey = randomToken(16);
    $contents = upsertEnv($contents, 'REVERB_APP_KEY', $appKey);
    $changed = true;
}

if (envValue($contents, 'REVERB_APP_ID') === '') {
    $contents = upsertEnv($contents, 'REVERB_APP_ID', 'af-' . randomToken(8));
    $changed = true;
}

if (envValue($contents, 'REVERB_APP_SECRET') === '') {
    $contents = upsertEnv($contents, 'REVERB_APP_SECRET', randomToken(24));
    $changed = true;
}

if (envValue($contents, 'VITE_REVERB_APP_KEY') !== $appKey) {
    $contents = upsertEnv($contents, 'VITE_REVERB_APP_KEY', $appKey);
    $changed = true;
}

$broadcast = envValue($contents, 'BROADCAST_CONNECTION');

if ($broadcast === '' || strtolower($broadcast) === 'null') {
    $contents = upsertEnv($contents, 'BROADCAST_CONNECTION', 'reverb');
    $changed = true;
}

if (!$changed) {
    echo "Reverb realtime keys already configured.\n";
    exit(0);
}

if (file_put_contents($envPath, $contents) === false) {
    fwrite(STDERR, "Unable to write Reverb realtime keys to {$envPath}.\n");
    exit(1);
}

echo "Generated Reverb realtime keys in .env.\n";
exit(0);

function randomToken(int $bytes): string
{
    return bin2hex(random_bytes($bytes));
}

function envValue(string $contents, string $key): string
{
    $pattern = '/^' . preg_quote($key, '/') . '=(.*)$/m';

    if (preg_match($pattern, $contents, $matches) === 1) {
        return trim((string) $matches[1]);
    }

    return '';
}

function upsertEnv(string $contents, string $key, string $value): string
{
    $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

    if (preg_match($pattern, $contents) === 1) {
        $updated = preg_replace_callback(
            $pattern,
            static fn (): string => $key . '=' . $value,
            $contents,
            1,
        );

        return is_string($updated) ? $updated : $contents;
    }

    $separator = str_ends_with($contents, "\n") ? '' : "\n";

    return $contents . $separator . $key . '=' . $value . "\n";
}
