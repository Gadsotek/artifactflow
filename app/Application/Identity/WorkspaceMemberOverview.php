<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAncestry;
use App\Models\WorkspaceMembership;

final readonly class WorkspaceMemberOverview
{
    public function __construct(
        private WorkspaceAccess $workspaceAccess,
        private WorkspaceHierarchyGraph $hierarchy,
        private EffectiveWorkspaceMembershipResolver $memberships,
    ) {
    }

    public function forWorkspace(
        User $actor,
        ?string $workspaceUid,
        int $requestedPage = 1,
        int $perPage = 20,
    ): WorkspaceMemberPage {
        if ($workspaceUid === null || !$this->isWorkspaceMember($actor, $workspaceUid)) {
            return new WorkspaceMemberPage([], 1, 1, $perPage, 0);
        }

        $ancestorWorkspaceUids = $this->ancestorWorkspaceUids($workspaceUid);
        $membershipRows = WorkspaceMembership::query()
            ->whereIn('workspace_uid', $ancestorWorkspaceUids)
            ->orderBy('created_at')
            ->orderBy('uid')
            ->get();
        /** @var list<string> $userUids */
        $userUids = $membershipRows
            ->pluck('user_uid')
            ->unique()
            ->values()
            ->all();
        $effectiveMemberships = $this->memberships->resolveUsersForWorkspace($userUids, $workspaceUid);
        $userUids = array_values(array_filter(
            $userUids,
            static fn (string $userUid): bool => ($effectiveMemberships[$userUid] ?? null)?->role
                instanceof WorkspaceRole,
        ));
        $total = count($userUids);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min(max(1, $requestedPage), $lastPage);
        $pageUserUids = array_slice($userUids, ($currentPage - 1) * $perPage, $perPage);
        $users = User::query()->whereIn('uid', $pageUserUids)->get()->keyBy('uid');
        $directMemberships = $membershipRows
            ->where('workspace_uid', $workspaceUid)
            ->keyBy('user_uid');
        /** @var array<string, WorkspaceMembership> $directMembershipMap */
        $directMembershipMap = $directMemberships->all();
        $originNames = $this->visibleOriginNames($actor, $ancestorWorkspaceUids);
        $pageUserUidMap = array_flip($pageUserUids);
        $ownershipControls = $this->ownershipControls(
            workspaceUid: $workspaceUid,
            directMemberships: array_intersect_key($directMembershipMap, $pageUserUidMap),
            effectiveMemberships: array_intersect_key($effectiveMemberships, $pageUserUidMap),
        );
        $items = [];

        foreach ($pageUserUids as $userUid) {
            $user = $users->get($userUid);

            if (!$user instanceof User) {
                continue;
            }

            $effective = $effectiveMemberships[$userUid];

            if (!$effective->role instanceof WorkspaceRole) {
                continue;
            }

            $directMembership = $directMemberships->get($userUid);
            $isInherited = !$directMembership instanceof WorkspaceMembership;
            $originWorkspaceName = $isInherited
                ? $this->winningOriginName($effective, $originNames)
                : null;

            $items[] = new WorkspaceMemberItem(
                membershipUid: $directMembership instanceof WorkspaceMembership ? $directMembership->uid : null,
                userUid: $user->uid,
                name: $user->name,
                email: $user->email,
                role: $effective->role,
                isCurrentUser: $user->uid === $actor->uid,
                ownedPageCount: $ownershipControls[$user->uid]['ownedPageCount'] ?? 0,
                directRole: $directMembership instanceof WorkspaceMembership ? $directMembership->role : null,
                isInherited: $isInherited,
                originWorkspaceName: $originWorkspaceName,
                ownershipCandidates: $ownershipControls[$user->uid]['candidates'] ?? [],
            );
        }

        return new WorkspaceMemberPage(
            items: $items,
            currentPage: $currentPage,
            lastPage: $lastPage,
            perPage: $perPage,
            total: $total,
        );
    }

    public function canManageWorkspace(User $actor, ?string $workspaceUid): bool
    {
        if ($workspaceUid === null) {
            return false;
        }

        $workspace = Workspace::query()->find($workspaceUid);

        if (!$workspace instanceof Workspace || $workspace->type !== WorkspaceType::Shared) {
            return false;
        }

        return $this->workspaceAccess->role($actor->uid, $workspaceUid) === WorkspaceRole::Admin;
    }

    /**
     * Removal controls are derived from the exact workspaces where deleting a
     * direct membership or excluding inherited origins at this boundary would
     * remove write authority. Retained direct descendant access therefore does
     * not demand an unrelated page reassignment.
     *
     * @param array<string, WorkspaceMembership> $directMemberships
     * @param array<string, EffectiveWorkspaceMembership> $effectiveMemberships
     * @return array<string, array{ownedPageCount: int, candidates: list<WorkspaceOwnershipCandidate>}>
     */
    private function ownershipControls(
        string $workspaceUid,
        array $directMemberships,
        array $effectiveMemberships,
    ): array {
        if ($effectiveMemberships === []) {
            return [];
        }

        $subtreeRows = $this->hierarchy->subtreeRows($workspaceUid);
        $descendantWorkspaceUids = array_map(
            static fn (WorkspaceAncestry $row): string => $row->descendant_workspace_uid,
            $subtreeRows,
        );
        $boundaryDepths = [];

        foreach ($subtreeRows as $row) {
            $boundaryDepths[$row->descendant_workspace_uid] = $row->depth;
        }

        $memberUserUids = array_keys($effectiveMemberships);
        $memberAuthorities = $this->memberships->resolveUsersForWorkspaces(
            $memberUserUids,
            $descendantWorkspaceUids,
        );
        $ownedPages = Page::query()
            ->whereIn('workspace_uid', $descendantWorkspaceUids)
            ->whereIn('owner_user_uid', $memberUserUids)
            ->select(['workspace_uid', 'owner_user_uid'])
            ->selectRaw('COUNT(*) AS owned_page_count')
            ->groupBy(['workspace_uid', 'owner_user_uid'])
            ->get();
        /** @var array<string, array<string, int>> $ownedCounts */
        $ownedCounts = [];

        foreach ($ownedPages as $page) {
            $ownedPageCount = $page->getAttribute('owned_page_count');

            if (!is_int($ownedPageCount) && !is_string($ownedPageCount)) {
                continue;
            }

            $ownedCounts[$page->owner_user_uid][$page->workspace_uid] = (int) $ownedPageCount;
        }

        /** @var array<string, array{ownedPageCount: int, candidates: list<WorkspaceOwnershipCandidate>}> $controls */
        $controls = [];
        /** @var array<string, list<string>> $requiredWorkspaceUidsByMember */
        $requiredWorkspaceUidsByMember = [];
        $allRequiredWorkspaceUids = [];

        foreach (array_keys($effectiveMemberships) as $memberUserUid) {
            $requiredWorkspaceUids = [];
            $ownedPageCount = 0;
            $directMembership = $directMemberships[$memberUserUid] ?? null;

            foreach ($descendantWorkspaceUids as $descendantWorkspaceUid) {
                $effective = $memberAuthorities[$memberUserUid][$descendantWorkspaceUid] ?? null;

                if (!$effective instanceof EffectiveWorkspaceMembership || $effective->role?->canWritePages() !== true) {
                    continue;
                }

                $remainingRole = $directMembership instanceof WorkspaceMembership
                    ? $this->roleWithoutMembership($effective, $directMembership->uid)
                    : $this->roleWithoutAncestorOrigins(
                        $effective,
                        $boundaryDepths[$descendantWorkspaceUid],
                    );

                if ($remainingRole?->canWritePages() === true) {
                    continue;
                }

                $count = $ownedCounts[$memberUserUid][$descendantWorkspaceUid] ?? 0;

                if ($count > 0) {
                    $requiredWorkspaceUids[] = $descendantWorkspaceUid;
                    $ownedPageCount += $count;
                }
            }

            $requiredWorkspaceUidsByMember[$memberUserUid] = $requiredWorkspaceUids;
            $allRequiredWorkspaceUids = [...$allRequiredWorkspaceUids, ...$requiredWorkspaceUids];
            $controls[$memberUserUid] = [
                'ownedPageCount' => $ownedPageCount,
                'candidates' => [],
            ];
        }

        $allRequiredWorkspaceUids = array_values(array_unique($allRequiredWorkspaceUids));

        if ($allRequiredWorkspaceUids === []) {
            return $controls;
        }

        $candidateUserUids = $this->memberships->userUidsForAny(
            $allRequiredWorkspaceUids,
            [WorkspaceRole::Editor, WorkspaceRole::Admin],
        );
        $candidateAuthorities = $this->memberships->resolveUsersForWorkspaces(
            $candidateUserUids,
            $allRequiredWorkspaceUids,
        );
        $candidateUsers = User::query()
            ->whereIn('uid', $candidateUserUids)
            ->orderBy('name')
            ->orderBy('uid')
            ->get()
            ->keyBy('uid');

        foreach ($requiredWorkspaceUidsByMember as $memberUserUid => $requiredWorkspaceUids) {
            $candidates = [];

            foreach ($candidateUsers as $candidate) {
                foreach ($requiredWorkspaceUids as $requiredWorkspaceUid) {
                    if (
                        ($candidateAuthorities[$candidate->uid][$requiredWorkspaceUid] ?? null)?->role?->canWritePages()
                        !== true
                    ) {
                        continue 2;
                    }
                }

                if ($requiredWorkspaceUids !== []) {
                    $candidates[] = new WorkspaceOwnershipCandidate(
                        userUid: $candidate->uid,
                        name: $candidate->name,
                    );
                }
            }

            $control = $controls[$memberUserUid] ?? null;

            if ($control === null) {
                continue;
            }

            $controls[$memberUserUid] = [
                'ownedPageCount' => $control['ownedPageCount'],
                'candidates' => $candidates,
            ];
        }

        return $controls;
    }

    private function roleWithoutMembership(
        EffectiveWorkspaceMembership $membership,
        string $excludedMembershipUid,
    ): ?WorkspaceRole {
        $role = null;

        foreach ($membership->origins as $origin) {
            if ($origin->membershipUid === $excludedMembershipUid) {
                continue;
            }

            if (!$role instanceof WorkspaceRole || $origin->role->rank() > $role->rank()) {
                $role = $origin->role;
            }
        }

        return $role;
    }

    private function roleWithoutAncestorOrigins(
        EffectiveWorkspaceMembership $membership,
        int $boundaryDepth,
    ): ?WorkspaceRole {
        $role = null;

        foreach ($membership->origins as $origin) {
            if ($origin->depth > $boundaryDepth) {
                continue;
            }

            if (!$role instanceof WorkspaceRole || $origin->role->rank() > $role->rank()) {
                $role = $origin->role;
            }
        }

        return $role;
    }

    /**
     * @return list<string>
     */
    private function ancestorWorkspaceUids(string $workspaceUid): array
    {
        return array_map(
            static fn (WorkspaceAncestry $row): string => $row->ancestor_workspace_uid,
            $this->hierarchy->ancestorRows($workspaceUid),
        );
    }

    /**
     * @param list<string> $ancestorWorkspaceUids
     * @return array<string, string>
     */
    private function visibleOriginNames(User $actor, array $ancestorWorkspaceUids): array
    {
        $names = [];

        foreach (Workspace::query()->whereIn('uid', $ancestorWorkspaceUids)->get() as $workspace) {
            if ($this->workspaceAccess->role($actor->uid, $workspace->uid) instanceof WorkspaceRole) {
                $names[$workspace->uid] = $workspace->name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, string> $originNames
     */
    private function winningOriginName(
        EffectiveWorkspaceMembership $membership,
        array $originNames,
    ): ?string {
        $winningDepth = PHP_INT_MAX;
        $name = null;

        foreach ($membership->origins as $origin) {
            if ($origin->role !== $membership->role || $origin->depth >= $winningDepth) {
                continue;
            }

            $winningDepth = $origin->depth;
            $name = $originNames[$origin->workspaceUid] ?? null;
        }

        return $name;
    }

    private function isWorkspaceMember(User $actor, string $workspaceUid): bool
    {
        return $this->workspaceAccess->role($actor->uid, $workspaceUid) instanceof WorkspaceRole;
    }
}
