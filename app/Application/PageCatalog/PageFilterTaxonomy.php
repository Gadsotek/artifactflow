<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\Category;
use App\Models\Page;
use App\Models\Tag;
use App\Models\User;

/**
 * Vocabulary reachable through the actor's workspace memberships or pages the
 * actor may actually view. Building filters and MCP discovery from the same
 * authorization path prevents category names and private-only tag labels from
 * becoming an information side channel.
 *
 * Scaling: the page walk is O(visible pages) but query-free — accessGrants,
 * category, and tags are eager-loaded, and PageAccess memoizes every role/grant
 * lookup for the request, so canView() adds no per-page query — and lazyById
 * bounds memory. Team-scale installs pay only an in-memory pass. A materialized
 * taxonomy projection is the intended path if an installation's visible-page
 * count ever makes that pass matter; there is no accidental N+1 to remove first.
 */
final readonly class PageFilterTaxonomy
{
    public function __construct(
        private PageVisibilityQuery $visibility,
        private PageAccess $access,
    ) {
    }

    public function forUser(User $actor, ?string $workspaceUid = null): PageFilterTaxonomyResult
    {
        $query = Page::query()
            ->select(['uid', 'workspace_uid', 'owner_user_uid', 'category_uid', 'access_mode'])
            ->with(['accessGrants', 'category.workspace', 'tags'])
            ->orderBy('pages.uid');
        $visibilityScope = $this->visibility->apply($query, $actor);

        if ($workspaceUid !== null && $workspaceUid !== PageSearchFilters::ALL_WORKSPACES) {
            $query->where('workspace_uid', $workspaceUid);
        }

        $categoryWorkspaceUids = $visibilityScope->membershipWorkspaceUids;

        if ($workspaceUid !== null && $workspaceUid !== PageSearchFilters::ALL_WORKSPACES) {
            $categoryWorkspaceUids = in_array($workspaceUid, $categoryWorkspaceUids, true)
                ? [$workspaceUid]
                : [];
        }

        /** @var array<string, Category> $categories */
        $categories = [];

        if ($categoryWorkspaceUids !== []) {
            foreach (Category::query()->with('workspace')->whereIn('workspace_uid', $categoryWorkspaceUids)->get() as $category) {
                $categories[$category->uid] = $category;
            }
        }
        /** @var array<string, Tag> $tags */
        $tags = [];

        foreach ($query->lazyById(200, 'pages.uid', 'uid') as $page) {
            if (!$this->access->canView($actor, $page)) {
                continue;
            }

            if ($page->category instanceof Category) {
                $categories[$page->category->uid] = $page->category;
            }

            foreach ($page->tags as $tag) {
                $tags[$tag->uid] = $tag;
            }
        }

        $categoryList = array_values($categories);
        usort($categoryList, static function (Category $left, Category $right): int {
            return [$left->name, $left->workspace->name, $left->uid]
                <=> [$right->name, $right->workspace->name, $right->uid];
        });
        $tagList = array_values($tags);
        usort($tagList, static fn (Tag $left, Tag $right): int => [$left->name, $left->uid] <=> [$right->name, $right->uid]);

        return new PageFilterTaxonomyResult($categoryList, $tagList);
    }
}
