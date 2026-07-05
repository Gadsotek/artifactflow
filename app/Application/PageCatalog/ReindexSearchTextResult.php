<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class ReindexSearchTextResult
{
    public function __construct(
        public int $pagesProcessed,
        public int $versionsExamined,
        public int $versionsChanged,
        public int $versionsSkipped,
        public bool $dryRun,
    ) {
    }
}
