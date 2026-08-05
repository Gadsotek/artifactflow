<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Infrastructure\Cache\ConfiguredCacheStore;
use App\Infrastructure\Cache\RateLimiterCacheConfiguration;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

final readonly class PruneExpiredRateLimitCache
{
    public const int DEFAULT_DELETE_CHUNK_SIZE = 1_000;

    public function __construct(
        private RateLimiterCacheConfiguration $rateLimiterCache,
    ) {
    }

    public function handle(bool $dryRun, int $chunkSize): ?PrunedRateLimitCache
    {
        $definitions = $this->rateLimiterCache->databaseStores();

        if ($definitions === []) {
            return null;
        }

        $expiresBefore = now()->getTimestamp();
        $entryCount = 0;
        $lockCount = 0;
        $prunedTables = [];

        foreach ($definitions as $definition) {
            $entryConnectionName = $definition->connection;
            $entryTable = $this->requiredTable($definition, $definition->table, 'table');
            $entryIdentity = $this->tableIdentity($entryConnectionName, $entryTable);

            if (!isset($prunedTables[$entryIdentity])) {
                $entryCount += $this->pruneTable(
                    $this->connection($entryConnectionName),
                    $entryTable,
                    $expiresBefore,
                    $dryRun,
                    $chunkSize,
                );
                $prunedTables[$entryIdentity] = true;
            }

            $lockConnectionName = $definition->lockConnection ?? $entryConnectionName;
            $lockTable = $this->requiredTable($definition, $definition->lockTable, 'lock_table');
            $lockIdentity = $this->tableIdentity($lockConnectionName, $lockTable);

            if (!isset($prunedTables[$lockIdentity])) {
                $lockCount += $this->pruneTable(
                    $this->connection($lockConnectionName),
                    $lockTable,
                    $expiresBefore,
                    $dryRun,
                    $chunkSize,
                );
                $prunedTables[$lockIdentity] = true;
            }
        }

        return new PrunedRateLimitCache($entryCount, $lockCount);
    }

    private function pruneTable(
        ConnectionInterface $connection,
        string $table,
        int $expiresBefore,
        bool $dryRun,
        int $chunkSize,
    ): int {
        $query = $connection->table($table)->where('expiration', '<=', $expiresBefore);

        if ($dryRun) {
            return $query->count();
        }

        $deleted = 0;
        $chunkSize = max(1, $chunkSize);
        $cursorExpiration = null;
        $cursorKey = null;

        do {
            $query = $connection->table($table)
                ->where('expiration', '<=', $expiresBefore);

            if ($cursorExpiration !== null) {
                $query->where(static function (Builder $cursor) use ($cursorExpiration, $cursorKey): void {
                    $cursor->where('expiration', '>', $cursorExpiration)
                        ->orWhere(static function (Builder $sameExpiration) use ($cursorExpiration, $cursorKey): void {
                            $sameExpiration->where('expiration', '=', $cursorExpiration)
                                ->where('key', '>', $cursorKey);
                        });
                });
            }

            $rows = $query
                ->orderBy('expiration')
                ->orderBy('key')
                ->limit($chunkSize)
                ->get(['expiration', 'key']);

            if ($rows->isEmpty()) {
                break;
            }

            $keys = [];

            foreach ($rows as $row) {
                $keys[] = $this->rowKey($row);
            }

            $last = $rows->last();

            $cursorExpiration = $this->rowExpiration($last);
            $cursorKey = $this->rowKey($last);

            $deleted += $connection->table($table)
                ->where('expiration', '<=', $expiresBefore)
                ->whereIn('key', $keys)
                ->delete();
        } while ($rows->count() === $chunkSize);

        return $deleted;
    }

    private function rowKey(stdClass $row): string
    {
        $key = $row->key ?? null;

        if (!is_string($key) || $key === '') {
            throw new RuntimeException('Database rate limiter cache returned an invalid key.');
        }

        return $key;
    }

    private function rowExpiration(stdClass $row): int
    {
        $expiration = $row->expiration ?? null;

        if (is_int($expiration)) {
            return $expiration;
        }

        if (is_string($expiration) && ctype_digit($expiration)) {
            return (int) $expiration;
        }

        throw new RuntimeException('Database rate limiter cache returned an invalid expiration.');
    }

    private function tableIdentity(?string $connection, string $table): string
    {
        $connectionName = $connection === null ? '' : trim($connection);

        if ($connectionName === '') {
            $connectionName = $this->stringConfig('database.default');
        }

        return $connectionName . "\0" . $table;
    }

    private function requiredTable(ConfiguredCacheStore $store, string $table, string $key): string
    {
        if ($table === '') {
            throw new RuntimeException(sprintf(
                "Database rate limiter cache store '%s' is missing its %s setting.",
                $store->name,
                $key,
            ));
        }

        return $table;
    }

    private function connection(?string $name): ConnectionInterface
    {
        return DB::connection($name !== null && trim($name) !== '' ? trim($name) : null);
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        return is_string($value) ? trim($value) : '';
    }
}
