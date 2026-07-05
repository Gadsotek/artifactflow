<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Domain\Events\DomainEventType;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use Illuminate\Support\Carbon;

final class AuditLogger
{
    /**
     * @param array<string, bool|int|string|null> $metadata
     */
    public function record(
        DomainEvent $event,
        ?string $actorUserUid,
        string $auditableType,
        string $auditableUid,
        DomainEventType $action,
        string $summary,
        array $metadata = [],
    ): AuditEntry {
        return AuditEntry::query()->create([
            'event_uid' => $event->uid,
            'aggregate_type' => $event->aggregate_type,
            'aggregate_uid' => $event->aggregate_uid,
            'actor_user_uid' => $actorUserUid,
            'auditable_type' => $auditableType,
            'auditable_uid' => $auditableUid,
            'action' => $action->value,
            'summary' => $summary,
            'metadata' => $metadata,
            'occurred_at' => Carbon::now(),
        ]);
    }
}
