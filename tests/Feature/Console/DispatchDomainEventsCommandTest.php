<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Application\Events\StoredDomainEvent;
use App\Domain\DomainRuleViolation;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DispatchDomainEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_falls_back_to_the_configured_batch_size_without_a_limit_option(): void
    {
        config(['domain-events.dispatch_batch_size' => 1]);
        $this->createEvent('page.created');
        $this->createEvent('page.version.created');
        Event::fake([StoredDomainEvent::class]);

        $exitCode = Artisan::call('artifactflow:dispatch-domain-events');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dispatched 1 domain event.', $output);
        $this->assertSame(1, DomainEvent::query()->whereNotNull('dispatched_at')->count());
    }

    public function test_dispatch_accepts_a_numeric_string_limit_option(): void
    {
        $this->createEvent('page.created');
        $this->createEvent('page.version.created');
        Event::fake([StoredDomainEvent::class]);

        $exitCode = Artisan::call('artifactflow:dispatch-domain-events', [
            '--limit' => '2',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dispatched 2 domain events.', $output);
        $this->assertSame(2, DomainEvent::query()->whereNotNull('dispatched_at')->count());
    }

    public function test_dispatch_rejects_a_non_positive_limit(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Domain event dispatch limit must be positive.');

        Artisan::call('artifactflow:dispatch-domain-events', [
            '--limit' => '0',
        ]);
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
