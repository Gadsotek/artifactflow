<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\DomainRuleViolation;
use App\Models\DomainEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Prunes dispatched events from the durable domain-event journal after the
 * retention window. Undispatched and failed (quarantined) events are never
 * pruned so the outbox dispatcher and artifactflow:requeue-domain-event keep
 * working. Audit entries are retained forever; audit_entries.event_uid is a
 * deliberate soft reference into this journal.
 */
final readonly class PruneDomainEvents
{
    public const int MINIMUM_RETENTION_DAYS = 7;
    public const int DEFAULT_DELETE_CHUNK_SIZE = 1000;

    public function handle(
        int $retentionDays,
        bool $dryRun = false,
        int $chunkSize = self::DEFAULT_DELETE_CHUNK_SIZE,
    ): int {
        if ($retentionDays < self::MINIMUM_RETENTION_DAYS) {
            throw new DomainRuleViolation('Domain event retention must be at least 7 days.');
        }

        if ($chunkSize < 1) {
            throw new DomainRuleViolation('Domain event prune chunk size must be positive.');
        }

        $cutoff = Carbon::now()->subDays($retentionDays);

        if ($dryRun) {
            return $this->prunableEvents($cutoff)->count();
        }

        $prunedCount = 0;

        do {
            // Chunked deletes keep each statement short so pruning never holds
            // long locks against the journal while writes keep appending.
            $deletedCount = $this->prunableEvents($cutoff)
                ->limit($chunkSize)
                ->toBase()
                ->delete();
            $prunedCount += $deletedCount;
        } while ($deletedCount === $chunkSize);

        return $prunedCount;
    }

    /**
     * @return Builder<DomainEvent>
     */
    private function prunableEvents(Carbon $cutoff): Builder
    {
        return DomainEvent::query()
            ->whereNotNull('dispatched_at')
            ->whereNull('failed_at')
            ->where('occurred_at', '<', $cutoff);
    }
}
