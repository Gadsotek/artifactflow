<?php

declare(strict_types=1);

namespace App\Application\Events;

use Illuminate\Support\Facades\Log;

final class LogStoredDomainEventDispatch
{
    public function handle(StoredDomainEvent $event): void
    {
        Log::info('domain_event.dispatched', [
            'event_uid' => $event->uid,
            'event_type' => $event->eventType,
            'aggregate_type' => $event->aggregateType,
            'aggregate_uid' => $event->aggregateUid,
        ]);
    }
}
