<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Administration\PruneExpiredRateLimitCache;
use Illuminate\Console\Command;

final class PruneRateLimitCacheCommand extends Command
{
    protected $signature = 'artifactflow:prune-rate-limit-cache {--dry-run} {--chunk-size=}';

    protected $description = 'Prune expired rows from the database-backed application and artifact rate-limiters.';

    public function handle(PruneExpiredRateLimitCache $pruner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $pruner->handle($dryRun, $this->chunkSize());

        if ($result === null) {
            $this->info('Rate limiter store is not database-backed; nothing to prune.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            $dryRun
                ? 'Would prune %d expired rate-limit cache %s and %d expired cache %s.'
                : 'Pruned %d expired rate-limit cache %s and %d expired cache %s.',
            $result->entryCount,
            $result->entryCount === 1 ? 'entry' : 'entries',
            $result->lockCount,
            $result->lockCount === 1 ? 'lock' : 'locks',
        ));

        return self::SUCCESS;
    }

    private function chunkSize(): int
    {
        $value = $this->option('chunk-size');

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return PruneExpiredRateLimitCache::DEFAULT_DELETE_CHUNK_SIZE;
    }
}
