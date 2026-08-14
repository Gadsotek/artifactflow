<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Identity\EffectiveWorkspaceMembershipResolver;
use App\Application\Identity\WorkspaceContext;
use App\Application\Identity\WorkspaceNavigationItem;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Models\Page;
use App\Models\User;

final readonly class PageWorkspaceMoveOptions
{
    public function __construct(
        private PageAccess $access,
        private WorkspaceContext $workspaceContext,
        private EffectiveWorkspaceMembershipResolver $memberships,
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
        $uniqueUserUids = $this->memberships->userUidsForAny(
            $targetWorkspaceUids,
            [WorkspaceRole::Editor, WorkspaceRole::Admin],
        );

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

            foreach ($uniqueUserUids as $userUid) {
                $owner = $usersByUid[$userUid] ?? null;

                if (!$owner instanceof User) {
                    continue;
                }

                $role = $this->memberships->resolve($owner->uid, $workspaceItem->uid)->role;

                if ($role?->canWritePages() !== true) {
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
