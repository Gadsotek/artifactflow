<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Cache;

use App\Infrastructure\Cache\RateLimiterCacheConfiguration;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class RateLimiterCacheConfigurationTest extends TestCase
{
    public function test_it_resolves_whether_the_effective_limiter_store_shares_counters(): void
    {
        $config = $this->configuration();

        $this->assertTrue($config->sharesCountersAcrossReplicas());

        $config = $this->configuration([
            'cache.limiter' => 'redis_limiter',
        ]);

        $this->assertTrue($config->sharesCountersAcrossReplicas());

        $config = $this->configuration([
            'cache.limiter' => 'file',
        ]);

        $this->assertFalse($config->sharesCountersAcrossReplicas());
    }

    public function test_connection_aliases_do_not_substitute_for_distinct_database_tables(): void
    {
        $config = $this->configuration([
            'cache.stores.database_artifact_limiter.connection' => 'artifact_connection',
            'cache.stores.database_artifact_limiter.table' => 'rate_limit_cache',
        ]);

        $this->assertFalse($config->artifactStoreIsIsolated());

        $config = $this->configuration([
            'cache.stores.database_artifact_limiter.table' => 'artifact_rate_limit_cache',
        ]);

        $this->assertTrue($config->artifactStoreIsIsolated());
    }

    public function test_non_database_aliases_fail_closed_when_physical_isolation_cannot_be_proven(): void
    {
        foreach (['redis', 'memcached', 'dynamodb'] as $driver) {
            $config = $this->configuration([
                'cache.limiter' => 'application_limiter',
                'cache.app_limiter' => 'application_limiter',
                'cache.artifact_limiter' => 'artifact_limiter',
                'cache.stores.application_limiter' => [
                    'driver' => $driver,
                    'prefix' => 'application:',
                ],
                'cache.stores.artifact_limiter' => [
                    'driver' => $driver,
                    'prefix' => 'artifact:',
                ],
            ]);

            $this->assertFalse($config->artifactStoreIsIsolated(), $driver);
        }
    }

    public function test_malformed_store_definitions_fail_closed(): void
    {
        $config = $this->configuration([
            'cache.stores.database_limiter.driver' => ['database'],
        ]);

        $this->assertFalse($config->sharesCountersAcrossReplicas());
        $this->assertFalse($config->artifactStoreIsIsolated());

        $config = $this->configuration([
            'cache.stores.database_artifact_limiter.table' => ['artifact_rate_limit_cache'],
        ]);

        $this->assertFalse($config->artifactStoreIsIsolated());
    }

    public function test_database_stores_are_exposed_as_typed_configuration_for_pruning(): void
    {
        $stores = $this->configuration()->databaseStores();

        $this->assertCount(2, $stores);
        $this->assertSame('database_limiter', $stores[0]->name);
        $this->assertSame('rate_limit_cache', $stores[0]->table);
        $this->assertSame('rate_limit_cache_locks', $stores[0]->lockTable);
        $this->assertSame('database_artifact_limiter', $stores[1]->name);
        $this->assertSame('artifact_rate_limit_cache', $stores[1]->table);
        $this->assertSame('artifact_rate_limit_cache_locks', $stores[1]->lockTable);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function configuration(array $overrides = []): RateLimiterCacheConfiguration
    {
        $config = new Repository([
            'app' => [
                'runtime_role' => 'app',
            ],
            'cache' => [
                'default' => 'database',
                'limiter' => 'database_limiter',
                'app_limiter' => 'database_limiter',
                'artifact_limiter' => 'database_artifact_limiter',
                'stores' => [
                    'database' => [
                        'driver' => 'database',
                        'table' => 'cache',
                        'lock_table' => 'cache_locks',
                    ],
                    'database_limiter' => [
                        'driver' => 'database',
                        'connection' => 'pgsql',
                        'table' => 'rate_limit_cache',
                        'lock_connection' => 'pgsql',
                        'lock_table' => 'rate_limit_cache_locks',
                    ],
                    'database_artifact_limiter' => [
                        'driver' => 'database',
                        'connection' => 'pgsql',
                        'table' => 'artifact_rate_limit_cache',
                        'lock_connection' => 'pgsql',
                        'lock_table' => 'artifact_rate_limit_cache_locks',
                    ],
                    'redis_limiter' => [
                        'driver' => 'redis',
                    ],
                    'file' => [
                        'driver' => 'file',
                    ],
                ],
            ],
        ]);

        foreach ($overrides as $key => $value) {
            $config->set($key, $value);
        }

        return new RateLimiterCacheConfiguration($config);
    }
}
