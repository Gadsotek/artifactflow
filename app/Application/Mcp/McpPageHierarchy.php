<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\PageVisibilityQuery;
use App\Models\Page;
use App\Models\User;

/**
 * Shapes page relationships for MCP without disclosing inaccessible records.
 * Ancestor traversal stops at the first hidden page, and child counts include
 * only direct children the active MCP principal may view.
 */
final readonly class McpPageHierarchy
{
    public function __construct(
        private PageAccess $pageAccess,
        private PageVisibilityQuery $visibility,
    ) {
    }

    /**
     * @param list<Page> $pages
     * @return array<string, array<string, mixed>>
     */
    public function forPages(User $actor, array $pages): array
    {
        if ($pages === []) {
            return [];
        }

        $pagesByUid = [];
        $loadedPageUids = [];
        $currentPages = [];
        $visitedPageUids = [];
        $directFirstAncestors = [];

        foreach ($pages as $page) {
            $pagesByUid[$page->uid] = $page;
            $loadedPageUids[$page->uid] = true;
            $currentPages[$page->uid] = $page;
            $visitedPageUids[$page->uid] = [$page->uid => true];
            $directFirstAncestors[$page->uid] = [];
        }

        while ($currentPages !== []) {
            $parentPageUidsToLoad = [];

            foreach ($currentPages as $pageUid => $currentPage) {
                $parentPageUid = $currentPage->parent_page_uid;

                if (
                    $parentPageUid !== null
                    && !array_key_exists($parentPageUid, $visitedPageUids[$pageUid])
                    && !array_key_exists($parentPageUid, $loadedPageUids)
                ) {
                    $parentPageUidsToLoad[$parentPageUid] = true;
                }
            }

            if ($parentPageUidsToLoad !== []) {
                $parentPages = Page::query()
                    ->with('accessGrants')
                    ->whereIn('uid', array_keys($parentPageUidsToLoad))
                    ->get();

                foreach (array_keys($parentPageUidsToLoad) as $parentPageUid) {
                    $loadedPageUids[$parentPageUid] = true;
                }

                foreach ($parentPages as $parentPage) {
                    $pagesByUid[$parentPage->uid] = $parentPage;
                }
            }

            $nextPages = [];

            foreach ($currentPages as $pageUid => $currentPage) {
                $parentPageUid = $currentPage->parent_page_uid;

                if (
                    $parentPageUid === null
                    || array_key_exists($parentPageUid, $visitedPageUids[$pageUid])
                ) {
                    continue;
                }

                $parentPage = $pagesByUid[$parentPageUid] ?? null;

                if (
                    !$parentPage instanceof Page
                    || $parentPage->workspace_uid !== $pagesByUid[$pageUid]->workspace_uid
                    || !$this->pageAccess->canView($actor, $parentPage)
                ) {
                    continue;
                }

                $visitedPageUids[$pageUid][$parentPageUid] = true;
                $directFirstAncestors[$pageUid][] = $parentPage;
                $nextPages[$pageUid] = $parentPage;
            }

            $currentPages = $nextPages;
        }

        $visibleChildCounts = [];
        $targetPagesByUid = [];

        foreach ($pages as $page) {
            $visibleChildCounts[$page->uid] = 0;
            $targetPagesByUid[$page->uid] = $page;
        }

        $childrenQuery = Page::query()
            ->with('accessGrants')
            ->whereIn('parent_page_uid', array_keys($targetPagesByUid));
        $this->visibility->apply($childrenQuery, $actor);

        foreach ($childrenQuery->get() as $childPage) {
            $parentPageUid = $childPage->parent_page_uid;
            $parentPage = $parentPageUid === null ? null : ($targetPagesByUid[$parentPageUid] ?? null);

            if (
                $parentPage instanceof Page
                && $childPage->workspace_uid === $parentPage->workspace_uid
                && $this->pageAccess->canView($actor, $childPage)
            ) {
                $visibleChildCounts[$parentPage->uid]++;
            }
        }

        $payloads = [];

        foreach ($pages as $page) {
            $ancestors = array_reverse($directFirstAncestors[$page->uid]);
            $ancestorPayloads = array_map(
                fn (Page $ancestor): array => $this->pageReference($ancestor),
                $ancestors,
            );

            $payloads[$page->uid] = [
                'parent' => $ancestorPayloads === [] ? null : $ancestorPayloads[count($ancestorPayloads) - 1],
                'ancestors' => $ancestorPayloads,
                'visible_depth' => count($ancestorPayloads),
                'visible_child_count' => $visibleChildCounts[$page->uid],
            ];
        }

        return $payloads;
    }

    /**
     * @return array{uid: string, title: array<string, mixed>}
     */
    private function pageReference(Page $page): array
    {
        return [
            'uid' => $page->uid,
            'title' => McpDataEnvelope::text($page->title),
        ];
    }
}
