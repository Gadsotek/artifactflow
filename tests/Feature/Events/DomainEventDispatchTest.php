<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\Events\DispatchDomainEvents;
use App\Application\Events\StoredDomainEvent;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class DomainEventDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_domain_events_are_dispatched_in_order_and_marked_after_success(): void
    {
        $first = $this->createEvent('page.created');
        $second = $this->createEvent('page.version.created');
        Event::fake([StoredDomainEvent::class]);

        $dispatchedCount = app(DispatchDomainEvents::class)->handle(10);

        $this->assertSame(2, $dispatchedCount);
        $this->assertNotNull($first->refresh()->dispatched_at);
        $this->assertNotNull($second->refresh()->dispatched_at);

        Event::assertDispatched(StoredDomainEvent::class, static function (StoredDomainEvent $event) use ($first): bool {
            return $event->uid === $first->uid
                && $event->eventType === 'page.created'
                && $event->aggregateType === 'page'
                && $event->aggregateUid === $first->aggregate_uid
                && $event->payload['page_uid'] === $first->aggregate_uid;
        });

        $dispatchedUids = [];
        Event::assertDispatched(StoredDomainEvent::class, static function (StoredDomainEvent $event) use (&$dispatchedUids): bool {
            $dispatchedUids[] = $event->uid;

            return true;
        });
        $this->assertSame([$first->uid, $second->uid], $dispatchedUids);
    }

    public function test_pending_domain_event_dispatch_invokes_registered_logging_listener_without_event_fake(): void
    {
        $event = $this->createEvent('page.created');

        Log::shouldReceive('info')
            ->once()
            ->with('domain_event.dispatched', Mockery::on(static function (array $context) use ($event): bool {
                return $context['event_uid'] === $event->uid
                    && $context['event_type'] === 'page.created'
                    && $context['aggregate_type'] === 'page'
                    && $context['aggregate_uid'] === $event->aggregate_uid
                    && !array_key_exists('payload', $context);
            }));

        $dispatchedCount = app(DispatchDomainEvents::class)->handle(1);

        $this->assertSame(1, $dispatchedCount);
        $this->assertNotNull($event->refresh()->dispatched_at);
    }

    public function test_failed_domain_event_dispatch_is_quarantined_without_blocking_later_events(): void
    {
        $poison = $this->createEvent('page.created');
        $later = $this->createEvent('page.version.created');

        Event::listen(StoredDomainEvent::class, static function (StoredDomainEvent $event) use ($poison): void {
            if ($event->uid === $poison->uid) {
                throw new RuntimeException('Forced domain listener failure with private payload.');
            }
        });

        try {
            $dispatchedCount = app(DispatchDomainEvents::class)->handle(10);
        } finally {
            Event::forget(StoredDomainEvent::class);
        }

        $this->assertSame(1, $dispatchedCount);
        $this->assertNull($poison->refresh()->dispatched_at);
        $this->assertSame(1, $poison->dispatch_attempts);
        $this->assertNotNull($poison->failed_at);
        $this->assertSame(RuntimeException::class, $poison->last_error);
        $this->assertStringNotContainsString('private payload', (string) $poison->last_error);
        $this->assertNotNull($later->refresh()->dispatched_at);
        $this->assertSame(1, $later->dispatch_attempts);
    }

    public function test_console_dispatch_uses_a_bounded_batch_without_exposing_payloads(): void
    {
        $this->createEvent('page.created');
        $this->createEvent('page.version.created');
        Event::fake([StoredDomainEvent::class]);

        $exitCode = Artisan::call('artifactflow:dispatch-domain-events', ['--limit' => 1]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dispatched 1 domain event.', $output);
        $this->assertStringNotContainsString('page_uid', $output);
        $this->assertSame(1, DomainEvent::query()->whereNotNull('dispatched_at')->count());
        $this->assertSame(1, DomainEvent::query()->whereNull('dispatched_at')->count());
    }

    public function test_failed_domain_event_can_be_requeued_for_replay_without_exposing_payloads(): void
    {
        $poison = $this->createEvent('page.created');

        Event::listen(StoredDomainEvent::class, static function (StoredDomainEvent $event) use ($poison): void {
            if ($event->uid === $poison->uid) {
                throw new RuntimeException('Forced failure with private payload.');
            }
        });

        try {
            app(DispatchDomainEvents::class)->handle(1);
        } finally {
            Event::forget(StoredDomainEvent::class);
        }

        $this->assertNotNull($poison->refresh()->failed_at);
        $this->assertSame(RuntimeException::class, $poison->last_error);

        $exitCode = Artisan::call('artifactflow:requeue-domain-event', ['uid' => $poison->uid]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Requeued failed domain event.', $output);
        $this->assertStringNotContainsString('page_uid', $output);
        $this->assertNull($poison->refresh()->failed_at);
        $this->assertNull($poison->last_error);

        Event::fake([StoredDomainEvent::class]);
        $this->assertSame(1, app(DispatchDomainEvents::class)->handle(1));
        $this->assertNotNull($poison->refresh()->dispatched_at);
    }

    private function createEvent(string $eventType): DomainEvent
    {
        $aggregateUid = (string) Str::ulid();

        return DomainEvent::query()->create([
            'event_type' => $eventType,
            'aggregate_type' => 'page',
            'aggregate_uid' => $aggregateUid,
            'payload' => [
                'page_uid' => $aggregateUid,
            ],
            'occurred_at' => now(),
        ]);
    }
}
