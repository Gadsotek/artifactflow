<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Identity\WorkspaceContext;
use App\Application\Identity\WorkspaceNavigationItem;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Models\Page;
use App\Models\User;
use App\Models\WorkspaceMembership;

final readonly class PageWorkspaceMoveOptions
{
    public function __construct(
        private PageAccess $access,
        private WorkspaceContext $workspaceContext,
    ) {
    }

    /**
     * @return list<PageWorkspaceMoveTarget>
     */
    public function forPage(User $actor, Page $page): array
    {
        if (!$this->access->canHardDelete($actor, $page)) {
            return [];
        }

        $targetWorkspaceItems = array_values(array_filter(
            $this->workspaceContext->itemsFor($actor),
            static fn (WorkspaceNavigationItem $item): bool => $item->uid !== $page->workspace_uid
                && $item->type === WorkspaceType::Shared
                && $item->role === WorkspaceRole::Admin,
        ));

        if ($targetWorkspaceItems === []) {
            return [];
        }

        $targetWorkspaceUids = array_map(
            static fn (WorkspaceNavigationItem $item): string => $item->uid,
            $targetWorkspaceItems,
        );
        $memberships = WorkspaceMembership::query()
            ->whereIn('workspace_uid', $targetWorkspaceUids)
            ->whereIn('role', [WorkspaceRole::Editor->value, WorkspaceRole::Admin->value])
            ->orderBy('created_at')
            ->get();
        $userUids = [];

        foreach ($memberships as $membership) {
            $userUids[] = $membership->user_uid;
        }

        $uniqueUserUids = array_values(array_unique(array_filter($userUids, 'is_string')));

        if ($uniqueUserUids === []) {
            return [];
        }

        $users = User::query()
            ->whereIn('uid', $uniqueUserUids)
            ->orderBy('name')
            ->get();
        $usersByUid = [];

        foreach ($users as $user) {
            $usersByUid[$user->uid] = $user;
        }

        $targets = [];

        foreach ($targetWorkspaceItems as $workspaceItem) {
            $owners = [];

            foreach ($memberships as $membership) {
                if ($membership->workspace_uid !== $workspaceItem->uid) {
                    continue;
                }

                $owner = $usersByUid[$membership->user_uid] ?? null;

                if (!$owner instanceof User) {
                    continue;
                }

                $owners[] = new PageWorkspaceMoveOwner(
                    uid: $owner->uid,
                    name: $owner->name,
                );
            }

            if ($owners === []) {
                continue;
            }

            $targets[] = new PageWorkspaceMoveTarget(
                workspaceUid: $workspaceItem->uid,
                workspaceName: $workspaceItem->name,
                owners: $owners,
            );
        }

        return $targets;
    }
}
