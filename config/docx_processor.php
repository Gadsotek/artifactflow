<?php

declare(strict_types=1);

return [
    'enabled' => env('DOCX_PROCESSOR_ENABLED', false),
    'url' => env('DOCX_PROCESSOR_URL', ''),
    'socket_path' => env('DOCX_PROCESSOR_SOCKET_PATH'),
    'shared_secret' => env('DOCX_PROCESSOR_SHARED_SECRET', ''),
    'connect_timeout_seconds' => (int) env('DOCX_PROCESSOR_CONNECT_TIMEOUT_SECONDS', 2),
    'timeout_seconds' => (int) env('DOCX_PROCESSOR_TIMEOUT_SECONDS', 35),
];
