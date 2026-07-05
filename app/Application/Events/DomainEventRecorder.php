<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\Events\DomainEventType;
use App\Models\DomainEvent;
use Illuminate\Support\Carbon;

final class DomainEventRecorder
{
    /**
     * @param array<string, bool|int|string|null> $payload
     */
    public function record(
        DomainEventType $eventType,
        string $aggregateType,
        string $aggregateUid,
        array $payload,
    ): DomainEvent {
        return DomainEvent::query()->create([
            'event_type' => $eventType->value,
            'aggregate_type' => $aggregateType,
            'aggregate_uid' => $aggregateUid,
            'payload' => $payload,
            'occurred_at' => Carbon::now(),
        ]);
    }
}
