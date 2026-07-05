<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\Category;
use App\Models\Page;
use App\Models\User;
use App\Models\WorkspaceMembership;

/**
 * Read-side query object for the category / owner / parent-page picker
 * lists shown on the page index and create screens. Keeps the controller thin
 * and consistent with the other read services (PageSearch, PageDetailViewData)
 * instead of building these queries inline.
 */
final readonly class PagePickerOptions
{
    private const int PARENT_PAGE_PICKER_LIMIT = 250;

    public function __construct(
        private PageAccess $access,
    ) {
    }

    /**
     * @param list<string> $workspaceUids
     *
     * @return list<Category>
     */
    public function categoriesFor(array $workspaceUids): array
    {
        if ($workspaceUids === []) {
            return [];
        }

        return array_values(Category::query()
            ->whereIn('workspace_uid', $workspaceUids)
            ->orderBy('name')
            ->get()
            ->all());
    }

    /**
     * @param list<string> $workspaceUids
     *
     * @return list<User>
     */
    public function ownersFor(array $workspaceUids): array
    {
        if ($workspaceUids === []) {
            return [];
        }

        $userUids = WorkspaceMembership::query()
            ->whereIn('workspace_uid', $workspaceUids)
            ->pluck('user_uid')
            ->all();
        $uniqueUserUids = array_values(array_unique(array_filter($userUids, 'is_string')));

        if ($uniqueUserUids === []) {
            return [];
        }

        return array_values(User::query()
            ->whereIn('uid', $uniqueUserUids)
            ->orderBy('name')
            ->get()
            ->all());
    }

    /**
     * Parent-page candidates the actor may actually view. The canView post-filter
     * runs in PHP over at most PARENT_PAGE_PICKER_LIMIT rows.
     *
     * @param list<string> $workspaceUids
     *
     * @return list<Page>
     */
    public function parentPagesFor(User $actor, array $workspaceUids): array
    {
        if ($workspaceUids === []) {
            return [];
        }

        $pages = Page::query()
            ->select(['uid', 'workspace_uid', 'owner_user_uid', 'access_mode', 'title'])
            ->with('accessGrants')
            ->whereIn('workspace_uid', $workspaceUids)
            ->orderBy('title')
            ->limit(self::PARENT_PAGE_PICKER_LIMIT)
            ->get();
        $result = [];

        foreach ($pages as $page) {
            if ($this->access->canView($actor, $page)) {
                $result[] = $page;
            }
        }

        return $result;
    }
}
