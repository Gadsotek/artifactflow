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
use App\Models\WorkspaceMembershipExclusion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class ExcludeInheritedWorkspaceMember
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private PageAccess $access,
        private WorkspaceAccess $workspaceAccess,
        private WorkspaceAuthorityImpact $authorityImpact,
        private PagePresenceRevoker $presence,
        private WorkspaceMemberAuthorityRetirement $retirement,
        private EffectiveWorkspaceMembershipResolver $memberships,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, ExcludeInheritedWorkspaceMemberCommand $command): void
    {
        $actorUid = ActorId::fromUser($actor);

        /** @var array{member_user_uid: string, page_uids: list<string>} $presenceRevocations */
        $presenceRevocations = DB::transaction(function () use ($actorUid, $command): array {
            $this->authorityImpact->acquireHierarchyLock();
            $descendantWorkspaceUids = $this->authorityImpact->descendantWorkspaceUids($command->workspaceUid);
            $presencePageUids = $this->authorityImpact->pageUids($descendantWorkspaceUids);
            $this->authorityImpact->lockPages($presencePageUids);
            $this->authorityImpact->lockWorkspaces($descendantWorkspaceUids);

            $workspace = Workspace::query()->whereKey($command->workspaceUid)->lockForUpdate()->first();

            if (!$workspace instanceof Workspace) {
                throw new DomainRuleViolation('Workspace does not exist.');
            }

            if ($workspace->type !== WorkspaceType::Shared) {
                throw new DomainRuleViolation('Personal workspace memberships cannot be excluded.');
            }

            $this->workspaceAccess->ensureAdmin(
                $actorUid,
                $workspace->uid,
                'Only workspace admins can remove members.',
            );

            $directMembership = WorkspaceMembership::query()
                ->where('workspace_uid', $workspace->uid)
                ->where('user_uid', $command->memberUserUid)
                ->lockForUpdate()
                ->first();

            if ($directMembership instanceof WorkspaceMembership) {
                throw new DomainRuleViolation('Direct workspace members must be removed through their membership.');
            }

            $existingExclusion = WorkspaceMembershipExclusion::query()
                ->where('workspace_uid', $workspace->uid)
                ->where('user_uid', $command->memberUserUid)
                ->lockForUpdate()
                ->first();

            if ($existingExclusion instanceof WorkspaceMembershipExclusion) {
                throw new DomainRuleViolation('This inherited member is already excluded from the workspace.');
            }

            $beforeMembership = $this->memberships->resolve($command->memberUserUid, $workspace->uid);

            if (!$beforeMembership->role instanceof WorkspaceRole || !$beforeMembership->isInherited()) {
                throw new DomainRuleViolation('Inherited workspace membership does not exist.');
            }

            $beforeRoles = $this->authorityImpact->rolesForUser(
                $command->memberUserUid,
                $descendantWorkspaceUids,
            );
            $exclusion = WorkspaceMembershipExclusion::query()->forceCreate([
                'workspace_uid' => $workspace->uid,
                'user_uid' => $command->memberUserUid,
                'excluded_by_user_uid' => $actorUid,
            ]);
            $afterRoles = $this->authorityImpact->rolesForUser(
                $command->memberUserUid,
                $descendantWorkspaceUids,
            );
            $retirement = $this->retirement->apply(
                memberUserUid: $command->memberUserUid,
                actorUid: $actorUid,
                affectedWorkspaceUids: $descendantWorkspaceUids,
                beforeRoles: $beforeRoles,
                afterRoles: $afterRoles,
                requestedReplacementOwnerUserUid: $command->replacementOwnerUserUid,
                reason: 'workspace_inherited_member_excluded',
                ownershipSummary: 'Page ownership transferred before inherited workspace access was removed.',
                grantRevocationSummary: 'Page access grant revoked before inherited workspace access was removed.',
            );

            $event = $this->events->record(
                eventType: DomainEventType::WorkspaceInheritedMembershipExcluded,
                aggregateType: 'workspace_membership_exclusion',
                aggregateUid: $exclusion->uid,
                payload: [
                    'workspace_uid' => $workspace->uid,
                    'workspace_membership_exclusion_uid' => $exclusion->uid,
                    'member_user_uid' => $command->memberUserUid,
                    'excluded_by_user_uid' => $actorUid,
                    'previous_role' => $beforeMembership->role->value,
                    'reassigned_page_count' => $retirement->reassignedPageCount,
                    'replacement_owner_user_uid' => $retirement->replacementOwnerUserUid,
                    'revoked_invitation_count' => $retirement->revokedInvitationCount,
                    'revoked_page_access_grant_count' => $retirement->revokedPageAccessGrantCount,
                    'invalidated_preview_page_count' => $retirement->invalidatedPreviewPageCount,
                    'presence_revocation_page_count' => count($presencePageUids),
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'workspace_membership_exclusion',
                auditableUid: $exclusion->uid,
                action: DomainEventType::WorkspaceInheritedMembershipExcluded,
                summary: 'Inherited workspace member removed.',
                metadata: [
                    'workspace_uid' => $workspace->uid,
                    'member_user_uid' => $command->memberUserUid,
                    'previous_role' => $beforeMembership->role->value,
                    'reassigned_page_count' => $retirement->reassignedPageCount,
                    'replacement_owner_user_uid' => $retirement->replacementOwnerUserUid,
                    'revoked_invitation_count' => $retirement->revokedInvitationCount,
                    'revoked_page_access_grant_count' => $retirement->revokedPageAccessGrantCount,
                    'invalidated_preview_page_count' => $retirement->invalidatedPreviewPageCount,
                    'presence_revocation_page_count' => count($presencePageUids),
                ],
            );

            return [
                'member_user_uid' => $command->memberUserUid,
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
}
