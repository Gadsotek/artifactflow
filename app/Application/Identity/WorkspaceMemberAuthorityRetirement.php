<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\PageCatalog\DirectUserPageAccessGrantRevoker;
use App\Application\PageCatalog\PageAccessRevision;
use App\Application\PageCatalog\PageOwnershipTransferRecorder;
use App\Application\PageCatalog\PageSearchVectorUpdater;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Models\Page;
use App\Models\WorkspaceMembershipRemoval;
use LogicException;

/**
 * Applies the shared security cleanup after a workspace authority mutation.
 * The caller owns locking and the mutation itself; this service computes the
 * before/after loss set and retires every capability derived from that set.
 */
final readonly class WorkspaceMemberAuthorityRetirement
{
    public function __construct(
        private PageSearchVectorUpdater $searchVectors,
        private WorkspaceAuthorityImpact $authorityImpact,
        private PageAccessRevision $revisions,
        private PageOwnershipTransferRecorder $ownershipTransfers,
        private DirectUserPageAccessGrantRevoker $pageGrantRevoker,
        private PendingWorkspaceInvitationRevoker $invitationRevoker,
    ) {
    }

    /**
     * @param list<string> $affectedWorkspaceUids
     * @param array<string, WorkspaceRole|null> $beforeRoles
     * @param array<string, WorkspaceRole|null> $afterRoles
     */
    public function apply(
        string $memberUserUid,
        string $actorUid,
        array $affectedWorkspaceUids,
        array $beforeRoles,
        array $afterRoles,
        ?string $requestedReplacementOwnerUserUid,
        string $reason,
        string $ownershipSummary,
        string $grantRevocationSummary,
    ): WorkspaceMemberAuthorityRetirementResult {
        $lostWorkspaceUids = $this->lostWorkspaceUids($beforeRoles, $afterRoles);
        $reducedWorkspaceUids = $this->reducedWorkspaceUids($beforeRoles, $afterRoles);
        $lostWriteWorkspaceUids = $this->lostWriteWorkspaceUids($beforeRoles, $afterRoles);
        $revokedInvitationCount = $this->invitationRevoker->forUserAcrossWorkspaces(
            $memberUserUid,
            $reducedWorkspaceUids,
        );
        $reassignment = $this->reassignOwnedPages(
            memberUserUid: $memberUserUid,
            affectedWorkspaceUids: $lostWriteWorkspaceUids,
            requestedReplacementOwnerUserUid: $requestedReplacementOwnerUserUid,
            actorUid: $actorUid,
            reason: $reason,
            summary: $ownershipSummary,
        );
        $revokedPageAccessGrantCount = $this->pageGrantRevoker->forUserAcrossWorkspaces(
            userUid: $memberUserUid,
            workspaceUids: $lostWorkspaceUids,
            actorUid: $actorUid,
            summary: $grantRevocationSummary,
            reason: $reason,
        );
        $invalidatedPreviewPageCount = $this->invalidateWorkspaceReach($affectedWorkspaceUids);

        // Materialize complete access loss so page grants predating it cannot
        // resurrect authority without consulting the event journal.
        $this->recordEffectiveMembershipRemovals($lostWorkspaceUids, $memberUserUid);

        return new WorkspaceMemberAuthorityRetirementResult(
            reassignedPageCount: $reassignment['count'],
            replacementOwnerUserUid: $reassignment['replacementOwnerUserUid'],
            revokedInvitationCount: $revokedInvitationCount,
            revokedPageAccessGrantCount: $revokedPageAccessGrantCount,
            invalidatedPreviewPageCount: $invalidatedPreviewPageCount,
        );
    }

    /**
     * @param list<string> $affectedWorkspaceUids
     * @return array{count: int, replacementOwnerUserUid: ?string}
     */
    private function reassignOwnedPages(
        string $memberUserUid,
        array $affectedWorkspaceUids,
        ?string $requestedReplacementOwnerUserUid,
        string $actorUid,
        string $reason,
        string $summary,
    ): array {
        $ownedPages = Page::query()
            ->whereIn('workspace_uid', $affectedWorkspaceUids)
            ->where('owner_user_uid', $memberUserUid)
            ->lockForUpdate()
            ->orderBy('uid')
            ->get();
        /** @var list<string> $ownedWorkspaceUids */
        $ownedWorkspaceUids = $ownedPages->pluck('workspace_uid')->unique()->values()->all();
        $replacementOwnerUserUid = $this->replacementOwnerUserUid(
            memberUserUid: $memberUserUid,
            affectedWorkspaceUids: $ownedWorkspaceUids,
            ownedPageCount: $ownedPages->count(),
            requestedReplacementOwnerUserUid: $requestedReplacementOwnerUserUid,
        );

        foreach ($ownedPages as $page) {
            if ($replacementOwnerUserUid === null) {
                throw new LogicException('Owned pages require a resolved replacement owner.');
            }

            $page->forceFill([
                'owner_user_uid' => $replacementOwnerUserUid,
                'metadata_revision' => $page->metadata_revision + 1,
            ])->save();
            $this->searchVectors->refreshPage($page->uid);
            $this->ownershipTransfers->record(
                page: $page,
                previousOwnerUserUid: $memberUserUid,
                newOwnerUserUid: $replacementOwnerUserUid,
                actorUid: $actorUid,
                reason: $reason,
                summary: $summary,
            );
        }

        return [
            'count' => $ownedPages->count(),
            'replacementOwnerUserUid' => $replacementOwnerUserUid,
        ];
    }

    /**
     * @param list<string> $affectedWorkspaceUids
     */
    private function replacementOwnerUserUid(
        string $memberUserUid,
        array $affectedWorkspaceUids,
        int $ownedPageCount,
        ?string $requestedReplacementOwnerUserUid,
    ): ?string {
        if ($ownedPageCount === 0) {
            return null;
        }

        $replacementOwnerUserUid = $requestedReplacementOwnerUserUid === null
            ? ''
            : trim($requestedReplacementOwnerUserUid);

        if ($replacementOwnerUserUid === '') {
            throw new DomainRuleViolation('A replacement owner is required for pages owned by this member.');
        }

        if ($replacementOwnerUserUid === $memberUserUid) {
            throw new DomainRuleViolation('Replacement page owner must be a different workspace member.');
        }

        $replacementRoles = $this->authorityImpact->rolesForUser(
            $replacementOwnerUserUid,
            $affectedWorkspaceUids,
        );

        foreach ($affectedWorkspaceUids as $workspaceUid) {
            $replacementRole = $replacementRoles[$workspaceUid] ?? null;

            if (!$replacementRole instanceof WorkspaceRole) {
                throw new DomainRuleViolation('Replacement page owner must belong to this workspace.');
            }

            if (!$replacementRole->canWritePages()) {
                throw new DomainRuleViolation('Replacement page owner must be a workspace editor or admin.');
            }
        }

        return $replacementOwnerUserUid;
    }

    /**
     * @param array<string, WorkspaceRole|null> $beforeRoles
     * @param array<string, WorkspaceRole|null> $afterRoles
     * @return list<string>
     */
    private function lostWorkspaceUids(array $beforeRoles, array $afterRoles): array
    {
        $workspaceUids = [];

        foreach ($beforeRoles as $workspaceUid => $beforeRole) {
            if ($beforeRole instanceof WorkspaceRole && ($afterRoles[$workspaceUid] ?? null) === null) {
                $workspaceUids[] = $workspaceUid;
            }
        }

        return $workspaceUids;
    }

    /**
     * @param array<string, WorkspaceRole|null> $beforeRoles
     * @param array<string, WorkspaceRole|null> $afterRoles
     * @return list<string>
     */
    private function reducedWorkspaceUids(array $beforeRoles, array $afterRoles): array
    {
        $workspaceUids = [];

        foreach ($beforeRoles as $workspaceUid => $beforeRole) {
            $afterRole = $afterRoles[$workspaceUid] ?? null;

            if (($afterRole?->rank() ?? 0) < ($beforeRole?->rank() ?? 0)) {
                $workspaceUids[] = $workspaceUid;
            }
        }

        return $workspaceUids;
    }

    /**
     * @param array<string, WorkspaceRole|null> $beforeRoles
     * @param array<string, WorkspaceRole|null> $afterRoles
     * @return list<string>
     */
    private function lostWriteWorkspaceUids(array $beforeRoles, array $afterRoles): array
    {
        $workspaceUids = [];

        foreach ($beforeRoles as $workspaceUid => $beforeRole) {
            $afterRole = $afterRoles[$workspaceUid] ?? null;

            if ($beforeRole?->canWritePages() === true && $afterRole?->canWritePages() !== true) {
                $workspaceUids[] = $workspaceUid;
            }
        }

        return $workspaceUids;
    }

    /**
     * @param list<string> $workspaceUids
     */
    private function recordEffectiveMembershipRemovals(array $workspaceUids, string $memberUserUid): void
    {
        foreach ($workspaceUids as $workspaceUid) {
            WorkspaceMembershipRemoval::query()->updateOrCreate(
                ['workspace_uid' => $workspaceUid, 'user_uid' => $memberUserUid],
                ['removed_at' => now()],
            );
        }
    }

    /**
     * @param list<string> $workspaceUids
     */
    private function invalidateWorkspaceReach(array $workspaceUids): int
    {
        $count = 0;

        foreach ($workspaceUids as $workspaceUid) {
            $count += $this->revisions->bumpWorkspace($workspaceUid);
            $count += $this->revisions->bumpPagesGrantedToWorkspace($workspaceUid);
        }

        return $count;
    }
}
