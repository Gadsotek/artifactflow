<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\PageCatalog\PageSearchFilters;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\Request;

final class WorkspaceContext
{
    /**
     * @return list<WorkspaceNavigationItem>
     */
    public function itemsFor(User $user): array
    {
        $memberships = WorkspaceMembership::query()
            ->where('user_uid', $user->uid)
            ->orderByDesc('created_at')
            ->get();
        $items = [];

        // This nav/switcher renders on every authenticated page, so resolve all
        // of the user's workspaces in one query instead of a find() per membership.
        $workspaces = Workspace::query()
            ->whereIn('uid', $memberships->pluck('workspace_uid')->all())
            ->get()
            ->keyBy('uid');

        foreach ($memberships as $membership) {
            $workspace = $workspaces->get($membership->workspace_uid);

            if (!$workspace instanceof Workspace) {
                continue;
            }

            $items[] = new WorkspaceNavigationItem(
                uid: $workspace->uid,
                name: $workspace->name,
                type: $workspace->type,
                role: $membership->role,
            );
        }

        return $items;
    }

    /**
     * @return list<WorkspaceNavigationItem>
     */
    public function editableItemsFor(User $user): array
    {
        return array_values(array_filter(
            $this->itemsFor($user),
            static fn (WorkspaceNavigationItem $item): bool => $item->role->canWritePages(),
        ));
    }

    /**
     * @param list<WorkspaceNavigationItem> $workspaceItems
     */
    public function resolveCurrentWorkspaceUid(
        Request $request,
        array $workspaceItems,
        bool $allowAllWorkspaces,
    ): ?string {
        $requestedWorkspaceUid = trim($request->string('workspace_uid')->toString());
        $sessionWorkspaceUid = $request->session()->get('current_workspace_uid');
        $allowedWorkspaceUids = $this->uidsFrom($workspaceItems);

        if ($allowAllWorkspaces && $requestedWorkspaceUid === PageSearchFilters::ALL_WORKSPACES) {
            return PageSearchFilters::ALL_WORKSPACES;
        }

        if ($requestedWorkspaceUid !== '') {
            if (in_array($requestedWorkspaceUid, $allowedWorkspaceUids, true)) {
                $request->session()->put('current_workspace_uid', $requestedWorkspaceUid);

                return $requestedWorkspaceUid;
            }
        }

        if (is_string($sessionWorkspaceUid) && in_array($sessionWorkspaceUid, $allowedWorkspaceUids, true)) {
            return $sessionWorkspaceUid;
        }

        $currentWorkspaceUid = $workspaceItems[0]->uid ?? null;

        if ($currentWorkspaceUid !== null) {
            $request->session()->put('current_workspace_uid', $currentWorkspaceUid);
        }

        return $currentWorkspaceUid;
    }

    /**
     * @param list<WorkspaceNavigationItem> $workspaceItems
     *
     * @return list<string>
     */
    public function uidsFrom(array $workspaceItems): array
    {
        return array_map(
            static fn (WorkspaceNavigationItem $item): string => $item->uid,
            $workspaceItems,
        );
    }
}
