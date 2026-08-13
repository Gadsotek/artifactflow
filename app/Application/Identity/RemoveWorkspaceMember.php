<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\PagePresenceRevoker;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class RemoveWorkspaceMember
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private PageAccess $access,
        private WorkspaceAccess $workspaceAccess,
        private WorkspaceAuthorityImpact $authorityImpact,
        private PagePresenceRevoker $presence,
        private WorkspaceMemberAuthorityRetirement $retirement,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, RemoveWorkspaceMemberCommand $command): void
    {
        $actorUid = ActorId::fromUser($actor);

        // Lock ordering: this handler locks every page it will touch -- the workspace's
        // own pages plus pages elsewhere shared with it -- FOR UPDATE in ascending uid
        // order FIRST, and only then the workspace row. That matches the catalog-wide
        // page->workspace order a concurrent save takes (page then workspace), so the two
        // can no longer form the lock cycle Postgres would otherwise break by aborting one
        // side with a deadlock (40P01) -> 500. The workspace lock stays load-bearing (it
        // serialises the last-admin invariant) and the revision bump commits atomically
        // with the revoke; the retry below is now defense-in-depth for other concurrency
        // errors rather than the primary deadlock guard. All mutations live inside the
        // transaction, so a retry is a clean replay; the presence kick runs post-commit.
        //
        // Phantom-page window: a page created in this workspace AFTER the snapshot below
        // but before bumpWorkspace() is not in the pre-locked set, so bumpWorkspace()'s
        // workspace-scoped UPDATE would acquire its row lock after the workspace row --
        // the one place the ascending page->workspace discipline is broken, and where a
        // concurrent save holding that page and waiting on the workspace can still form a
        // cycle. The retry is the deliberate guard for exactly this case: on replay the
        // phantom is a committed page, gets pre-locked with the rest, and the bump
        // completes. The bump MUST stay workspace-scoped -- the cookieless artifact
        // preview authorizes solely by the signature over preview_access_revision, so it
        // is the only mechanism that revokes a removed member's already-minted preview
        // URLs. Narrowing it to the pre-locked snapshot would prevent the cycle but leak a
        // still-valid URL for a race-window page, so we accept the retry instead. See
        // WorkspaceMembershipLockOrderingTest::
        // test_removal_bumps_the_preview_revision_of_a_page_that_appears_after_the_presence_snapshot.
        /** @var array{member_user_uid: string, page_uids: list<string>} $presenceRevocations */
        $presenceRevocations = DB::transaction(function () use ($actorUid, $command): array {
            $this->authorityImpact->acquireHierarchyLock();
            $descendantWorkspaceUids = $this->authorityImpact->descendantWorkspaceUids($command->workspaceUid);
            // The locked set doubles as the presence snapshot: it is exactly the pages
            // whose presence subscribers may lose view once the membership is gone.
            $presencePageUids = $this->authorityImpact->pageUids($descendantWorkspaceUids);
            $this->authorityImpact->lockPages($presencePageUids);
            $this->authorityImpact->lockWorkspaces($descendantWorkspaceUids);

            $workspace = $this->lockRemovableWorkspace($command->workspaceUid);
            $this->ensureWorkspaceAdmin($actorUid, $workspace->uid);
            $membership = $this->lockRemovableMembership($command->membershipUid, $workspace->uid);
            $this->ensureAdminRemains($membership, $workspace->uid);

            $memberUserUid = $membership->user_uid;
            $previousRole = $membership->role;
            $membershipUid = $membership->uid;
            $beforeRoles = $this->authorityImpact->rolesForUser($memberUserUid, $descendantWorkspaceUids);

            $membership->delete();
            $afterRoles = $this->authorityImpact->rolesForUser($memberUserUid, $descendantWorkspaceUids);
            $retirement = $this->retirement->apply(
                memberUserUid: $memberUserUid,
                actorUid: $actorUid,
                affectedWorkspaceUids: $descendantWorkspaceUids,
                beforeRoles: $beforeRoles,
                afterRoles: $afterRoles,
                requestedReplacementOwnerUserUid: $command->replacementOwnerUserUid,
                reason: 'workspace_member_removed',
                ownershipSummary: 'Page ownership transferred before workspace member removal.',
                grantRevocationSummary: 'Page access grant revoked before workspace member removal.',
            );

            $this->recordRemoval(
                workspaceUid: $workspace->uid,
                membershipUid: $membershipUid,
                memberUserUid: $memberUserUid,
                actorUid: $actorUid,
                previousRole: $previousRole,
                reassignedPageCount: $retirement->reassignedPageCount,
                replacementOwnerUserUid: $retirement->replacementOwnerUserUid,
                revokedInvitationCount: $retirement->revokedInvitationCount,
                revokedPageAccessGrantCount: $retirement->revokedPageAccessGrantCount,
                invalidatedPreviewPageCount: $retirement->invalidatedPreviewPageCount,
                presencePageCount: count($presencePageUids),
            );

            return [
                'member_user_uid' => $memberUserUid,
                'page_uids' => $presencePageUids,
            ];
        }, attempts: 3);

        $this->access->flushCache();

        $member = User::query()->find($presenceRevocations['member_user_uid']);

        if ($member instanceof User) {
            $this->presence->kickUserFromPagesWhereViewLost(
                $member,
                Page::query()
                    ->whereIn('uid', $presenceRevocations['page_uids'])
                    ->orderBy('uid')
                    ->get(),
            );
        }
    }

    private function lockRemovableWorkspace(string $workspaceUid): Workspace
    {
        $workspace = Workspace::query()
            ->lockForUpdate()
            ->find($workspaceUid);

        if (!$workspace instanceof Workspace) {
            throw new DomainRuleViolation('Workspace does not exist.');
        }

        if ($workspace->type === WorkspaceType::Personal) {
            throw new DomainRuleViolation('Personal workspace memberships cannot be removed.');
        }

        return $workspace;
    }

    private function lockRemovableMembership(string $membershipUid, string $workspaceUid): WorkspaceMembership
    {
        $membership = WorkspaceMembership::query()
            ->where('uid', $membershipUid)
            ->where('workspace_uid', $workspaceUid)
            ->lockForUpdate()
            ->first();

        if (!$membership instanceof WorkspaceMembership) {
            throw new DomainRuleViolation('Workspace membership does not exist.');
        }

        return $membership;
    }

    private function recordRemoval(
        string $workspaceUid,
        string $membershipUid,
        string $memberUserUid,
        string $actorUid,
        WorkspaceRole $previousRole,
        int $reassignedPageCount,
        ?string $replacementOwnerUserUid,
        int $revokedInvitationCount,
        int $revokedPageAccessGrantCount,
        int $invalidatedPreviewPageCount,
        int $presencePageCount,
    ): void {
        $event = $this->events->record(
            eventType: DomainEventType::WorkspaceMembershipRemoved,
            aggregateType: 'workspace_membership',
            aggregateUid: $membershipUid,
            payload: [
                'workspace_uid' => $workspaceUid,
                'workspace_membership_uid' => $membershipUid,
                'member_user_uid' => $memberUserUid,
                'removed_by_user_uid' => $actorUid,
                'previous_role' => $previousRole->value,
                'reassigned_page_count' => $reassignedPageCount,
                'replacement_owner_user_uid' => $replacementOwnerUserUid,
                'revoked_invitation_count' => $revokedInvitationCount,
                'revoked_page_access_grant_count' => $revokedPageAccessGrantCount,
                'invalidated_preview_page_count' => $invalidatedPreviewPageCount,
                'presence_revocation_page_count' => $presencePageCount,
            ],
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUid,
            auditableType: 'workspace_membership',
            auditableUid: $membershipUid,
            action: DomainEventType::WorkspaceMembershipRemoved,
            summary: 'Workspace member removed.',
            metadata: [
                'workspace_uid' => $workspaceUid,
                'member_user_uid' => $memberUserUid,
                'previous_role' => $previousRole->value,
                'reassigned_page_count' => $reassignedPageCount,
                'replacement_owner_user_uid' => $replacementOwnerUserUid,
                'revoked_invitation_count' => $revokedInvitationCount,
                'revoked_page_access_grant_count' => $revokedPageAccessGrantCount,
                'invalidated_preview_page_count' => $invalidatedPreviewPageCount,
                'presence_revocation_page_count' => $presencePageCount,
            ],
        );
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureWorkspaceAdmin(string $actorUid, string $workspaceUid): void
    {
        $this->workspaceAccess->ensureAdmin($actorUid, $workspaceUid, 'Only workspace admins can remove members.');
    }

    private function ensureAdminRemains(WorkspaceMembership $membership, string $workspaceUid): void
    {
        if ($membership->role !== WorkspaceRole::Admin) {
            return;
        }

        $adminCount = WorkspaceMembership::query()
            ->where('workspace_uid', $workspaceUid)
            ->where('role', WorkspaceRole::Admin)
            ->count();

        if ($adminCount <= 1) {
            throw new DomainRuleViolation('A shared workspace must retain at least one admin.');
        }
    }
}
