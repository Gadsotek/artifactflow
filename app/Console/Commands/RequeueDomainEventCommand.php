<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DomainEvent;
use Illuminate\Console\Command;

final class RequeueDomainEventCommand extends Command
{
    protected $signature = 'artifactflow:requeue-domain-event {uid}';

    protected $description = 'Clear quarantine on one failed durable domain event so it can be replayed.';

    public function handle(): int
    {
        $uid = $this->argument('uid');

        $event = DomainEvent::query()
            ->whereKey($uid)
            ->whereNull('dispatched_at')
            ->whereNotNull('failed_at')
            ->first();

        if (!$event instanceof DomainEvent) {
            $this->line('Failed domain event not found.');

            return 1;
        }

        $event->forceFill([
            'failed_at' => null,
            'last_error' => null,
        ])->save();

        $this->info('Requeued failed domain event.');

        return 0;
    }
}
