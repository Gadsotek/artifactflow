<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\PageCatalog\ReindexSearchText;
use App\Domain\DomainRuleViolation;
use Illuminate\Console\Command;

final class ReindexSearchCommand extends Command
{
    protected $signature = 'artifactflow:reindex-search {--all-versions} {--page=} {--dry-run} {--batch-size=}';

    protected $description = 'Re-extract search text from stored artifact files and rebuild the page search vector.';

    public function handle(ReindexSearchText $reindexSearchText): int
    {
        $batchSizeOption = $this->input->getOption('batch-size');
        $pageOption = $this->option('page');

        try {
            $batchSize = 100;
            if (is_string($batchSizeOption) && $batchSizeOption !== '') {
                $batchSize = ctype_digit($batchSizeOption) ? (int) $batchSizeOption : 0;
            } elseif (is_int($batchSizeOption)) {
                $batchSize = $batchSizeOption;
            }

            if ($batchSize < 1) {
                throw new DomainRuleViolation('Search reindex batch size must be positive.');
            }

            $result = $reindexSearchText->handle(
                pageUid: is_string($pageOption) ? $pageOption : null,
                allVersions: (bool) $this->option('all-versions'),
                dryRun: (bool) $this->option('dry-run'),
                batchSize: $batchSize,
            );
        } catch (DomainRuleViolation $exception) {
            $this->line($exception->getMessage());

            return 1;
        }

        $this->info(sprintf(
            'Search reindex complete: pages=%d, versions=%d, changed=%d, skipped=%d, dry_run=%s.',
            $result->pagesProcessed,
            $result->versionsExamined,
            $result->versionsChanged,
            $result->versionsSkipped,
            $result->dryRun ? 'yes' : 'no',
        ));

        return 0;
    }
}
