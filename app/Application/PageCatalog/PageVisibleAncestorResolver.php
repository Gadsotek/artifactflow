<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\Page;
use App\Models\User;

/**
 * Resolves, for pages whose direct parent is absent from an authorized result
 * set, the nearest ancestor that is present -- so a filtered-out ancestor (an
 * archived page, say) does not strand its descendants as apparent roots.
 *
 * The walk stops at the first ancestor the actor cannot view. Reattaching past
 * one would disclose that a visible page descends from another through a page
 * the actor may not see, which is exactly what rendering it as a root withholds.
 */
final readonly class PageVisibleAncestorResolver
{
    public function __construct(
        private PageAccess $access,
    ) {
    }

    /**
     * @param list<PageSearchResult> $results
     *
     * @return array<string, string> page uid => nearest ancestor uid present in $results
     */
    public function effectiveParents(User $actor, array $results): array
    {
        $presentPageUids = [];

        foreach ($results as $result) {
            $presentPageUids[$result->page->uid] = true;
        }

        /** @var array<string, Page|null> $ancestorsByUid */
        $ancestorsByUid = [];
        $effectiveParents = [];

        foreach ($results as $result) {
            $page = $result->page;
            $parentPageUid = $page->parent_page_uid;

            if ($parentPageUid === null || array_key_exists($parentPageUid, $presentPageUids)) {
                continue;
            }

            $reattachTo = $this->nearestPresentAncestorUid(
                page: $page,
                actor: $actor,
                presentPageUids: $presentPageUids,
                ancestorsByUid: $ancestorsByUid,
            );

            if ($reattachTo !== null) {
                $effectiveParents[$page->uid] = $reattachTo;
            }
        }

        return $effectiveParents;
    }

    /**
     * @param array<string, true> $presentPageUids
     * @param array<string, Page|null> $ancestorsByUid
     */
    private function nearestPresentAncestorUid(
        Page $page,
        User $actor,
        array $presentPageUids,
        array &$ancestorsByUid,
    ): ?string {
        // Seeded with self so a row cycle terminates instead of walking forever.
        $visitedPageUids = [$page->uid => true];
        $currentUid = $page->parent_page_uid;

        while ($currentUid !== null && !array_key_exists($currentUid, $visitedPageUids)) {
            $visitedPageUids[$currentUid] = true;

            $ancestor = $this->ancestor($ancestorsByUid, $currentUid, $page->workspace_uid);

            if (!$ancestor instanceof Page || !$this->access->canView($actor, $ancestor)) {
                return null;
            }

            if (array_key_exists($currentUid, $presentPageUids)) {
                return $currentUid;
            }

            $currentUid = $ancestor->parent_page_uid;
        }

        return null;
    }

    /**
     * @param array<string, Page|null> $ancestorsByUid
     */
    private function ancestor(array &$ancestorsByUid, string $pageUid, string $workspaceUid): ?Page
    {
        if (array_key_exists($pageUid, $ancestorsByUid)) {
            return $ancestorsByUid[$pageUid];
        }

        $ancestor = Page::query()
            ->where('workspace_uid', $workspaceUid)
            // Eager-load grants so the canView() above reads them from memory
            // instead of issuing a grant query per ancestor hop.
            ->with('accessGrants')
            ->find($pageUid);

        $ancestorsByUid[$pageUid] = $ancestor;

        return $ancestor;
    }
}
