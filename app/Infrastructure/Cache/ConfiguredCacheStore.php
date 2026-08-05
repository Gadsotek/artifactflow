<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

final readonly class ConfiguredCacheStore
{
    public function __construct(
        public string $name,
        public string $driver,
        public ?string $connection,
        public string $table,
        public ?string $lockConnection,
        public string $lockTable,
    ) {
    }

    public function sharesCountersAcrossReplicas(): bool
    {
        return in_array($this->driver, ['database', 'redis', 'memcached', 'dynamodb'], true);
    }

    public function isDatabase(): bool
    {
        return $this->driver === 'database';
    }
}
