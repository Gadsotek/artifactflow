<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageStatus;
use App\Models\Page;

final class SummarizeDashboardDiscovery
{
    private const int POPULAR_TAG_LIMIT = 8;

    /**
     * @param list<Page> $pages
     */
    public function handle(array $pages): DashboardDiscoverySummary
    {
        $draftPageCount = 0;
        $deprecatedPageCount = 0;
        $tagCounts = [];

        foreach ($pages as $page) {
            if ($page->status === PageStatus::Draft) {
                ++$draftPageCount;
            }

            if ($page->status === PageStatus::Deprecated) {
                ++$deprecatedPageCount;
            }

            foreach ($page->tags as $tag) {
                $tagCounts[$tag->name] = ($tagCounts[$tag->name] ?? 0) + 1;
            }
        }

        $tagNames = array_keys($tagCounts);
        usort(
            $tagNames,
            static fn (string $left, string $right): int => $tagCounts[$right] <=> $tagCounts[$left]
                ?: strcasecmp($left, $right),
        );
        $popularTags = [];

        foreach (array_slice($tagNames, 0, self::POPULAR_TAG_LIMIT) as $tagName) {
            $popularTags[] = new DashboardPopularTag($tagName, $tagCounts[$tagName]);
        }

        return new DashboardDiscoverySummary(
            draftPageCount: $draftPageCount,
            deprecatedPageCount: $deprecatedPageCount,
            popularTags: $popularTags,
        );
    }
}
