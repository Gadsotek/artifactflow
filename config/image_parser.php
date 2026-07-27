<?php

declare(strict_types=1);

return [
    'enabled' => env('IMAGE_PARSER_ENABLED', true),
    'url' => env('IMAGE_PARSER_URL', 'http://image-parser:8080'),
    'shared_secret' => env('IMAGE_PARSER_SHARED_SECRET', ''),
    'connect_timeout_seconds' => (int) env('IMAGE_PARSER_CONNECT_TIMEOUT_SECONDS', 2),
    'timeout_seconds' => (int) env('IMAGE_PARSER_TIMEOUT_SECONDS', 12),
    'user_pixel_budget_per_minute' => (int) env(
        'IMAGE_NORMALIZATION_USER_PIXEL_BUDGET_PER_MINUTE',
        64 * 1024 * 1024,
    ),
    'installation_pixel_budget_per_minute' => (int) env(
        'IMAGE_NORMALIZATION_INSTALLATION_PIXEL_BUDGET_PER_MINUTE',
        256 * 1024 * 1024,
    ),
];
