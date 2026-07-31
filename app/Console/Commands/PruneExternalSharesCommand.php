<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\ExternalSharing\PruneExternalShares;
use Illuminate\Console\Command;

final class PruneExternalSharesCommand extends Command
{
    protected $signature = 'artifactflow:prune-external-shares {--dry-run} {--chunk-size=}';

    protected $description = 'Prune stale anonymous sessions and terminal external shares after their retention windows.';

    public function handle(PruneExternalShares $pruner): int
    {
        $result = $pruner->handle(
            sessionRetentionHours: $this->configInt(
                'external_sharing.session_retention_hours',
                24,
            ),
            shareRetentionDays: $this->configInt(
                'external_sharing.terminal_share_retention_days',
                90,
            ),
            dryRun: (bool) $this->option('dry-run'),
            chunkSize: $this->chunkSize(),
        );
        $dryRun = (bool) $this->option('dry-run');
        $this->info(sprintf(
            $dryRun
                ? 'Would prune %d stale external-share %s and %d terminal external %s.'
                : 'Pruned %d stale external-share %s and %d terminal external %s.',
            $result->sessionCount,
            $result->sessionCount === 1 ? 'session' : 'sessions',
            $result->shareCount,
            $result->shareCount === 1 ? 'share' : 'shares',
        ));

        return self::SUCCESS;
    }

    private function chunkSize(): int
    {
        $value = $this->option('chunk-size');

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return PruneExternalShares::DEFAULT_DELETE_CHUNK_SIZE;
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : $default;
    }
}
