<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\Page;
use App\Models\User;

final readonly class PageHierarchy
{
    private const int CHILD_LIMIT = 100;

    public function __construct(
        private PageAccess $access,
    ) {
    }

    public function forPage(User $actor, Page $page): PageHierarchyResult
    {
        $parent = $this->visibleParent($actor, $page);
        $children = [];
        $childPages = Page::query()
            ->where('workspace_uid', $page->workspace_uid)
            ->where('parent_page_uid', $page->uid)
            // Eager-load grants so the per-child canView() below reads them from
            // memory instead of issuing one PageAccessGrant query per child.
            ->with('accessGrants')
            ->orderBy('title')
            ->limit(self::CHILD_LIMIT)
            ->get();

        foreach ($childPages as $childPage) {
            if ($this->access->canView($actor, $childPage)) {
                $children[] = $this->item($childPage);
            }
        }

        return new PageHierarchyResult($parent, $children);
    }

    private function visibleParent(User $actor, Page $page): ?PageHierarchyItem
    {
        if ($page->parent_page_uid === null) {
            return null;
        }

        $parent = Page::query()
            ->where('workspace_uid', $page->workspace_uid)
            ->with('accessGrants')
            ->find($page->parent_page_uid);

        if (!$parent instanceof Page || !$this->access->canView($actor, $parent)) {
            return null;
        }

        return $this->item($parent);
    }

    private function item(Page $page): PageHierarchyItem
    {
        return new PageHierarchyItem(
            pageUid: $page->uid,
            title: $page->title,
            status: $page->status,
        );
    }
}
