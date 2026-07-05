<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class RequeueDomainEventCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_requeue_reports_an_unknown_event_uid(): void
    {
        $this->runConsoleCommand('artifactflow:requeue-domain-event', [
            'uid' => 'does-not-exist',
        ])
            ->expectsOutputToContain('Failed domain event not found.')
            ->assertExitCode(1);
    }

    public function test_requeue_refuses_a_pending_event_that_has_not_failed(): void
    {
        $event = $this->createEvent();

        $this->runConsoleCommand('artifactflow:requeue-domain-event', [
            'uid' => $event->uid,
        ])
            ->expectsOutputToContain('Failed domain event not found.')
            ->assertExitCode(1);

        $this->assertNull($event->refresh()->failed_at);
    }

    private function createEvent(): DomainEvent
    {
        $aggregateUid = (string) Str::ulid();

        return DomainEvent::query()->create([
            'event_type' => 'page.created',
            'aggregate_type' => 'page',
            'aggregate_uid' => $aggregateUid,
            'payload' => [
                'page_uid' => $aggregateUid,
            ],
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runConsoleCommand(string $command, array $parameters = []): PendingCommand
    {
        $pendingCommand = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pendingCommand);

        return $pendingCommand;
    }
}
