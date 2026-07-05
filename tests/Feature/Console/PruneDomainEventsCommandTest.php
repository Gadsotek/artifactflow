<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class PruneDomainEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_dispatched_events_are_pruned_and_recent_dispatched_events_are_kept(): void
    {
        config(['domain-events.retention_days' => 90]);
        $oldDispatched = $this->createEvent(
            occurredAt: Carbon::now()->subDays(91),
            dispatchedAt: Carbon::now()->subDays(91),
        );
        $recentDispatched = $this->createEvent(
            occurredAt: Carbon::now()->subDays(89),
            dispatchedAt: Carbon::now()->subDays(89),
        );

        $this->runConsoleCommand('artifactflow:prune-domain-events')
            ->expectsOutput('Pruned 1 dispatched domain event older than 90 days.')
            ->assertExitCode(0);

        $this->assertNull(DomainEvent::query()->find($oldDispatched->uid));
        $this->assertNotNull(DomainEvent::query()->find($recentDispatched->uid));
    }

    public function test_undispatched_events_are_kept_regardless_of_age(): void
    {
        $ancientPending = $this->createEvent(
            occurredAt: Carbon::now()->subDays(400),
            dispatchedAt: null,
        );

        $this->runConsoleCommand('artifactflow:prune-domain-events')
            ->expectsOutput('Pruned 0 dispatched domain events older than 90 days.')
            ->assertExitCode(0);

        $this->assertNotNull(DomainEvent::query()->find($ancientPending->uid));
    }

    public function test_failed_events_are_kept_regardless_of_age(): void
    {
        $ancientFailed = $this->createEvent(
            occurredAt: Carbon::now()->subDays(400),
            dispatchedAt: null,
            failedAt: Carbon::now()->subDays(400),
        );
        $ancientDispatchedThenFailed = $this->createEvent(
            occurredAt: Carbon::now()->subDays(400),
            dispatchedAt: Carbon::now()->subDays(400),
            failedAt: Carbon::now()->subDays(400),
        );

        $this->runConsoleCommand('artifactflow:prune-domain-events')
            ->expectsOutput('Pruned 0 dispatched domain events older than 90 days.')
            ->assertExitCode(0);

        $this->assertNotNull(DomainEvent::query()->find($ancientFailed->uid));
        $this->assertNotNull(DomainEvent::query()->find($ancientDispatchedThenFailed->uid));
    }

    public function test_audit_entries_referencing_pruned_events_survive_with_event_uid_intact(): void
    {
        $event = $this->createEvent(
            occurredAt: Carbon::now()->subDays(120),
            dispatchedAt: Carbon::now()->subDays(120),
        );
        $actor = $this->createUser('Audit Actor', 'prune-audit-actor@example.test');
        $auditEntry = AuditEntry::query()->create([
            'event_uid' => $event->uid,
            'actor_user_uid' => $actor->uid,
            'auditable_type' => 'page',
            'auditable_uid' => $event->aggregate_uid,
            'action' => 'page.created',
            'summary' => 'Page created.',
            'metadata' => ['workspace_uid' => (string) Str::ulid()],
            'occurred_at' => Carbon::now()->subDays(120),
        ]);

        $this->runConsoleCommand('artifactflow:prune-domain-events')
            ->expectsOutput('Pruned 1 dispatched domain event older than 90 days.')
            ->assertExitCode(0);

        $this->assertNull(DomainEvent::query()->find($event->uid));
        $survivingEntry = AuditEntry::query()->find($auditEntry->uid);
        $this->assertInstanceOf(AuditEntry::class, $survivingEntry);
        $this->assertSame($event->uid, $survivingEntry->event_uid);
        $this->assertSame('page.created', $survivingEntry->action);
    }

    public function test_dry_run_counts_prunable_events_without_deleting_anything(): void
    {
        $oldDispatched = $this->createEvent(
            occurredAt: Carbon::now()->subDays(120),
            dispatchedAt: Carbon::now()->subDays(120),
        );
        $ancientPending = $this->createEvent(
            occurredAt: Carbon::now()->subDays(400),
            dispatchedAt: null,
        );

        $this->runConsoleCommand('artifactflow:prune-domain-events --dry-run')
            ->expectsOutput('Would prune 1 dispatched domain event older than 90 days.')
            ->assertExitCode(0);

        $this->assertNotNull(DomainEvent::query()->find($oldDispatched->uid));
        $this->assertNotNull(DomainEvent::query()->find($ancientPending->uid));
        $this->assertSame(2, DomainEvent::query()->count());
    }

    public function test_days_option_overrides_the_configured_retention(): void
    {
        config(['domain-events.retention_days' => 90]);
        $dispatchedEightDaysAgo = $this->createEvent(
            occurredAt: Carbon::now()->subDays(8),
            dispatchedAt: Carbon::now()->subDays(8),
        );
        $dispatchedSixDaysAgo = $this->createEvent(
            occurredAt: Carbon::now()->subDays(6),
            dispatchedAt: Carbon::now()->subDays(6),
        );

        $this->runConsoleCommand('artifactflow:prune-domain-events --days=7')
            ->expectsOutput('Pruned 1 dispatched domain event older than 7 days.')
            ->assertExitCode(0);

        $this->assertNull(DomainEvent::query()->find($dispatchedEightDaysAgo->uid));
        $this->assertNotNull(DomainEvent::query()->find($dispatchedSixDaysAgo->uid));
    }

    public function test_default_retention_is_read_from_configuration(): void
    {
        config(['domain-events.retention_days' => 10]);
        $dispatchedElevenDaysAgo = $this->createEvent(
            occurredAt: Carbon::now()->subDays(11),
            dispatchedAt: Carbon::now()->subDays(11),
        );
        $dispatchedNineDaysAgo = $this->createEvent(
            occurredAt: Carbon::now()->subDays(9),
            dispatchedAt: Carbon::now()->subDays(9),
        );

        $this->runConsoleCommand('artifactflow:prune-domain-events')
            ->expectsOutput('Pruned 1 dispatched domain event older than 10 days.')
            ->assertExitCode(0);

        $this->assertNull(DomainEvent::query()->find($dispatchedElevenDaysAgo->uid));
        $this->assertNotNull(DomainEvent::query()->find($dispatchedNineDaysAgo->uid));
    }

    public function test_retention_below_seven_days_is_rejected_without_deleting_anything(): void
    {
        $oldDispatched = $this->createEvent(
            occurredAt: Carbon::now()->subDays(120),
            dispatchedAt: Carbon::now()->subDays(120),
        );

        $this->runConsoleCommand('artifactflow:prune-domain-events --days=6')
            ->expectsOutput('Domain event retention must be at least 7 days.')
            ->assertExitCode(1);

        $this->assertNotNull(DomainEvent::query()->find($oldDispatched->uid));
    }

    public function test_non_numeric_days_option_is_rejected_without_deleting_anything(): void
    {
        $oldDispatched = $this->createEvent(
            occurredAt: Carbon::now()->subDays(120),
            dispatchedAt: Carbon::now()->subDays(120),
        );

        $this->runConsoleCommand('artifactflow:prune-domain-events --days=soon')
            ->expectsOutput('Domain event retention must be at least 7 days.')
            ->assertExitCode(1);

        $this->assertNotNull(DomainEvent::query()->find($oldDispatched->uid));
    }

    public function test_prune_deletes_in_chunks_when_more_events_than_one_batch_exist(): void
    {
        config(['domain-events.retention_days' => 90]);
        for ($index = 0; $index < 3; $index++) {
            $this->createEvent(
                occurredAt: Carbon::now()->subDays(100 + $index),
                dispatchedAt: Carbon::now()->subDays(100 + $index),
            );
        }
        $keptPending = $this->createEvent(
            occurredAt: Carbon::now()->subDays(100),
            dispatchedAt: null,
        );

        $this->runConsoleCommand('artifactflow:prune-domain-events --chunk-size=2')
            ->expectsOutput('Pruned 3 dispatched domain events older than 90 days.')
            ->assertExitCode(0);

        $this->assertSame(1, DomainEvent::query()->count());
        $this->assertNotNull(DomainEvent::query()->find($keptPending->uid));
    }

    private function createEvent(
        Carbon $occurredAt,
        ?Carbon $dispatchedAt,
        ?Carbon $failedAt = null,
    ): DomainEvent {
        $aggregateUid = (string) Str::ulid();

        return DomainEvent::query()->create([
            'event_type' => 'page.created',
            'aggregate_type' => 'page',
            'aggregate_uid' => $aggregateUid,
            'payload' => [
                'page_uid' => $aggregateUid,
            ],
            'occurred_at' => $occurredAt,
            'dispatched_at' => $dispatchedAt,
            'failed_at' => $failedAt,
            'last_error' => $failedAt instanceof Carbon ? 'RuntimeException' : null,
        ]);
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
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
