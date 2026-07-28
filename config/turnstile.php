<?php

declare(strict_types=1);

$applicationHost = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST);

return [
    /*
    |--------------------------------------------------------------------------
    | Optional Cloudflare Turnstile authentication challenge
    |--------------------------------------------------------------------------
    |
    | Turnstile is disabled when both keys are absent and enabled when both are
    | present. Production boot validation rejects partial or unsafe settings.
    |
    */
    'site_key' => env('TURNSTILE_SITE_KEY'),
    'secret_key' => env('TURNSTILE_SECRET_KEY'),
    'expected_hostname' => env(
        'TURNSTILE_EXPECTED_HOSTNAME',
        is_string($applicationHost) ? $applicationHost : '',
    ),
    'connect_timeout_seconds' => env('TURNSTILE_CONNECT_TIMEOUT_SECONDS', 2),
    'timeout_seconds' => env('TURNSTILE_TIMEOUT_SECONDS', 5),
];
