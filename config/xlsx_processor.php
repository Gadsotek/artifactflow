<?php

declare(strict_types=1);

return [
    'enabled' => env('XLSX_PROCESSOR_ENABLED', false),
    'url' => env('XLSX_PROCESSOR_URL', ''),
    'socket_path' => env('XLSX_PROCESSOR_SOCKET_PATH'),
    'shared_secret' => env('XLSX_PROCESSOR_SHARED_SECRET', ''),
    'connect_timeout_seconds' => (int) env('XLSX_PROCESSOR_CONNECT_TIMEOUT_SECONDS', 2),
    'timeout_seconds' => (int) env('XLSX_PROCESSOR_TIMEOUT_SECONDS', 15),
];
