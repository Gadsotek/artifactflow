<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\PageCatalog\DirectUserPageAccessGrantRevoker;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\PagePresenceRevoker;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAncestry;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipRemoval;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class ReparentWorkspace
{
    public function __construct(
        private WorkspaceHierarchyGraph $hierarchy,
        private EffectiveWorkspaceMembershipResolver $memberships,
        private WorkspaceAccess $workspaceAccess,
        private PageAccess $pageAccess,
        private PagePresenceRevoker $presence,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private PendingWorkspaceInvitationRevoker $invitationRevoker,
        private DirectUserPageAccessGrantRevoker $pageGrantRevoker,
    ) {
    }

    /**
     * Returns a consistent, non-secret impact summary without mutating the tree.
     * Page placement, grants, and membership writes share the hierarchy lock, so
     * the counts cannot acquire phantoms while this preview is calculated.
     *
     * @throws AuthorizationException
     */
    public function preview(User $actor, ReparentWorkspaceCommand $command): ReparentWorkspaceImpactPreview
    {
        $actorUid = ActorId::fromUser($actor);

        return DB::transaction(function () use ($actorUid, $command): ReparentWorkspaceImpactPreview {
            $this->hierarchy->acquireMutationLock();
            $workspace = $this->workspace($command->workspaceUid);
            $this->ensureCurrentHierarchyAuthority($actorUid, $workspace);
            $newParent = $this->newParent($actorUid, $command->newParentWorkspaceUid);
            $this->ensureSharedHierarchyParticipants($workspace, $newParent);
            $subtreeRows = $this->hierarchy->subtreeRows($workspace->uid);
            $subtreeWorkspaceUids = $this->descendantWorkspaceUids($subtreeRows);
            $newParentAncestorRows = $newParent instanceof Workspace
                ? $this->hierarchy->ancestorRows($newParent->uid)
                : [];
            $this->ensureNoCycle($newParent, $subtreeWorkspaceUids);
            $this->ensureDepthAllowed($subtreeRows, $newParentAncestorRows);
            $workspace = $this->lockWorkspacesAndReauthorize(
                $actorUid,
                $workspace,
                $workspace->parent_workspace_uid,
                $newParent,
                $subtreeWorkspaceUids,
                $newParentAncestorRows,
            );
            $affectedPageUids = $this->affectedPageUids($subtreeWorkspaceUids);
            $candidateUserUids = $this->candidateUserUids(
                subtreeWorkspaceUids: $subtreeWorkspaceUids,
                oldAncestorWorkspaceUids: $this->ancestorWorkspaceUids($workspace->uid),
                newParentAncestorRows: $newParentAncestorRows,
                affectedPageUids: $affectedPageUids,
            );
            $beforeRoles = $this->rolesByUserAndWorkspace($candidateUserUids, $subtreeWorkspaceUids);
            $afterRoles = $this->proposedRolesByUserAndWorkspace(
                $candidateUserUids,
                $workspace->uid,
                $subtreeWorkspaceUids,
                $newParentAncestorRows,
            );

            return $this->impactPreview(
                workspaceUid: $workspace->uid,
                newParentWorkspaceUid: $newParent?->uid,
                subtreeWorkspaceUids: $subtreeWorkspaceUids,
                affectedPageUids: $affectedPageUids,
                beforeRoles: $beforeRoles,
                afterRoles: $afterRoles,
            );
        }, attempts: 3);
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, ReparentWorkspaceCommand $command): Workspace
    {
        if (!$command->confirmed) {
            throw new DomainRuleViolation('Workspace hierarchy changes must be explicitly confirmed.');
        }

        $actorUid = ActorId::fromUser($actor);

        $result = DB::transaction(function () use ($actorUid, $command): ReparentWorkspaceResult {
            $this->hierarchy->acquireMutationLock();

            $workspace = $this->workspace($command->workspaceUid);
            $this->ensureCurrentHierarchyAuthority($actorUid, $workspace);
            $newParent = $this->newParent($actorUid, $command->newParentWorkspaceUid);
            $this->ensureSharedHierarchyParticipants($workspace, $newParent);

            $subtreeRows = $this->hierarchy->subtreeRows($workspace->uid);
            $subtreeWorkspaceUids = $this->descendantWorkspaceUids($subtreeRows);
            $newParentAncestorRows = $newParent instanceof Workspace
                ? $this->hierarchy->ancestorRows($newParent->uid)
                : [];
            $this->ensureNoCycle($newParent, $subtreeWorkspaceUids);
            $this->ensureDepthAllowed($subtreeRows, $newParentAncestorRows);

            $oldParentWorkspaceUid = $workspace->parent_workspace_uid;

            if ($oldParentWorkspaceUid === $command->newParentWorkspaceUid) {
                $this->lockWorkspacesAndReauthorize(
                    $actorUid,
                    $workspace,
                    $oldParentWorkspaceUid,
                    $newParent,
                    $subtreeWorkspaceUids,
                    $newParentAncestorRows,
                );

                return new ReparentWorkspaceResult($workspace, [], []);
            }

            $affectedPageUids = $this->affectedPageUids($subtreeWorkspaceUids);
            $this->lockPages($affectedPageUids);
            $workspace = $this->lockWorkspacesAndReauthorize(
                $actorUid,
                $workspace,
                $oldParentWorkspaceUid,
                $newParent,
                $subtreeWorkspaceUids,
                $newParentAncestorRows,
            );
            $this->ensureEveryWorkspaceHasDirectAdmin($subtreeWorkspaceUids);

            $candidateUserUids = $this->candidateUserUids(
                subtreeWorkspaceUids: $subtreeWorkspaceUids,
                oldAncestorWorkspaceUids: $this->ancestorWorkspaceUids($workspace->uid),
                newParentAncestorRows: $newParentAncestorRows,
                affectedPageUids: $affectedPageUids,
            );
            $beforeRoles = $this->rolesByUserAndWorkspace($candidateUserUids, $subtreeWorkspaceUids);
            $afterRoles = $this->proposedRolesByUserAndWorkspace(
                $candidateUserUids,
                $workspace->uid,
                $subtreeWorkspaceUids,
                $newParentAncestorRows,
            );
            $impact = $this->impactPreview(
                workspaceUid: $workspace->uid,
                newParentWorkspaceUid: $newParent?->uid,
                subtreeWorkspaceUids: $subtreeWorkspaceUids,
                affectedPageUids: $affectedPageUids,
                beforeRoles: $beforeRoles,
                afterRoles: $afterRoles,
            );

            if ($command->expectedImpact instanceof ReparentWorkspaceImpactPreview && !$impact->equals($command->expectedImpact)) {
                throw new DomainRuleViolation('Workspace hierarchy impact changed. Review the current impact before confirming again.');
            }

            $this->ensurePageOwnersRemainEligible(
                affectedPageUids: $affectedPageUids,
                subtreeWorkspaceUids: $subtreeWorkspaceUids,
                afterRoles: $afterRoles,
            );
            $revokedInvitationCount = $this->revokeInvitationsForReducedAuthority($beforeRoles, $afterRoles);

            $this->hierarchy->replaceParent(
                workspace: $workspace,
                newParentWorkspaceUid: $newParent?->uid,
                subtreeRows: $subtreeRows,
                newParentAncestorRows: $newParentAncestorRows,
            );

            $revokedPageAccessGrantCount = $this->revokeGrantsForLostMemberships(
                beforeRoles: $beforeRoles,
                afterRoles: $afterRoles,
                actorUid: $actorUid,
            );
            $reducedUserUids = $this->recordEffectiveRemovals(
                beforeRoles: $beforeRoles,
                afterRoles: $afterRoles,
            );
            $invalidatedPreviewPageCount = $this->invalidatePreviews($affectedPageUids);
            $this->recordHierarchyChange(
                workspaceUid: $workspace->uid,
                actorUid: $actorUid,
                previousParentWorkspaceUid: $oldParentWorkspaceUid,
                newParentWorkspaceUid: $newParent?->uid,
                movedWorkspaceCount: count($subtreeWorkspaceUids),
                affectedPageCount: count($affectedPageUids),
                affectedUserCount: count($candidateUserUids),
                gainedUserCount: $impact->gainedUserCount,
                reducedUserCount: count($reducedUserUids),
                revokedInvitationCount: $revokedInvitationCount,
                revokedPageAccessGrantCount: $revokedPageAccessGrantCount,
                invalidatedPreviewPageCount: $invalidatedPreviewPageCount,
            );

            return new ReparentWorkspaceResult(
                workspace: $workspace->refresh(),
                reducedUserUids: $reducedUserUids,
                affectedPageUids: $affectedPageUids,
            );
        }, attempts: 3);

        $this->pageAccess->flushCache();
        $this->revokeLostPresence($result);

        return $result->workspace;
    }

    private function workspace(string $workspaceUid): Workspace
    {
        $workspace = Workspace::query()->find($workspaceUid);

        if (!$workspace instanceof Workspace) {
            throw new DomainRuleViolation('Workspace does not exist.');
        }

        return $workspace;
    }

    private function newParent(string $actorUid, ?string $workspaceUid): ?Workspace
    {
        if ($workspaceUid === null) {
            return null;
        }

        $workspace = Workspace::query()->find($workspaceUid);

        // Authorize before type, ancestry, cycle, or depth classification. An
        // inaccessible workspace UID must look exactly like a missing one.
        if (
            !$workspace instanceof Workspace
            || !$this->workspaceAccess->isAdmin($actorUid, $workspace->uid)
        ) {
            throw new AuthorizationException('Only workspace admins can change workspace hierarchy.');
        }

        return $workspace;
    }

    private function ensureCurrentHierarchyAuthority(string $actorUid, Workspace $workspace): void
    {
        $this->ensureHierarchyAdmin($actorUid, $workspace->uid);

        if ($workspace->parent_workspace_uid !== null) {
            $this->ensureHierarchyAdmin($actorUid, $workspace->parent_workspace_uid);
        }
    }

    private function ensureSharedHierarchyParticipants(Workspace $workspace, ?Workspace $newParent): void
    {
        if (
            $workspace->type === WorkspaceType::Personal
            || ($newParent instanceof Workspace && $newParent->type === WorkspaceType::Personal)
        ) {
            throw new DomainRuleViolation('Personal workspaces cannot participate in a workspace hierarchy.');
        }
    }

    /**
     * @param list<string> $subtreeWorkspaceUids
     */
    private function ensureNoCycle(?Workspace $newParent, array $subtreeWorkspaceUids): void
    {
        if ($newParent instanceof Workspace && in_array($newParent->uid, $subtreeWorkspaceUids, true)) {
            throw new DomainRuleViolation('A workspace cannot be moved inside its own subtree.');
        }
    }

    /**
     * @param list<WorkspaceAncestry> $subtreeRows
     * @param list<WorkspaceAncestry> $newParentAncestorRows
     */
    private function ensureDepthAllowed(array $subtreeRows, array $newParentAncestorRows): void
    {
        if ($newParentAncestorRows === []) {
            return;
        }

        $subtreeDepth = $this->maximumDepth($subtreeRows);
        $parentDepth = $this->maximumDepth($newParentAncestorRows);

        if ($parentDepth + 1 + $subtreeDepth > 2) {
            throw new DomainRuleViolation('Workspace hierarchy is limited to three levels.');
        }
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
     * @param list<WorkspaceAncestry> $rows
     *
     * @return list<string>
     */
    private function descendantWorkspaceUids(array $rows): array
    {
        return array_values(array_unique(array_map(
            static fn (WorkspaceAncestry $row): string => $row->descendant_workspace_uid,
            $rows,
        )));
    }

    /**
     * @param list<WorkspaceAncestry> $rows
     *
     * @return list<string>
     */
    private function ancestorUidsFromRows(array $rows): array
    {
        return array_values(array_unique(array_map(
            static fn (WorkspaceAncestry $row): string => $row->ancestor_workspace_uid,
            $rows,
        )));
    }

    /**
     * @return list<string>
     */
    private function ancestorWorkspaceUids(string $workspaceUid): array
    {
        return $this->ancestorUidsFromRows($this->hierarchy->ancestorRows($workspaceUid));
    }

    /**
     * @param list<string> $subtreeWorkspaceUids
     * @param list<WorkspaceAncestry> $newParentAncestorRows
     */
    private function lockWorkspacesAndReauthorize(
        string $actorUid,
        Workspace $workspace,
        ?string $oldParentWorkspaceUid,
        ?Workspace $newParent,
        array $subtreeWorkspaceUids,
        array $newParentAncestorRows,
    ): Workspace {
        $workspaceUids = [
            ...$subtreeWorkspaceUids,
            ...$this->ancestorWorkspaceUids($workspace->uid),
            ...$this->ancestorUidsFromRows($newParentAncestorRows),
        ];

        if ($oldParentWorkspaceUid !== null) {
            $workspaceUids[] = $oldParentWorkspaceUid;
        }

        if ($newParent instanceof Workspace) {
            $workspaceUids[] = $newParent->uid;
        }

        $workspaceUids = array_values(array_unique($workspaceUids));
        sort($workspaceUids);

        foreach ($workspaceUids as $workspaceUid) {
            Workspace::query()->whereKey($workspaceUid)->lockForUpdate()->first();
        }

        $lockedWorkspace = Workspace::query()->find($workspace->uid);

        if (!$lockedWorkspace instanceof Workspace) {
            throw new DomainRuleViolation('Workspace does not exist.');
        }

        $this->ensureHierarchyAdmin($actorUid, $lockedWorkspace->uid);

        if ($oldParentWorkspaceUid !== null) {
            $this->ensureHierarchyAdmin($actorUid, $oldParentWorkspaceUid);
        }

        if ($newParent instanceof Workspace) {
            $this->ensureHierarchyAdmin($actorUid, $newParent->uid);
        }

        return $lockedWorkspace;
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureHierarchyAdmin(string $actorUid, string $workspaceUid): void
    {
        $this->workspaceAccess->ensureAdmin(
            $actorUid,
            $workspaceUid,
            'Only workspace admins can change workspace hierarchy.',
        );
    }

    /**
     * @param list<string> $subtreeWorkspaceUids
     *
     * @return list<string>
     */
    private function affectedPageUids(array $subtreeWorkspaceUids): array
    {
        /** @var list<string> $workspacePageUids */
        $workspacePageUids = Page::query()
            ->whereIn('workspace_uid', $subtreeWorkspaceUids)
            ->pluck('uid')
            ->all();
        /** @var list<string> $grantedPageUids */
        $grantedPageUids = PageAccessGrant::query()
            ->where('subject_type', PageAccessSubjectType::Workspace)
            ->whereIn('subject_uid', $subtreeWorkspaceUids)
            ->pluck('page_uid')
            ->all();
        $pageUids = array_values(array_unique([...$workspacePageUids, ...$grantedPageUids]));
        sort($pageUids);

        return $pageUids;
    }

    /**
     * @param list<string> $pageUids
     */
    private function lockPages(array $pageUids): void
    {
        foreach ($pageUids as $pageUid) {
            Page::query()->whereKey($pageUid)->lockForUpdate()->first();
        }
    }

    /**
     * @param list<string> $workspaceUids
     */
    private function ensureEveryWorkspaceHasDirectAdmin(array $workspaceUids): void
    {
        $adminWorkspaceUids = WorkspaceMembership::query()
            ->whereIn('workspace_uid', $workspaceUids)
            ->where('role', WorkspaceRole::Admin)
            ->pluck('workspace_uid')
            ->unique()
            ->values()
            ->all();

        if (count($adminWorkspaceUids) !== count($workspaceUids)) {
            throw new DomainRuleViolation('Every shared workspace must retain a direct admin.');
        }
    }

    /**
     * @param list<string> $subtreeWorkspaceUids
     * @param list<string> $oldAncestorWorkspaceUids
     * @param list<WorkspaceAncestry> $newParentAncestorRows
     * @param list<string> $affectedPageUids
     *
     * @return list<string>
     */
    private function candidateUserUids(
        array $subtreeWorkspaceUids,
        array $oldAncestorWorkspaceUids,
        array $newParentAncestorRows,
        array $affectedPageUids,
    ): array {
        $membershipWorkspaceUids = array_values(array_unique([
            ...$subtreeWorkspaceUids,
            ...$oldAncestorWorkspaceUids,
            ...$this->ancestorUidsFromRows($newParentAncestorRows),
        ]));
        /** @var list<string> $membershipUserUids */
        $membershipUserUids = WorkspaceMembership::query()
            ->whereIn('workspace_uid', $membershipWorkspaceUids)
            ->pluck('user_uid')
            ->all();
        /** @var list<string> $ownerUserUids */
        $ownerUserUids = Page::query()
            ->whereIn('uid', $affectedPageUids)
            ->pluck('owner_user_uid')
            ->all();
        $userUids = array_values(array_unique([...$membershipUserUids, ...$ownerUserUids]));
        sort($userUids);

        return $userUids;
    }

    /**
     * @param list<string> $userUids
     * @param list<string> $workspaceUids
     *
     * @return array<string, array<string, WorkspaceRole|null>>
     */
    private function rolesByUserAndWorkspace(array $userUids, array $workspaceUids): array
    {
        $roles = [];
        $membershipsByUser = $this->memberships->resolveUsersForWorkspaces($userUids, $workspaceUids);

        foreach ($userUids as $userUid) {
            foreach ($workspaceUids as $workspaceUid) {
                $roles[$userUid][$workspaceUid] = $membershipsByUser[$userUid][$workspaceUid]->role;
            }
        }

        return $roles;
    }

    /**
     * Resolve roles against the proposed ancestry without mutating the closure
     * table. Internal subtree ancestry stays unchanged; every moved workspace
     * additionally receives the proposed parent's ancestor chain.
     *
     * @param list<string> $userUids
     * @param list<string> $subtreeWorkspaceUids
     * @param list<WorkspaceAncestry> $newParentAncestorRows
     * @return array<string, array<string, WorkspaceRole|null>>
     */
    private function proposedRolesByUserAndWorkspace(
        array $userUids,
        string $movedWorkspaceUid,
        array $subtreeWorkspaceUids,
        array $newParentAncestorRows,
    ): array {
        $internalRows = WorkspaceAncestry::query()
            ->whereIn('ancestor_workspace_uid', $subtreeWorkspaceUids)
            ->whereIn('descendant_workspace_uid', $subtreeWorkspaceUids)
            ->orderBy('descendant_workspace_uid')
            ->orderBy('depth')
            ->get();
        /** @var array<string, list<WorkspaceAncestry>> $internalRowsByDescendant */
        $internalRowsByDescendant = [];

        foreach ($internalRows as $row) {
            $internalRowsByDescendant[$row->descendant_workspace_uid][] = $row;
        }

        /** @var array<string, list<array{workspaceUid: string, depth: int}>> $proposedPaths */
        $proposedPaths = [];

        foreach ($subtreeWorkspaceUids as $workspaceUid) {
            $movedRootDepth = null;

            foreach ($internalRowsByDescendant[$workspaceUid] ?? [] as $internalRow) {
                $proposedPaths[$workspaceUid][] = [
                    'workspaceUid' => $internalRow->ancestor_workspace_uid,
                    'depth' => $internalRow->depth,
                ];

                if ($internalRow->ancestor_workspace_uid === $movedWorkspaceUid) {
                    $movedRootDepth = $internalRow->depth;
                }
            }

            if ($movedRootDepth === null) {
                continue;
            }

            foreach ($newParentAncestorRows as $newAncestor) {
                $proposedPaths[$workspaceUid][] = [
                    'workspaceUid' => $newAncestor->ancestor_workspace_uid,
                    'depth' => $movedRootDepth + 1 + $newAncestor->depth,
                ];
            }
        }

        $roles = [];
        $proposedMemberships = $this->memberships->resolveUsersForAncestorPaths(
            $userUids,
            $subtreeWorkspaceUids,
            $proposedPaths,
        );

        foreach ($userUids as $userUid) {
            foreach ($subtreeWorkspaceUids as $workspaceUid) {
                $roles[$userUid][$workspaceUid] = $proposedMemberships[$userUid][$workspaceUid]->role;
            }
        }

        return $roles;
    }

    /**
     * @param list<string> $subtreeWorkspaceUids
     * @param list<string> $affectedPageUids
     * @param array<string, array<string, WorkspaceRole|null>> $beforeRoles
     * @param array<string, array<string, WorkspaceRole|null>> $afterRoles
     */
    private function impactPreview(
        string $workspaceUid,
        ?string $newParentWorkspaceUid,
        array $subtreeWorkspaceUids,
        array $affectedPageUids,
        array $beforeRoles,
        array $afterRoles,
    ): ReparentWorkspaceImpactPreview {
        $gainedUserUids = [];
        $reducedUserUids = [];

        foreach ($beforeRoles as $userUid => $workspaceRoles) {
            foreach ($workspaceRoles as $descendantWorkspaceUid => $beforeRole) {
                $afterRole = $afterRoles[$userUid][$descendantWorkspaceUid] ?? null;

                if ($this->roleRank($afterRole) > $this->roleRank($beforeRole)) {
                    $gainedUserUids[] = $userUid;
                }

                if ($this->roleRank($afterRole) < $this->roleRank($beforeRole)) {
                    $reducedUserUids[] = $userUid;
                }
            }
        }

        return new ReparentWorkspaceImpactPreview(
            workspaceUid: $workspaceUid,
            newParentWorkspaceUid: $newParentWorkspaceUid,
            movedWorkspaceCount: count($subtreeWorkspaceUids),
            affectedPageCount: count($affectedPageUids),
            gainedUserCount: count(array_unique($gainedUserUids)),
            reducedUserCount: count(array_unique($reducedUserUids)),
        );
    }

    /**
     * @param array<string, array<string, WorkspaceRole|null>> $beforeRoles
     * @param array<string, array<string, WorkspaceRole|null>> $afterRoles
     */
    private function revokeInvitationsForReducedAuthority(array $beforeRoles, array $afterRoles): int
    {
        $count = 0;

        foreach ($beforeRoles as $userUid => $workspaceRoles) {
            $reducedWorkspaceUids = [];

            foreach ($workspaceRoles as $workspaceUid => $beforeRole) {
                $afterRole = $afterRoles[$userUid][$workspaceUid] ?? null;

                if ($this->roleRank($afterRole) < $this->roleRank($beforeRole)) {
                    $reducedWorkspaceUids[] = $workspaceUid;
                }
            }

            $count += $this->invitationRevoker->forUserAcrossWorkspaces($userUid, $reducedWorkspaceUids);
        }

        return $count;
    }

    /**
     * @param list<string> $affectedPageUids
     * @param list<string> $subtreeWorkspaceUids
     * @param array<string, array<string, WorkspaceRole|null>> $afterRoles
     */
    private function ensurePageOwnersRemainEligible(
        array $affectedPageUids,
        array $subtreeWorkspaceUids,
        array $afterRoles,
    ): void {
        $pages = Page::query()
            ->whereIn('uid', $affectedPageUids)
            ->whereIn('workspace_uid', $subtreeWorkspaceUids)
            ->get(['uid', 'workspace_uid', 'owner_user_uid']);

        foreach ($pages as $page) {
            $ownerRole = $afterRoles[$page->owner_user_uid][$page->workspace_uid] ?? null;

            if (!$ownerRole instanceof WorkspaceRole || !$ownerRole->canWritePages()) {
                throw new DomainRuleViolation(
                    'Reassign pages whose owners would lose workspace edit access before moving this workspace.',
                );
            }
        }
    }

    /**
     * @param array<string, array<string, WorkspaceRole|null>> $beforeRoles
     * @param array<string, array<string, WorkspaceRole|null>> $afterRoles
     *
     * @return list<string>
     */
    private function recordEffectiveRemovals(array $beforeRoles, array $afterRoles): array
    {
        $reducedUserUids = [];

        foreach ($beforeRoles as $userUid => $workspaceRoles) {
            foreach ($workspaceRoles as $workspaceUid => $beforeRole) {
                $afterRole = $afterRoles[$userUid][$workspaceUid] ?? null;

                if ($this->roleRank($afterRole) >= $this->roleRank($beforeRole)) {
                    continue;
                }

                $reducedUserUids[] = $userUid;

                if ($afterRole === null) {
                    WorkspaceMembershipRemoval::query()->updateOrCreate(
                        ['workspace_uid' => $workspaceUid, 'user_uid' => $userUid],
                        ['removed_at' => now()],
                    );
                }
            }
        }

        return array_values(array_unique($reducedUserUids));
    }

    /**
     * @param array<string, array<string, WorkspaceRole|null>> $beforeRoles
     * @param array<string, array<string, WorkspaceRole|null>> $afterRoles
     */
    private function revokeGrantsForLostMemberships(
        array $beforeRoles,
        array $afterRoles,
        string $actorUid,
    ): int {
        $count = 0;

        foreach ($beforeRoles as $userUid => $workspaceRoles) {
            $lostWorkspaceUids = [];

            foreach ($workspaceRoles as $workspaceUid => $beforeRole) {
                $afterRole = $afterRoles[$userUid][$workspaceUid] ?? null;

                if ($beforeRole instanceof WorkspaceRole && $afterRole === null) {
                    $lostWorkspaceUids[] = $workspaceUid;
                }
            }

            $count += $this->pageGrantRevoker->forUserAcrossWorkspaces(
                userUid: $userUid,
                workspaceUids: $lostWorkspaceUids,
                actorUid: $actorUid,
                summary: 'Page access grant revoked after workspace hierarchy removed effective membership.',
                reason: 'workspace_hierarchy_membership_lost',
            );
        }

        return $count;
    }

    private function roleRank(?WorkspaceRole $role): int
    {
        return $role?->rank() ?? 0;
    }

    /**
     * @param list<string> $pageUids
     */
    private function invalidatePreviews(array $pageUids): int
    {
        if ($pageUids === []) {
            return 0;
        }

        return Page::query()
            ->whereIn('uid', $pageUids)
            ->increment('preview_access_revision');
    }

    private function recordHierarchyChange(
        string $workspaceUid,
        string $actorUid,
        ?string $previousParentWorkspaceUid,
        ?string $newParentWorkspaceUid,
        int $movedWorkspaceCount,
        int $affectedPageCount,
        int $affectedUserCount,
        int $gainedUserCount,
        int $reducedUserCount,
        int $revokedInvitationCount,
        int $revokedPageAccessGrantCount,
        int $invalidatedPreviewPageCount,
    ): void {
        $metadata = [
            'workspace_uid' => $workspaceUid,
            'changed_by_user_uid' => $actorUid,
            'previous_parent_workspace_uid' => $previousParentWorkspaceUid,
            'new_parent_workspace_uid' => $newParentWorkspaceUid,
            'moved_workspace_count' => $movedWorkspaceCount,
            'affected_page_count' => $affectedPageCount,
            'affected_user_count' => $affectedUserCount,
            'gained_user_count' => $gainedUserCount,
            'reduced_user_count' => $reducedUserCount,
            'revoked_invitation_count' => $revokedInvitationCount,
            'revoked_page_access_grant_count' => $revokedPageAccessGrantCount,
            'invalidated_preview_page_count' => $invalidatedPreviewPageCount,
        ];
        $event = $this->events->record(
            eventType: DomainEventType::WorkspaceHierarchyChanged,
            aggregateType: 'workspace',
            aggregateUid: $workspaceUid,
            payload: $metadata,
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUid,
            auditableType: 'workspace',
            auditableUid: $workspaceUid,
            action: DomainEventType::WorkspaceHierarchyChanged,
            summary: 'Workspace hierarchy changed.',
            metadata: $metadata,
        );
    }

    private function revokeLostPresence(ReparentWorkspaceResult $result): void
    {
        if ($result->reducedUserUids === [] || $result->affectedPageUids === []) {
            return;
        }

        $pages = Page::query()
            ->whereIn('uid', $result->affectedPageUids)
            ->orderBy('uid')
            ->get();
        $users = User::query()
            ->whereIn('uid', $result->reducedUserUids)
            ->orderBy('uid')
            ->get();

        foreach ($users as $user) {
            $this->presence->kickUserFromPagesWhereViewLost($user, $pages);
        }
    }
}
