<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\PageCatalog\PageAccess;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class AcceptWorkspaceInvitation
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private PageAccess $access,
    ) {
    }

    /**
     * @throws AuthorizationException
     * @throws DomainRuleViolation
     */
    public function handle(User $user, WorkspaceInvitation $invitation): WorkspaceMembership
    {
        $membership = DB::transaction(function () use ($user, $invitation): WorkspaceMembership {
            $userUid = ActorId::fromUser($user);
            $lockedInvitation = WorkspaceInvitation::query()
                ->whereKey($invitation->uid)
                ->lockForUpdate()
                ->first();

            if (!$lockedInvitation instanceof WorkspaceInvitation) {
                throw new DomainRuleViolation('Workspace invitation does not exist.');
            }

            $this->ensureInvitationCanBeAcceptedBy($lockedInvitation, $user);

            $existingMembership = WorkspaceMembership::query()
                ->where('workspace_uid', $lockedInvitation->workspace_uid)
                ->where('user_uid', $userUid)
                ->lockForUpdate()
                ->first();

            if ($lockedInvitation->accepted_by_user_uid !== null) {
                if ($existingMembership instanceof WorkspaceMembership) {
                    return $existingMembership;
                }

                throw new DomainRuleViolation('Accepted workspace invitations cannot be replayed.');
            }

            $membership = $existingMembership instanceof WorkspaceMembership
                ? $this->updateMembershipIfInvitationRoleIsStronger($existingMembership, $lockedInvitation->role)
                : $this->createMembership($lockedInvitation, $userUid);

            $lockedInvitation->forceFill([
                'accepted_by_user_uid' => $userUid,
                'accepted_at' => now(),
            ])->save();

            $event = $this->events->record(
                eventType: DomainEventType::WorkspaceInvitationAccepted,
                aggregateType: 'workspace_invitation',
                aggregateUid: $lockedInvitation->uid,
                payload: [
                    'workspace_uid' => $lockedInvitation->workspace_uid,
                    'invitation_uid' => $lockedInvitation->uid,
                    'accepted_by_user_uid' => $userUid,
                    'role' => $lockedInvitation->role->value,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $userUid,
                auditableType: 'workspace_invitation',
                auditableUid: $lockedInvitation->uid,
                action: DomainEventType::WorkspaceInvitationAccepted,
                summary: 'Workspace invitation accepted.',
                metadata: [
                    'workspace_uid' => $lockedInvitation->workspace_uid,
                    'accepted_by_user_uid' => $userUid,
                    'role' => $lockedInvitation->role->value,
                ],
            );

            return $membership;
        });

        $this->access->flushCache();

        return $membership;
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureInvitationCanBeAcceptedBy(WorkspaceInvitation $invitation, User $user): void
    {
        if ($invitation->revoked_at instanceof DateTimeInterface) {
            throw new DomainRuleViolation('Revoked workspace invitations cannot be accepted.');
        }

        if (!$invitation->expires_at instanceof DateTimeInterface) {
            throw new DomainRuleViolation('Expired workspace invitations cannot be accepted.');
        }

        if ($invitation->expires_at->getTimestamp() < now()->getTimestamp()) {
            throw new DomainRuleViolation('Expired workspace invitations cannot be accepted.');
        }

        if ($this->normalizeEmail($user->email) !== $invitation->invited_email) {
            throw new AuthorizationException('Only the invited user can accept this workspace invitation.');
        }
    }

    private function createMembership(WorkspaceInvitation $invitation, string $userUid): WorkspaceMembership
    {
        return WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $invitation->workspace_uid,
            'user_uid' => $userUid,
            'role' => $invitation->role,
            'accepted_at' => now(),
        ]);
    }

    private function updateMembershipIfInvitationRoleIsStronger(
        WorkspaceMembership $membership,
        WorkspaceRole $invitedRole,
    ): WorkspaceMembership {
        if ($membership->role->rank() >= $invitedRole->rank()) {
            return $membership;
        }

        $membership->forceFill([
            'role' => $invitedRole,
            'accepted_at' => $membership->accepted_at ?? now(),
        ])->save();

        return $membership->refresh();
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
