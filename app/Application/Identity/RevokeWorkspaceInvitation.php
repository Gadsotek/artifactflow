<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class RevokeWorkspaceInvitation
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private WorkspaceInvitationAccess $access,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, RevokeWorkspaceInvitationCommand $command): WorkspaceInvitation
    {
        $actorUid = ActorId::fromUser($actor);

        return DB::transaction(function () use ($actorUid, $command): WorkspaceInvitation {
            $workspace = Workspace::query()
                ->lockForUpdate()
                ->find($command->workspaceUid);

            if (!$workspace instanceof Workspace) {
                throw new DomainRuleViolation('Workspace does not exist.');
            }

            $invitation = WorkspaceInvitation::query()
                ->where('uid', $command->invitationUid)
                ->where('workspace_uid', $workspace->uid)
                ->lockForUpdate()
                ->first();

            if (!$invitation instanceof WorkspaceInvitation) {
                throw new DomainRuleViolation('Workspace invitation does not exist.');
            }

            $actor = User::query()->find($actorUid);

            if (!$actor instanceof User) {
                throw new LogicException('The invitation actor no longer exists.');
            }

            $this->access->ensureCanRevoke($actor, $workspace, $invitation);

            if ($workspace->type !== WorkspaceType::Shared) {
                throw new DomainRuleViolation('Personal workspaces do not have invitations.');
            }

            if ($invitation->accepted_at instanceof DateTimeInterface) {
                throw new DomainRuleViolation('Accepted workspace invitations cannot be revoked.');
            }

            if ($invitation->revoked_at instanceof DateTimeInterface) {
                return $invitation;
            }

            $invitation->forceFill(['revoked_at' => now()])->save();

            $event = $this->events->record(
                eventType: DomainEventType::WorkspaceInvitationRevoked,
                aggregateType: 'workspace_invitation',
                aggregateUid: $invitation->uid,
                payload: [
                    'workspace_uid' => $workspace->uid,
                    'invitation_uid' => $invitation->uid,
                    'revoked_by_user_uid' => $actorUid,
                    'invited_email' => $invitation->invited_email,
                    'role' => $invitation->role->value,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'workspace_invitation',
                auditableUid: $invitation->uid,
                action: DomainEventType::WorkspaceInvitationRevoked,
                summary: 'Workspace invitation revoked.',
                metadata: [
                    'workspace_uid' => $workspace->uid,
                    'invited_email' => $invitation->invited_email,
                    'role' => $invitation->role->value,
                ],
            );

            return $invitation->refresh();
        });
    }
}
