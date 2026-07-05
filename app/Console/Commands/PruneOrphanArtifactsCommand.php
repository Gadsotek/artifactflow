<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\PageCatalog\PruneOrphanArtifacts;
use Illuminate\Console\Command;

final class PruneOrphanArtifactsCommand extends Command
{
    protected $signature = 'artifactflow:prune-orphan-artifacts {--delete} {--min-age-hours=24} {--json}';

    protected $description = 'Find (and, with --delete, remove) stored artifact files that no page version references.';

    public function handle(PruneOrphanArtifacts $pruneOrphanArtifacts): int
    {
        $minAgeHours = $this->parseMinAgeHours();

        if ($minAgeHours === null) {
            $this->line('Minimum age (--min-age-hours) must be a positive whole number of hours.');

            return 1;
        }

        $result = $pruneOrphanArtifacts->handle(
            delete: (bool) $this->option('delete'),
            minAgeSeconds: $minAgeHours * 3600,
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_THROW_ON_ERROR));

            return 0;
        }

        $this->info(sprintf(
            'Orphan artifact prune complete: scanned=%d, orphans=%d, deleted=%d, recent_skipped=%d, delete_requested=%s.',
            $result->filesScanned,
            $result->orphansFound,
            $result->orphansDeleted,
            $result->recentSkipped,
            $result->deleteRequested ? 'yes' : 'no',
        ));

        if (!$result->deleteRequested && $result->orphansFound > 0) {
            $this->line('Re-run with --delete to remove them.');
        }

        return 0;
    }

    /**
     * A zero or negative window is rejected, not clamped: it would disable the
     * in-flight-write safety window in PruneOrphanArtifacts and let --delete reap a
     * blob whose version row is still mid-commit. Callers must pass at least one hour.
     */
    private function parseMinAgeHours(): ?int
    {
        $option = $this->input->getOption('min-age-hours');

        if (is_int($option)) {
            return $option >= 1 ? $option : null;
        }

        if (is_string($option) && ctype_digit($option)) {
            $parsed = (int) $option;

            return $parsed >= 1 ? $parsed : null;
        }

        return null;
    }
}
