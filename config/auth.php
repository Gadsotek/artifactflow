<?php

declare(strict_types=1);

return [
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'mcp' => [
            'driver' => 'mcp-token',
            'provider' => 'users',
        ],
    ],
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', App\Models\User::class),
        ],
    ],
    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TABLE', 'password_reset_tokens'),
            'expire' => (int) env('AUTH_PASSWORD_RESET_EXPIRE_MINUTES', 60),
            'throttle' => (int) env('AUTH_PASSWORD_RESET_THROTTLE_SECONDS', 60),
        ],
    ],
    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 900),
    'admin_password_timeout' => (int) env('AUTH_ADMIN_PASSWORD_TIMEOUT', 900),
    'two_factor_enrollment_password_timeout' => (int) env('TWO_FACTOR_ENROLLMENT_PASSWORD_TIMEOUT_SECONDS', 180),
    'two_factor_challenge_timeout' => (int) env('TWO_FACTOR_CHALLENGE_TIMEOUT_SECONDS', 300),
    'two_factor_drift_window' => (int) env('TWO_FACTOR_DRIFT_WINDOW', 1),
    'two_factor_trusted_device_days' => (int) env('TWO_FACTOR_TRUSTED_DEVICE_DAYS', 30),
    // Non-secret: a deliberate dummy bcrypt hash used only to equalize login
    // timing for unknown emails (never a real credential; safe to commit).
    'dummy_password_hash' => env(
        'AUTH_DUMMY_PASSWORD_HASH',
        '$2y$12$xm0UA0D2OPiZ6/nnQh8xgejBhHl4A5jjwewkvxe9iCf7uZYBYxgBe',
    ),
];
