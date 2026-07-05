<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Events\PruneDomainEvents;
use App\Domain\DomainRuleViolation;
use Illuminate\Console\Command;

final class PruneDomainEventsCommand extends Command
{
    protected $signature = 'artifactflow:prune-domain-events {--days=} {--dry-run} {--chunk-size=}';

    protected $description = 'Prune dispatched durable domain events older than the retention window; failed and pending events are kept.';

    public function handle(PruneDomainEvents $pruneDomainEvents): int
    {
        $days = $this->integerOption('days', 'domain-events.retention_days');
        $chunkSize = $this->integerOption('chunk-size', null, PruneDomainEvents::DEFAULT_DELETE_CHUNK_SIZE);
        $dryRun = (bool) $this->option('dry-run');

        try {
            $prunedCount = $pruneDomainEvents->handle(
                retentionDays: $days,
                dryRun: $dryRun,
                chunkSize: $chunkSize,
            );
        } catch (DomainRuleViolation $exception) {
            $this->line($exception->getMessage());

            return 1;
        }

        $noun = $prunedCount === 1 ? 'event' : 'events';
        $this->info(sprintf(
            $dryRun
                ? 'Would prune %d dispatched domain %s older than %d days.'
                : 'Pruned %d dispatched domain %s older than %d days.',
            $prunedCount,
            $noun,
            $days,
        ));

        return 0;
    }

    private function integerOption(string $option, ?string $configKey, int $fallback = 0): int
    {
        $value = $this->input->getOption($option);

        if (($value === null || $value === '') && $configKey !== null) {
            $value = config($configKey);
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        if ($value === null || $value === '') {
            return $fallback;
        }

        return 0;
    }
}
