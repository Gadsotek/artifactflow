<?php

declare(strict_types=1);

$applicationLimiter = env('CACHE_LIMITER');
$artifactLimiter = env('ARTIFACT_CACHE_LIMITER', 'database_artifact_limiter');

return [
    'default' => env('CACHE_STORE', 'database'),
    'app_limiter' => $applicationLimiter,
    'artifact_limiter' => $artifactLimiter,
    'limiter' => env('APP_RUNTIME_ROLE', 'app') === 'artifact-host'
        ? $artifactLimiter
        : $applicationLimiter,
    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE', 'cache_locks'),
        ],
        'database_limiter' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_RATE_LIMIT_CACHE_TABLE', 'rate_limit_cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_RATE_LIMIT_CACHE_LOCK_TABLE', 'rate_limit_cache_locks'),
        ],
        'database_artifact_limiter' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_ARTIFACT_RATE_LIMIT_CACHE_TABLE', 'artifact_rate_limit_cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_ARTIFACT_RATE_LIMIT_CACHE_LOCK_TABLE', 'artifact_rate_limit_cache_locks'),
        ],
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],
    ],
    'prefix' => env('CACHE_PREFIX', \Illuminate\Support\Str::slug((string) env('APP_NAME', 'laravel'), '_') . '_cache_'),
];
