<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\DomainRuleViolation;
use App\Models\DomainEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class DispatchDomainEvents
{
    public function __construct(
        private Dispatcher $dispatcher,
    ) {
    }

    public function handle(int $limit): int
    {
        if ($limit < 1) {
            throw new DomainRuleViolation('Domain event dispatch limit must be positive.');
        }

        $dispatchedCount = 0;
        $processedCount = 0;

        while ($processedCount < $limit) {
            $result = DB::transaction(function (): string {
                $event = DomainEvent::query()
                    ->whereNull('dispatched_at')
                    ->whereNull('failed_at')
                    ->orderBy('occurred_at')
                    ->orderBy('uid')
                    ->lockForUpdate()
                    ->first();

                if (!$event instanceof DomainEvent) {
                    return 'none';
                }

                try {
                    $this->dispatcher->dispatch(new StoredDomainEvent(
                        uid: $event->uid,
                        eventType: $event->event_type,
                        aggregateType: $event->aggregate_type,
                        aggregateUid: $event->aggregate_uid,
                        payload: $event->payload,
                        occurredAt: $event->occurred_at,
                    ));
                } catch (Throwable $exception) {
                    Log::warning('domain_event.dispatch_failed', [
                        'event_uid' => $event->uid,
                        'event_type' => $event->event_type,
                        'aggregate_type' => $event->aggregate_type,
                        'aggregate_uid' => $event->aggregate_uid,
                        'exception' => $exception::class,
                    ]);

                    $event->forceFill([
                        'dispatch_attempts' => $event->dispatch_attempts + 1,
                        'failed_at' => Carbon::now(),
                        'last_error' => $exception::class,
                    ])->save();

                    return 'failed';
                }

                $event->forceFill([
                    'dispatch_attempts' => $event->dispatch_attempts + 1,
                    'dispatched_at' => Carbon::now(),
                ])->save();

                return 'dispatched';
            });

            if ($result === 'none') {
                break;
            }

            $processedCount++;

            if ($result === 'dispatched') {
                $dispatchedCount++;
            }
        }

        return $dispatchedCount;
    }
}
