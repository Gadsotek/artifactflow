<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Events\DispatchDomainEvents;
use App\Domain\DomainRuleViolation;
use Illuminate\Console\Command;

final class DispatchDomainEventsCommand extends Command
{
    protected $signature = 'artifactflow:dispatch-domain-events {--limit=}';

    protected $description = 'Dispatch pending durable domain events from the transactional outbox.';

    public function handle(DispatchDomainEvents $dispatchDomainEvents): int
    {
        $limitOption = $this->input->getOption('limit');
        $configuredLimit = config('domain-events.dispatch_batch_size');
        $limit = (is_string($limitOption) && $limitOption !== '') || is_int($limitOption)
            ? $limitOption
            : $configuredLimit;

        if (is_string($limit) && ctype_digit($limit)) {
            $limit = (int) $limit;
        }

        if (!is_int($limit) || $limit < 1) {
            throw new DomainRuleViolation('Domain event dispatch limit must be positive.');
        }

        $dispatchedCount = $dispatchDomainEvents->handle($limit);
        $noun = $dispatchedCount === 1 ? 'event' : 'events';

        $this->info(sprintf('Dispatched %d domain %s.', $dispatchedCount, $noun));

        return 0;
    }
}
