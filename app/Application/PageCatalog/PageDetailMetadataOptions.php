<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\Identity\WorkspaceRole;
use App\Models\Category;
use App\Models\Page;
use App\Models\User;
use App\Models\WorkspaceMembership;

final readonly class PageDetailMetadataOptions
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
    public function eligibleOwnersFor(array $workspaceUids): array
    {
        if ($workspaceUids === []) {
            return [];
        }

        $userUids = WorkspaceMembership::query()
            ->whereIn('workspace_uid', $workspaceUids)
            ->whereIn('role', [WorkspaceRole::Editor->value, WorkspaceRole::Admin->value])
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
     * The current owner as the sole picker option, for an editor who may save
     * metadata but not reassign ownership. The required owner field resubmits
     * unchanged without exposing the workspace's Editor/Admin roster.
     *
     * @return list<User>
     */
    public function currentOwnerOptionFor(Page $page): array
    {
        $owner = User::query()->find($page->owner_user_uid);

        return $owner instanceof User ? [$owner] : [];
    }

    /**
     * @return list<Page>
     */
    public function parentPagesFor(User $actor, Page $page): array
    {
        $pages = Page::query()
            ->select(['uid', 'workspace_uid', 'owner_user_uid', 'access_mode', 'title'])
            ->with('accessGrants')
            ->where('workspace_uid', $page->workspace_uid)
            ->where('uid', '!=', $page->uid)
            ->orderBy('title')
            ->limit(self::PARENT_PAGE_PICKER_LIMIT)
            ->get();
        $result = [];

        foreach ($pages as $parentPage) {
            if ($this->access->canView($actor, $parentPage)) {
                $result[] = $parentPage;
            }
        }

        return $result;
    }
}
