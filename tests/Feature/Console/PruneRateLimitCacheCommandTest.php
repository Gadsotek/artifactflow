<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class PruneRateLimitCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.limiter' => 'database_limiter',
            'cache.app_limiter' => 'database_limiter',
            'cache.artifact_limiter' => 'database_artifact_limiter',
            'cache.stores.database_limiter.driver' => 'database',
            'cache.stores.database_limiter.connection' => config('database.default'),
            'cache.stores.database_limiter.table' => 'rate_limit_cache',
            'cache.stores.database_limiter.lock_connection' => config('database.default'),
            'cache.stores.database_limiter.lock_table' => 'rate_limit_cache_locks',
            'cache.stores.database_artifact_limiter.driver' => 'database',
            'cache.stores.database_artifact_limiter.connection' => config('database.default'),
            'cache.stores.database_artifact_limiter.table' => 'artifact_rate_limit_cache',
            'cache.stores.database_artifact_limiter.lock_connection' => config('database.default'),
            'cache.stores.database_artifact_limiter.lock_table' => 'artifact_rate_limit_cache_locks',
        ]);
    }

    public function test_it_prunes_both_runtime_limiter_stores_but_keeps_live_entries(): void
    {
        DB::table('rate_limit_cache')->insert([
            ['key' => 'expired-counter', 'value' => 'i:1;', 'expiration' => now()->subMinute()->timestamp],
            ['key' => 'live-counter', 'value' => 'i:1;', 'expiration' => now()->addMinute()->timestamp],
        ]);
        DB::table('rate_limit_cache_locks')->insert([
            ['key' => 'expired-lock', 'owner' => 'old', 'expiration' => now()->subMinute()->timestamp],
            ['key' => 'live-lock', 'owner' => 'current', 'expiration' => now()->addMinute()->timestamp],
        ]);
        DB::table('artifact_rate_limit_cache')->insert([
            ['key' => 'expired-artifact-counter', 'value' => 'i:1;', 'expiration' => now()->subMinute()->timestamp],
            ['key' => 'live-artifact-counter', 'value' => 'i:1;', 'expiration' => now()->addMinute()->timestamp],
        ]);
        DB::table('artifact_rate_limit_cache_locks')->insert([
            ['key' => 'expired-artifact-lock', 'owner' => 'old', 'expiration' => now()->subMinute()->timestamp],
            ['key' => 'live-artifact-lock', 'owner' => 'current', 'expiration' => now()->addMinute()->timestamp],
        ]);

        $this->runConsoleCommand('artifactflow:prune-rate-limit-cache')
            ->expectsOutputToContain('Pruned 2 expired rate-limit cache entries and 2 expired cache locks.')
            ->assertSuccessful();

        $this->assertFalse(DB::table('rate_limit_cache')->where('key', 'expired-counter')->exists());
        $this->assertTrue(DB::table('rate_limit_cache')->where('key', 'live-counter')->exists());
        $this->assertFalse(DB::table('rate_limit_cache_locks')->where('key', 'expired-lock')->exists());
        $this->assertTrue(DB::table('rate_limit_cache_locks')->where('key', 'live-lock')->exists());
        $this->assertFalse(DB::table('artifact_rate_limit_cache')->where('key', 'expired-artifact-counter')->exists());
        $this->assertTrue(DB::table('artifact_rate_limit_cache')->where('key', 'live-artifact-counter')->exists());
        $this->assertFalse(DB::table('artifact_rate_limit_cache_locks')->where('key', 'expired-artifact-lock')->exists());
        $this->assertTrue(DB::table('artifact_rate_limit_cache_locks')->where('key', 'live-artifact-lock')->exists());
    }

    public function test_pruning_uses_the_expiration_key_order_and_a_composite_cursor(): void
    {
        DB::table('rate_limit_cache')->insert([
            ['key' => 'z-first-expiration', 'value' => 'i:1;', 'expiration' => now()->subMinutes(2)->timestamp],
            ['key' => 'a-second-expiration', 'value' => 'i:1;', 'expiration' => now()->subMinute()->timestamp],
        ]);

        DB::enableQueryLog();

        $this->runConsoleCommand('artifactflow:prune-rate-limit-cache', ['--chunk-size' => '1'])
            ->assertSuccessful();

        $selects = array_values(array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'),
        ));
        $sql = implode("\n", array_column($selects, 'query'));

        $this->assertStringContainsString('order by "expiration" asc, "key" asc', strtolower($sql));
        $this->assertStringContainsString('"expiration" > ?', $sql);
        $this->assertStringContainsString('"expiration" = ?', $sql);
        $this->assertStringContainsString('"key" > ?', $sql);
    }

    public function test_dry_run_counts_without_deleting_and_non_database_stores_are_safe_no_ops(): void
    {
        DB::table('rate_limit_cache')->insert([
            'key' => 'expired-counter',
            'value' => 'i:1;',
            'expiration' => now()->subMinute()->timestamp,
        ]);

        $this->runConsoleCommand('artifactflow:prune-rate-limit-cache', ['--dry-run' => true])
            ->expectsOutputToContain('Would prune 1 expired rate-limit cache entry and 0 expired cache locks.')
            ->assertSuccessful();
        $this->assertTrue(DB::table('rate_limit_cache')->where('key', 'expired-counter')->exists());

        config([
            'cache.limiter' => 'array',
            'cache.app_limiter' => 'array',
            'cache.artifact_limiter' => 'array',
        ]);

        $this->runConsoleCommand('artifactflow:prune-rate-limit-cache')
            ->expectsOutputToContain('Rate limiter store is not database-backed; nothing to prune.')
            ->assertSuccessful();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runConsoleCommand(string $command, array $parameters = []): PendingCommand
    {
        $pendingCommand = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pendingCommand);

        return $pendingCommand;
    }
}
