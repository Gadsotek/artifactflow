<?php

declare(strict_types=1);

return [
    'enabled' => env('PDF_PROCESSOR_ENABLED', false),
    'url' => env('PDF_PROCESSOR_URL', 'http://pdf-processor:8080'),
    'shared_secret' => env('PDF_PROCESSOR_SHARED_SECRET', ''),
    'connect_timeout_seconds' => (int) env('PDF_PROCESSOR_CONNECT_TIMEOUT_SECONDS', 2),
    'timeout_seconds' => (int) env('PDF_PROCESSOR_TIMEOUT_SECONDS', 15),
];
