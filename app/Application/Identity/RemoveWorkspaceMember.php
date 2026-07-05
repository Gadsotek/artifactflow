<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\PageAccessGrantRevocationJournal;
use App\Application\PageCatalog\PageAccessRevision;
use App\Application\PageCatalog\PageOwnershipTransferRecorder;
use App\Application\PageCatalog\PagePresenceRevoker;
use App\Application\PageCatalog\PageSearchVectorUpdater;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipRemoval;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class RemoveWorkspaceMember
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private PageSearchVectorUpdater $searchVectors,
        private PageAccess $access,
        private WorkspaceAccess $workspaceAccess,
        private PageAccessRevision $revisions,
        private PagePresenceRevoker $presence,
        private PageOwnershipTransferRecorder $ownershipTransfers,
        private PageAccessGrantRevocationJournal $revocationJournal,
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
            // The locked set doubles as the presence snapshot: it is exactly the pages
            // whose presence subscribers may lose view once the membership is gone.
            $presencePageUids = $this->lockWorkspacePagesInCanonicalOrder($command->workspaceUid);

            $workspace = $this->lockRemovableWorkspace($command->workspaceUid);
            $this->ensureWorkspaceAdmin($actorUid, $workspace->uid);
            $membership = $this->lockRemovableMembership($command->membershipUid, $workspace->uid);
            $this->ensureAdminRemains($membership, $workspace->uid);

            $memberUserUid = $membership->user_uid;
            $previousRole = $membership->role;
            $membershipUid = $membership->uid;

            $reassignment = $this->reassignOwnedPages($membership, $command->replacementOwnerUserUid, $actorUid);

            $revokedInvitationCount = $this->revokeReusableInvitations($workspace->uid, $memberUserUid);
            $revokedPageAccessGrantCount = $this->revokeDirectPageAccessGrants(
                workspaceUid: $workspace->uid,
                memberUserUid: $memberUserUid,
                actorUid: $actorUid,
            );
            $invalidatedPreviewPageCount = $this->revisions->bumpWorkspace($workspace->uid)
                + $this->revisions->bumpPagesGrantedToWorkspace($workspace->uid);
            $membership->delete();

            // Materialize the removal so page-access authorization can reject
            // grants that predate it without reading the domain-event outbox.
            WorkspaceMembershipRemoval::query()->updateOrCreate(
                ['workspace_uid' => $workspace->uid, 'user_uid' => $memberUserUid],
                ['removed_at' => now()],
            );

            $this->recordRemoval(
                workspaceUid: $workspace->uid,
                membershipUid: $membershipUid,
                memberUserUid: $memberUserUid,
                actorUid: $actorUid,
                previousRole: $previousRole,
                reassignedPageCount: $reassignment['count'],
                replacementOwnerUserUid: $reassignment['replacementOwnerUserUid'],
                revokedInvitationCount: $revokedInvitationCount,
                revokedPageAccessGrantCount: $revokedPageAccessGrantCount,
                invalidatedPreviewPageCount: $invalidatedPreviewPageCount,
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

    /**
     * Lock every page this removal will touch, FOR UPDATE, one row at a time in
     * ascending uid order, before the caller takes the workspace row lock. Locking
     * the rows individually in sorted order (rather than a single ordered
     * SELECT ... FOR UPDATE, whose lock order follows the scan, not the ORDER BY)
     * guarantees the ascending acquisition order the catalog-wide page->workspace
     * discipline depends on. Returns the locked uids so they can double as the
     * presence snapshot.
     *
     * @return list<string>
     */
    private function lockWorkspacePagesInCanonicalOrder(string $workspaceUid): array
    {
        $pageUids = $this->collectPresencePageUids($workspaceUid);
        sort($pageUids);

        foreach ($pageUids as $pageUid) {
            Page::query()->whereKey($pageUid)->lockForUpdate()->first();
        }

        return $pageUids;
    }

    /**
     * Pages whose presence subscribers may lose view when this membership goes
     * away: every page in the workspace, plus pages elsewhere shared with it.
     *
     * @return list<string>
     */
    private function collectPresencePageUids(string $workspaceUid): array
    {
        /** @var list<string> $workspacePageUids */
        $workspacePageUids = Page::query()
            ->where('workspace_uid', $workspaceUid)
            ->orderBy('uid')
            ->pluck('uid')
            ->all();
        /** @var list<string> $grantedPageUids */
        $grantedPageUids = Page::query()
            ->where('workspace_uid', '<>', $workspaceUid)
            ->whereIn('uid', PageAccessGrant::query()
                ->select('page_uid')
                ->where('subject_type', PageAccessSubjectType::Workspace)
                ->where('subject_uid', $workspaceUid))
            ->orderBy('uid')
            ->pluck('uid')
            ->all();

        return array_values(array_unique([...$workspacePageUids, ...$grantedPageUids]));
    }

    /**
     * Transfers every page the departing member owns to the resolved
     * replacement owner. Locks the owned pages before resolving the
     * replacement so the reassignment cannot race a concurrent change.
     *
     * @return array{count: int, replacementOwnerUserUid: ?string}
     */
    private function reassignOwnedPages(
        WorkspaceMembership $membership,
        ?string $requestedReplacementOwnerUserUid,
        string $actorUid,
    ): array {
        $ownedPages = Page::query()
            ->where('workspace_uid', $membership->workspace_uid)
            ->where('owner_user_uid', $membership->user_uid)
            ->lockForUpdate()
            ->orderBy('uid')
            ->get();
        $replacementOwnerUserUid = $this->replacementOwnerUserUid(
            membership: $membership,
            ownedPageCount: $ownedPages->count(),
            requestedReplacementOwnerUserUid: $requestedReplacementOwnerUserUid,
        );

        foreach ($ownedPages as $page) {
            if ($replacementOwnerUserUid === null) {
                throw new LogicException('Owned pages require a resolved replacement owner.');
            }

            $page->forceFill(['owner_user_uid' => $replacementOwnerUserUid])->save();
            $this->searchVectors->refreshPage($page->uid);
            $this->ownershipTransfers->record(
                page: $page,
                previousOwnerUserUid: $membership->user_uid,
                newOwnerUserUid: $replacementOwnerUserUid,
                actorUid: $actorUid,
                reason: 'workspace_member_removed',
                summary: 'Page ownership transferred before workspace member removal.',
            );
        }

        return [
            'count' => $ownedPages->count(),
            'replacementOwnerUserUid' => $replacementOwnerUserUid,
        ];
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

    private function revokeReusableInvitations(string $workspaceUid, string $memberUserUid): int
    {
        $member = User::query()->find($memberUserUid);

        if (!$member instanceof User) {
            return 0;
        }

        return WorkspaceInvitation::query()
            ->where('workspace_uid', $workspaceUid)
            ->where('invited_email', strtolower(trim($member->email)))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function revokeDirectPageAccessGrants(string $workspaceUid, string $memberUserUid, string $actorUid): int
    {
        $grants = PageAccessGrant::query()
            ->select('page_access_grants.*')
            ->join('pages', 'page_access_grants.page_uid', '=', 'pages.uid')
            ->where('pages.workspace_uid', $workspaceUid)
            ->where('page_access_grants.subject_type', PageAccessSubjectType::User)
            ->where('page_access_grants.subject_uid', $memberUserUid)
            ->orderBy('page_access_grants.uid')
            ->lockForUpdate()
            ->get();

        foreach ($grants as $grant) {
            $grantUid = $grant->uid;
            $pageUid = $grant->page_uid;
            $subjectType = $grant->subject_type;
            $subjectUid = $grant->subject_uid;
            $role = $grant->role;
            $grant->delete();

            $this->revocationJournal->record(
                pageUid: $pageUid,
                grantUid: $grantUid,
                subjectType: $subjectType,
                subjectUid: $subjectUid,
                role: $role,
                actorUid: $actorUid,
                summary: 'Page access grant revoked before workspace member removal.',
                reason: 'workspace_member_removed',
            );
        }

        return $grants->count();
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

    private function replacementOwnerUserUid(
        WorkspaceMembership $membership,
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

        if ($replacementOwnerUserUid === $membership->user_uid) {
            throw new DomainRuleViolation('Replacement page owner must be a different workspace member.');
        }

        $replacementMembership = WorkspaceMembership::query()
            ->where('workspace_uid', $membership->workspace_uid)
            ->where('user_uid', $replacementOwnerUserUid)
            ->lockForUpdate()
            ->first();

        if (!$replacementMembership instanceof WorkspaceMembership) {
            throw new DomainRuleViolation('Replacement page owner must belong to this workspace.');
        }

        if ($replacementMembership->role === WorkspaceRole::Reader) {
            throw new DomainRuleViolation('Replacement page owner must be a workspace editor or admin.');
        }

        return $replacementMembership->user_uid;
    }
}
