<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class DashboardDiscoverySummary
{
    /**
     * @param list<DashboardPopularTag> $popularTags
     */
    public function __construct(
        public int $draftPageCount,
        public int $deprecatedPageCount,
        public array $popularTags,
    ) {
    }
}
