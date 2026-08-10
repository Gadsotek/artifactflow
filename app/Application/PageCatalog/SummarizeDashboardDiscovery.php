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
        /** @var array<string, array{name: string, count: int}> $tagCounts */
        $tagCounts = [];

        foreach ($pages as $page) {
            if ($page->status === PageStatus::Draft) {
                ++$draftPageCount;
            }

            if ($page->status === PageStatus::Deprecated) {
                ++$deprecatedPageCount;
            }

            foreach ($page->tags as $tag) {
                $tagCounts[$tag->uid] ??= ['name' => $tag->name, 'count' => 0];
                ++$tagCounts[$tag->uid]['count'];
            }
        }

        $tagUids = array_keys($tagCounts);
        usort(
            $tagUids,
            static fn (string $left, string $right): int => $tagCounts[$right]['count'] <=> $tagCounts[$left]['count']
                ?: strcasecmp($tagCounts[$left]['name'], $tagCounts[$right]['name']),
        );
        $popularTags = [];

        foreach (array_slice($tagUids, 0, self::POPULAR_TAG_LIMIT) as $tagUid) {
            $popularTags[] = new DashboardPopularTag(
                uid: $tagUid,
                name: $tagCounts[$tagUid]['name'],
                pageCount: $tagCounts[$tagUid]['count'],
            );
        }

        return new DashboardDiscoverySummary(
            draftPageCount: $draftPageCount,
            deprecatedPageCount: $deprecatedPageCount,
            popularTags: $popularTags,
        );
    }
}
