<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'artifactflow'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'source_url' => env('APP_SOURCE_URL', 'https://github.com/Gadsotek/artifactflow'),
    // 127.0.0.1 while the app defaults to localhost: cookies ignore the port,
    // so only a different HOST keeps the app session cookie off artifact requests.
    'artifact_url' => env('ARTIFACT_URL', 'http://127.0.0.1:18081'),
    // Docker host ports published for the two origins, passed in by compose so
    // the doctor can warn when a port and its URL were not changed together.
    'host_port' => env('APP_HOST_PORT'),
    'artifact_host_port' => env('ARTIFACT_HOST_PORT'),
    'reverb_url' => env('REVERB_PUBLIC_URL', env('APP_URL', 'http://localhost')),
    'artifact_frame_ancestors' => env('ARTIFACT_FRAME_ANCESTORS', env('APP_URL', 'http://localhost')),
    'artifact_url_signing_key' => env('ARTIFACT_URL_SIGNING_KEY'),

    // HTTP Strict-Transport-Security. A strong two-year max-age is on by default,
    // but includeSubDomains and preload are opt-in: both are hard to walk back and
    // reach beyond this host. includeSubDomains forces HTTPS on every sibling
    // subdomain (an apex deployment can strand a plain-HTTP status page or intranet
    // host for the full max-age), and preload is a near-permanent commitment to the
    // browser preload list. A self-hosting operator turns them on once every
    // subdomain is HTTPS -- the product must not make that choice for them.
    'hsts' => [
        'max_age' => (int) env('HSTS_MAX_AGE', 63072000),
        'include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', false),
        'preload' => (bool) env('HSTS_PRELOAD', false),
    ],
    'artifact_preview_url_ttl_seconds' => (int) env('ARTIFACT_PREVIEW_URL_TTL_SECONDS', 60),
    'runtime_role' => env('APP_RUNTIME_ROLE', 'app'),
    'local_vite_origin' => env('VITE_DEV_SERVER_ORIGIN', 'http://localhost:' . (string) env('VITE_PORT', '5173')),
    'vite_hot_file' => env('VITE_HOT_FILE'),
    'default_theme' => env('DEFAULT_THEME', 'system'),
    'bootstrap_admin_password' => env('ARTIFACTFLOW_ADMIN_PASSWORD'),
    'bootstrap_admin_command' => 'artifactflow:bootstrap-admin',
    'create_user_password' => env('ARTIFACTFLOW_CREATE_USER_PASSWORD'),
    'reset_user_password' => env('ARTIFACTFLOW_RESET_PASSWORD'),
    'timezone' => 'UTC',
    'locale' => env('APP_LOCALE', 'en'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],
];
