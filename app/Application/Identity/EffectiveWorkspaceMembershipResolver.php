<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceAncestry;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipExclusion;

final readonly class EffectiveWorkspaceMembershipResolver
{
    public function resolve(string $userUid, string $workspaceUid): EffectiveWorkspaceMembership
    {
        return $this->resolveMany($userUid, [$workspaceUid])[$workspaceUid];
    }

    /**
     * Resolve one workspace for many users without turning a member listing
     * into one ancestry query plus one membership query per row.
     *
     * @param list<string> $userUids
     *
     * @return array<string, EffectiveWorkspaceMembership>
     */
    public function resolveUsersForWorkspace(array $userUids, string $workspaceUid): array
    {
        $results = [];

        foreach ($this->resolveUsersForWorkspaces($userUids, [$workspaceUid]) as $userUid => $memberships) {
            $results[$userUid] = $memberships[$workspaceUid];
        }

        return $results;
    }

    /**
     * Resolve many workspaces for many users with one ancestry query and one
     * membership query. Pickers and hierarchy impact screens must use this
     * rather than nesting resolveMany() inside a candidate loop.
     *
     * @param list<string> $userUids
     * @param list<string> $workspaceUids
     * @return array<string, array<string, EffectiveWorkspaceMembership>>
     */
    public function resolveUsersForWorkspaces(array $userUids, array $workspaceUids): array
    {
        $userUids = array_values(array_unique($userUids));
        $workspaceUids = array_values(array_unique($workspaceUids));

        if ($userUids === [] || $workspaceUids === []) {
            return $this->emptyResults($userUids, $workspaceUids);
        }

        $ancestryRows = WorkspaceAncestry::query()
            ->whereIn('descendant_workspace_uid', $workspaceUids)
            ->orderBy('descendant_workspace_uid')
            ->orderBy('depth')
            ->get();
        /** @var array<string, list<array{workspaceUid: string, depth: int}>> $ancestorPaths */
        $ancestorPaths = [];

        foreach ($ancestryRows as $ancestry) {
            $ancestorPaths[$ancestry->descendant_workspace_uid][] = [
                'workspaceUid' => $ancestry->ancestor_workspace_uid,
                'depth' => $ancestry->depth,
            ];
        }

        return $this->resolveUsersForAncestorPaths($userUids, $workspaceUids, $ancestorPaths);
    }

    /**
     * Resolve memberships against supplied target-to-root paths. Hierarchy
     * previews use this to apply the same inheritance barriers as live reads
     * without first mutating the closure table.
     *
     * @param list<string> $userUids
     * @param list<string> $workspaceUids
     * @param array<string, list<array{workspaceUid: string, depth: int}>> $ancestorPaths
     * @return array<string, array<string, EffectiveWorkspaceMembership>>
     */
    public function resolveUsersForAncestorPaths(
        array $userUids,
        array $workspaceUids,
        array $ancestorPaths,
    ): array {
        $userUids = array_values(array_unique($userUids));
        $workspaceUids = array_values(array_unique($workspaceUids));
        $results = $this->emptyResults($userUids, $workspaceUids);

        if ($userUids === [] || $workspaceUids === []) {
            return $results;
        }

        $ancestorWorkspaceUids = [];

        foreach ($workspaceUids as $workspaceUid) {
            $path = $ancestorPaths[$workspaceUid] ?? [];
            usort(
                $path,
                static fn (array $left, array $right): int => $left['depth'] <=> $right['depth'],
            );
            $ancestorPaths[$workspaceUid] = $path;

            foreach ($path as $ancestor) {
                $ancestorWorkspaceUids[] = $ancestor['workspaceUid'];
            }
        }

        $ancestorWorkspaceUids = array_values(array_unique($ancestorWorkspaceUids));

        if ($ancestorWorkspaceUids === []) {
            return $results;
        }

        $membershipsByUser = WorkspaceMembership::query()
            ->whereIn('user_uid', $userUids)
            ->whereIn('workspace_uid', $ancestorWorkspaceUids)
            ->get()
            ->groupBy('user_uid');
        $workspacesByUid = Workspace::query()
            ->whereIn('uid', $ancestorWorkspaceUids)
            ->get(['uid', 'inherits_parent_memberships'])
            ->keyBy('uid');
        $exclusionRows = WorkspaceMembershipExclusion::query()
            ->whereIn('user_uid', $userUids)
            ->whereIn('workspace_uid', $ancestorWorkspaceUids)
            ->get(['workspace_uid', 'user_uid']);
        /** @var array<string, array<string, true>> $excludedWorkspacesByUser */
        $excludedWorkspacesByUser = [];

        foreach ($exclusionRows as $exclusion) {
            $excludedWorkspacesByUser[$exclusion->user_uid][$exclusion->workspace_uid] = true;
        }

        foreach ($userUids as $userUid) {
            $membershipsByWorkspace = $membershipsByUser
                ->get($userUid, collect())
                ->keyBy('workspace_uid');
            $excludedWorkspaces = $excludedWorkspacesByUser[$userUid] ?? [];

            foreach ($workspaceUids as $workspaceUid) {
                $origins = [];
                $ancestorAccessBlocked = false;

                foreach ($ancestorPaths[$workspaceUid] ?? [] as $ancestor) {
                    $boundaryWorkspace = $workspacesByUid->get($ancestor['workspaceUid']);

                    // Missing hierarchy participants indicate corrupt or partially
                    // migrated state. Do not let an authority origin cross them.
                    if (!$boundaryWorkspace instanceof Workspace) {
                        $ancestorAccessBlocked = true;

                        continue;
                    }

                    $membership = $membershipsByWorkspace->get($ancestor['workspaceUid']);

                    // A direct membership at a boundary is authoritative. Process it
                    // before the boundary cuts off memberships originating above it.
                    if (!$ancestorAccessBlocked && $membership instanceof WorkspaceMembership) {
                        $origins[] = new EffectiveWorkspaceMembershipOrigin(
                            membershipUid: $membership->uid,
                            workspaceUid: $membership->workspace_uid,
                            role: $membership->role,
                            depth: $ancestor['depth'],
                            isInherited: $ancestor['depth'] > 0,
                        );
                    }

                    if (
                        !$boundaryWorkspace->inherits_parent_memberships
                        || isset($excludedWorkspaces[$boundaryWorkspace->uid])
                    ) {
                        $ancestorAccessBlocked = true;
                    }
                }

                $results[$userUid][$workspaceUid] = new EffectiveWorkspaceMembership(
                    workspaceUid: $workspaceUid,
                    role: $this->strongestRole($origins),
                    origins: $origins,
                );
            }
        }

        return $results;
    }

    /**
     * @param list<string> $workspaceUids
     *
     * @return array<string, EffectiveWorkspaceMembership>
     */
    public function resolveMany(string $userUid, array $workspaceUids): array
    {
        $workspaceUids = array_values(array_unique($workspaceUids));
        $results = $this->resolveUsersForWorkspaces([$userUid], $workspaceUids);

        return $results[$userUid] ?? [];
    }

    /**
     * @return list<string>
     */
    public function workspaceUidsFor(string $userUid): array
    {
        /** @var list<string> $candidateWorkspaceUids */
        $candidateWorkspaceUids = WorkspaceAncestry::query()
            ->join(
                'workspace_memberships',
                'workspace_memberships.workspace_uid',
                '=',
                'workspace_ancestry.ancestor_workspace_uid',
            )
            ->where('workspace_memberships.user_uid', $userUid)
            ->orderBy('workspace_ancestry.descendant_workspace_uid')
            ->pluck('workspace_ancestry.descendant_workspace_uid')
            ->unique()
            ->values()
            ->all();

        if ($candidateWorkspaceUids === []) {
            return [];
        }

        $resolved = $this->resolveMany($userUid, $candidateWorkspaceUids);

        return array_values(array_filter(
            $candidateWorkspaceUids,
            static fn (string $workspaceUid): bool => ($resolved[$workspaceUid] ?? null)?->role
                instanceof WorkspaceRole,
        ));
    }

    /**
     * @param list<string> $workspaceUids
     * @param list<WorkspaceRole>|null $allowedRoles
     * @return list<string>
     */
    public function userUidsForAny(array $workspaceUids, ?array $allowedRoles = null): array
    {
        $workspaceUids = array_values(array_unique($workspaceUids));

        if ($workspaceUids === []) {
            return [];
        }

        /** @var list<string> $ancestorWorkspaceUids */
        $ancestorWorkspaceUids = WorkspaceAncestry::query()
            ->whereIn('descendant_workspace_uid', $workspaceUids)
            ->pluck('ancestor_workspace_uid')
            ->unique()
            ->values()
            ->all();
        /** @var list<string> $candidateUserUids */
        $candidateUserUids = WorkspaceMembership::query()
            ->whereIn('workspace_uid', $ancestorWorkspaceUids)
            ->orderBy('created_at')
            ->orderBy('uid')
            ->pluck('user_uid')
            ->unique()
            ->values()
            ->all();

        $userUids = [];
        $membershipsByUser = $this->resolveUsersForWorkspaces($candidateUserUids, $workspaceUids);

        foreach ($candidateUserUids as $userUid) {
            foreach ($membershipsByUser[$userUid] ?? [] as $membership) {
                if (
                    $membership->role instanceof WorkspaceRole
                    && ($allowedRoles === null || in_array($membership->role, $allowedRoles, true))
                ) {
                    $userUids[] = $userUid;

                    continue 2;
                }
            }
        }

        return $userUids;
    }

    /**
     * @param list<EffectiveWorkspaceMembershipOrigin> $origins
     */
    private function strongestRole(array $origins): ?WorkspaceRole
    {
        $strongestRole = null;

        foreach ($origins as $origin) {
            if ($strongestRole === null || $origin->role->rank() > $strongestRole->rank()) {
                $strongestRole = $origin->role;
            }
        }

        return $strongestRole;
    }

    /**
     * @param list<string> $userUids
     * @param list<string> $workspaceUids
     * @return array<string, array<string, EffectiveWorkspaceMembership>>
     */
    private function emptyResults(array $userUids, array $workspaceUids): array
    {
        $results = [];

        foreach ($userUids as $userUid) {
            foreach ($workspaceUids as $workspaceUid) {
                $results[$userUid][$workspaceUid] = new EffectiveWorkspaceMembership(
                    workspaceUid: $workspaceUid,
                    role: null,
                    origins: [],
                );
            }
        }

        return $results;
    }
}
