<?php

declare(strict_types=1);

$secure = env('SESSION_SECURE_COOKIE', true);
$cookieName = (string) env(
    'SESSION_COOKIE',
    \Illuminate\Support\Str::slug((string) env('APP_NAME', 'laravel'), '_') . '_session',
);

// Secure deployments never accept the legacy unprefixed session credential.
// Preserve custom names and avoid doubling a prefix supplied by the operator.
if ($secure === true && !str_starts_with($cookieName, '__Host-')) {
    $cookieName = '__Host-' . $cookieName;
}

return [
    'driver' => env('SESSION_DRIVER', 'database'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', false),
    'encrypt' => env('SESSION_ENCRYPT', false),
    'files' => storage_path('framework/sessions'),
    'connection' => env('SESSION_CONNECTION'),
    'table' => env('SESSION_TABLE', 'sessions'),
    'store' => env('SESSION_STORE'),
    'lottery' => [2, 100],
    'cookie' => $cookieName,
    'path' => env('SESSION_PATH', '/'),
    'domain' => env('SESSION_DOMAIN'),
    'secure' => $secure,
    'http_only' => env('SESSION_HTTP_ONLY', true),
    'same_site' => env('SESSION_SAME_SITE', 'lax'),
    'partitioned' => env('SESSION_PARTITIONED_COOKIE', false),
];
