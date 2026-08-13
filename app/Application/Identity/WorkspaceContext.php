<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\PageCatalog\PageSearchFilters;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAncestry;
use Illuminate\Http\Request;

final readonly class WorkspaceContext
{
    public function __construct(
        private EffectiveWorkspaceMembershipResolver $memberships,
        private WorkspaceHierarchyGraph $hierarchy,
    ) {
    }

    /**
     * @return list<WorkspaceNavigationItem>
     */
    public function itemsFor(User $user): array
    {
        $workspaceUids = $this->memberships->workspaceUidsFor($user->uid);
        $itemsByUid = [];

        // This nav/switcher renders on every authenticated page, so resolve all
        // reachable workspaces and roles in bounded queries rather than once per item.
        $workspaces = Workspace::query()
            ->whereIn('uid', $workspaceUids)
            ->orderByDesc('created_at')
            ->get();
        $effectiveMemberships = $this->memberships->resolveMany($user->uid, $workspaceUids);

        foreach ($workspaces as $workspace) {
            $effectiveMembership = $effectiveMemberships[$workspace->uid] ?? null;

            if (!$effectiveMembership instanceof EffectiveWorkspaceMembership || $effectiveMembership->role === null) {
                continue;
            }

            $parentWorkspaceUid = $workspace->parent_workspace_uid;

            if ($parentWorkspaceUid !== null && !in_array($parentWorkspaceUid, $workspaceUids, true)) {
                $parentWorkspaceUid = null;
            }

            $itemsByUid[$workspace->uid] = new WorkspaceNavigationItem(
                uid: $workspace->uid,
                name: $workspace->name,
                type: $workspace->type,
                role: $effectiveMembership->role,
                parentWorkspaceUid: $parentWorkspaceUid,
            );
        }

        return $this->treeOrder($itemsByUid);
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
     * Shared workspaces where the actor is an effective Admin and which can
     * still contain a child. The mutation handler remains the final authority.
     *
     * @return list<WorkspaceNavigationItem>
     */
    public function parentItemsFor(User $user, ?string $excludedWorkspaceUid = null): array
    {
        $items = $this->itemsFor($user);
        $excludedWorkspaceUids = $excludedWorkspaceUid === null
            ? []
            : array_map(
                static fn (WorkspaceAncestry $row): string => $row->descendant_workspace_uid,
                $this->hierarchy->subtreeRows($excludedWorkspaceUid),
            );
        $subtreeDepth = $excludedWorkspaceUid === null
            ? 0
            : $this->maximumDepth($this->hierarchy->subtreeRows($excludedWorkspaceUid));
        $actualDepths = $this->actualDepths(array_map(
            static fn (WorkspaceNavigationItem $item): string => $item->uid,
            $items,
        ));

        return array_values(array_filter(
            $items,
            static fn (WorkspaceNavigationItem $item): bool => $item->type === WorkspaceType::Shared
                && $item->role === WorkspaceRole::Admin
                && ($actualDepths[$item->uid] ?? 2) + 1 + $subtreeDepth <= 2
                && !in_array($item->uid, $excludedWorkspaceUids, true),
        ));
    }

    /**
     * Display depth deliberately flattens a branch whose ancestors are hidden.
     * Parent eligibility must instead use the closure table's real depth.
     *
     * @param list<string> $workspaceUids
     * @return array<string, int>
     */
    private function actualDepths(array $workspaceUids): array
    {
        $depths = [];
        $rows = WorkspaceAncestry::query()
            ->whereIn('descendant_workspace_uid', $workspaceUids)
            ->groupBy('descendant_workspace_uid')
            ->select('descendant_workspace_uid')
            ->selectRaw('max(depth) as maximum_depth')
            ->get();

        foreach ($rows as $row) {
            $maximumDepth = $row->getAttribute('maximum_depth');

            if (is_int($maximumDepth) || is_string($maximumDepth)) {
                $depths[$row->descendant_workspace_uid] = (int) $maximumDepth;
            }
        }

        return $depths;
    }

    /**
     * @param list<WorkspaceAncestry> $rows
     */
    private function maximumDepth(array $rows): int
    {
        $maximum = 0;

        foreach ($rows as $row) {
            $maximum = max($maximum, $row->depth);
        }

        return $maximum;
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

    /**
     * @param array<string, WorkspaceNavigationItem> $itemsByUid
     * @return list<WorkspaceNavigationItem>
     */
    private function treeOrder(array $itemsByUid): array
    {
        /** @var array<string, list<WorkspaceNavigationItem>> $childrenByParent */
        $childrenByParent = [];

        foreach ($itemsByUid as $item) {
            $childrenByParent[$item->parentWorkspaceUid ?? ''][] = $item;
        }

        $ordered = [];
        $visited = [];

        foreach ($childrenByParent[''] ?? [] as $root) {
            $this->appendTreeBranch($root, 0, $childrenByParent, $ordered, $visited);
        }

        foreach ($itemsByUid as $item) {
            if (!isset($visited[$item->uid])) {
                $this->appendTreeBranch($item, 0, $childrenByParent, $ordered, $visited);
            }
        }

        return $ordered;
    }

    /**
     * @param array<string, list<WorkspaceNavigationItem>> $childrenByParent
     * @param list<WorkspaceNavigationItem> $ordered
     * @param array<string, true> $visited
     */
    private function appendTreeBranch(
        WorkspaceNavigationItem $item,
        int $depth,
        array $childrenByParent,
        array &$ordered,
        array &$visited,
    ): void {
        if (isset($visited[$item->uid])) {
            return;
        }

        $visited[$item->uid] = true;
        $ordered[] = $item->withDepth(min(2, $depth));

        foreach ($childrenByParent[$item->uid] ?? [] as $child) {
            $this->appendTreeBranch($child, $depth + 1, $childrenByParent, $ordered, $visited);
        }
    }
}
