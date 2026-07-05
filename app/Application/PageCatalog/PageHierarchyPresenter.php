<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\User;

/**
 * Arranges only the pages already present in an authorized search result.
 * When a page's direct parent is absent, it reattaches to the nearest ancestor
 * that is present -- but only across ancestors the actor can view, so an
 * inaccessible parent stays undisclosed and its descendants render as roots.
 */
final class PageHierarchyPresenter
{
    public function __construct(
        private readonly PageVisibleAncestorResolver $visibleAncestors,
    ) {
    }

    /**
     * @param list<PageSearchResult> $results
     * @return list<PageTreeItem>
     */
    public function arrange(User $actor, array $results): array
    {
        $resultsByPageUid = [];

        foreach ($results as $result) {
            $resultsByPageUid[$result->page->uid] = $result;
        }

        // A filtered-out ancestor (archived, say) would otherwise strand its
        // descendants at the root, where they read as top-level pages.
        $effectiveParents = $this->visibleAncestors->effectiveParents($actor, $results);

        /** @var array<string, list<PageSearchResult>> $childrenByParentUid */
        $childrenByParentUid = [];
        $roots = [];

        foreach ($results as $result) {
            $parentPageUid = $effectiveParents[$result->page->uid] ?? $result->page->parent_page_uid;

            if ($parentPageUid === null || !array_key_exists($parentPageUid, $resultsByPageUid)) {
                $roots[] = $result;

                continue;
            }

            $childrenByParentUid[$parentPageUid][] = $result;
        }

        $items = [];
        $visitedPageUids = [];

        foreach ($roots as $root) {
            $this->appendBranch($root, 0, null, $childrenByParentUid, $visitedPageUids, $items);
        }

        // Application rules prevent cycles, but keep the read model complete if
        // legacy or manually altered rows contain one: every visible result must
        // still render exactly once, without an unbounded recursive traversal.
        foreach ($results as $result) {
            if (array_key_exists($result->page->uid, $visitedPageUids)) {
                continue;
            }

            $this->appendBranch($result, 0, null, $childrenByParentUid, $visitedPageUids, $items);
        }

        return $items;
    }

    /**
     * @param array<string, list<PageSearchResult>> $childrenByParentUid
     * @param array<string, true> $visitedPageUids
     * @param list<PageTreeItem> $items
     */
    private function appendBranch(
        PageSearchResult $result,
        int $depth,
        ?string $parentTitle,
        array $childrenByParentUid,
        array &$visitedPageUids,
        array &$items,
    ): void {
        $pageUid = $result->page->uid;

        if (array_key_exists($pageUid, $visitedPageUids)) {
            return;
        }

        $visitedPageUids[$pageUid] = true;
        $items[] = new PageTreeItem($result, $depth, $parentTitle);

        foreach ($childrenByParentUid[$pageUid] ?? [] as $child) {
            $this->appendBranch(
                $child,
                $depth + 1,
                $result->page->title,
                $childrenByParentUid,
                $visitedPageUids,
                $items,
            );
        }
    }
}
