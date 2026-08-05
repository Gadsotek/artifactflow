<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Typed boundary around Laravel's array-shaped cache configuration. Consumers
 * work with ConfiguredCacheStore objects and never interpret raw definitions.
 */
final readonly class RateLimiterCacheConfiguration
{
    public function __construct(
        private Repository $config,
    ) {
    }

    public function effectiveStoreName(): string
    {
        $limiterStore = $this->configuredString('cache.limiter');

        return $limiterStore !== '' ? $limiterStore : $this->configuredString('cache.default');
    }

    public function sharesCountersAcrossReplicas(): bool
    {
        return $this->store($this->effectiveStoreName())?->sharesCountersAcrossReplicas() ?? false;
    }

    /**
     * ArtifactFlow can currently prove the cross-runtime credential boundary
     * only for separately granted database tables. Alternative backend ACLs
     * live outside Laravel's cache configuration and therefore fail closed.
     */
    public function artifactStoreIsIsolated(): bool
    {
        $runtimeRole = $this->configuredString('app.runtime_role');
        $effectiveStoreName = $this->effectiveStoreName();
        $applicationStoreName = $this->configuredString('cache.app_limiter');

        if ($applicationStoreName === '') {
            $applicationStoreName = $runtimeRole === 'artifact-host'
                ? $this->configuredString('cache.default')
                : $effectiveStoreName;
        }

        $artifactStoreName = $this->configuredString('cache.artifact_limiter');
        $expectedStoreName = $runtimeRole === 'artifact-host' ? $artifactStoreName : $applicationStoreName;

        if (
            $effectiveStoreName === ''
            || $applicationStoreName === ''
            || $artifactStoreName === ''
            || $effectiveStoreName !== $expectedStoreName
            || $applicationStoreName === $artifactStoreName
        ) {
            return false;
        }

        $applicationStore = $this->store($applicationStoreName);
        $artifactStore = $this->store($artifactStoreName);

        if (
            $applicationStore === null
            || $artifactStore === null
            || !$applicationStore->sharesCountersAcrossReplicas()
            || !$artifactStore->sharesCountersAcrossReplicas()
            || !$applicationStore->isDatabase()
            || !$artifactStore->isDatabase()
        ) {
            return false;
        }

        return $applicationStore->table !== ''
            && $artifactStore->table !== ''
            && strcasecmp($applicationStore->table, $artifactStore->table) !== 0;
    }

    /**
     * @return list<ConfiguredCacheStore>
     */
    public function databaseStores(): array
    {
        $applicationStoreName = $this->configuredString('cache.app_limiter');

        if ($applicationStoreName === '') {
            $applicationStoreName = $this->effectiveStoreName();
        }

        $storeNames = array_values(array_unique(array_filter([
            $applicationStoreName,
            $this->configuredString('cache.artifact_limiter'),
        ], static fn (string $store): bool => $store !== '')));
        $stores = [];

        foreach ($storeNames as $storeName) {
            $store = $this->store($storeName);

            if ($store?->isDatabase() === true) {
                $stores[] = $store;
            }
        }

        return $stores;
    }

    private function store(string $name): ?ConfiguredCacheStore
    {
        if ($name === '') {
            return null;
        }

        $definition = $this->config->get('cache.stores.' . $name);

        if (!is_array($definition)) {
            return null;
        }

        try {
            $driver = strtolower(trim(Arr::string($definition, 'driver', '')));
            $table = trim(Arr::string($definition, 'table', ''));
            $lockTable = trim(Arr::string($definition, 'lock_table', ''));
            $connection = $this->optionalString($definition, 'connection');
            $lockConnection = $this->optionalString($definition, 'lock_connection');
        } catch (InvalidArgumentException) {
            return null;
        }

        return new ConfiguredCacheStore(
            $name,
            $driver,
            $connection,
            $table,
            $lockConnection,
            $lockTable,
        );
    }

    /**
     * @param array<array-key, mixed> $definition
     */
    private function optionalString(array $definition, string $key): ?string
    {
        $value = Arr::get($definition, $key);

        if ($value === null) {
            return null;
        }

        $value = trim(Arr::string($definition, $key));

        return $value !== '' ? $value : null;
    }

    private function configuredString(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? trim($value) : '';
    }
}
