<?php

declare(strict_types=1);

return [
    // ArtifactFlow is PostgreSQL-only: the schema uses tsvector/GIN search,
    // jsonb, partial/functional indexes, and composite deferrable FKs that
    // cannot migrate on SQLite. Default to pgsql so a contributor who skips the
    // .env path fails with a clear connection error, not an opaque
    // "type tsvector does not exist" mid-migration. The sqlite connection below
    // is retained only for framework tooling that expects the key to exist.
    'default' => env('DB_CONNECTION', 'pgsql'),
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'artifactflow'),
            'username' => env('DB_USERNAME', 'app_user'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'verify-full'),
            'sslrootcert' => env('DB_SSLROOTCERT') ?: null,
        ],
    ],
    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];
