<?php

declare(strict_types=1);

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
        ],
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL') . '/storage',
            'visibility' => 'public',
            'throw' => false,
        ],
        'artifacts' => [
            'driver' => 'local',
            'root' => env('ARTIFACT_STORAGE_ROOT', storage_path('app/private_artifacts')),
            'visibility' => 'private',
            'throw' => true,
        ],
    ],
    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
