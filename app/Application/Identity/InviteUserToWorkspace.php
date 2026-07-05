<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;

final readonly class InviteUserToWorkspace
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
    public function handle(User $actor, InviteUserToWorkspaceCommand $command): WorkspaceInvitation
    {
        $invitedEmail = $this->normalizeEmail($command->email);
        $workspaceUid = $this->requireWorkspaceUid($command->workspaceUid);
        $role = $command->role;

        return DB::transaction(function () use ($actor, $workspaceUid, $invitedEmail, $role): WorkspaceInvitation {
            $actorUid = ActorId::fromUser($actor);
            $workspace = Workspace::query()->lockForUpdate()->find($workspaceUid);

            if (!$workspace instanceof Workspace) {
                throw new DomainRuleViolation('Workspace does not exist.');
            }

            $this->access->ensureCanInvite($actor, $workspace, $role);

            if ($workspace->type === WorkspaceType::Personal) {
                throw new DomainRuleViolation('Personal workspaces cannot invite members.');
            }

            $existingInvitation = WorkspaceInvitation::query()
                ->where('workspace_uid', $workspaceUid)
                ->where('invited_email', $invitedEmail)
                ->lockForUpdate()
                ->first();

            if ($existingInvitation instanceof WorkspaceInvitation) {
                return $this->handleExistingInvitation($actor, $workspace, $existingInvitation, $role);
            }

            $invitation = WorkspaceInvitation::query()->forceCreate([
                'workspace_uid' => $workspaceUid,
                'invited_email' => $invitedEmail,
                'role' => $role,
                'invited_by_user_uid' => $actorUid,
                'expires_at' => $this->newExpiration(),
            ]);

            $event = $this->events->record(
                eventType: DomainEventType::WorkspaceInvitationCreated,
                aggregateType: 'workspace_invitation',
                aggregateUid: $invitation->uid,
                payload: [
                    'workspace_uid' => $workspaceUid,
                    'invitation_uid' => $invitation->uid,
                    'invited_by_user_uid' => $actorUid,
                    'invited_email' => $invitation->invited_email,
                    'role' => $role->value,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'workspace_invitation',
                auditableUid: $invitation->uid,
                action: DomainEventType::WorkspaceInvitationCreated,
                summary: 'Workspace invitation created.',
                metadata: [
                    'workspace_uid' => $workspaceUid,
                    'invited_email' => $invitation->invited_email,
                    'role' => $role->value,
                ],
            );

            $this->queueInvitationEmail($actor, $workspace, $invitation);

            return $invitation;
        });
    }

    private function handleExistingInvitation(
        User $actor,
        Workspace $workspace,
        WorkspaceInvitation $invitation,
        WorkspaceRole $role,
    ): WorkspaceInvitation {
        $actorUid = ActorId::fromUser($actor);
        $workspaceUid = $workspace->uid;

        if ($invitation->accepted_at instanceof DateTimeInterface) {
            throw new DomainRuleViolation(
                'This workspace invitation has already been accepted. Change the member role instead.',
            );
        }

        $previousState = $this->inactiveState($invitation);

        if ($previousState !== null) {
            return $this->reactivateInvitation(
                actor: $actor,
                workspace: $workspace,
                invitation: $invitation,
                role: $role,
                previousState: $previousState,
            );
        }

        if ($invitation->role === $role) {
            return $invitation;
        }

        $previousRole = $invitation->role;

        $invitation->forceFill([
            'role' => $role,
            'invited_by_user_uid' => $actorUid,
            'expires_at' => $this->newExpiration(),
        ])->save();

        $event = $this->events->record(
            eventType: DomainEventType::WorkspaceInvitationRoleChanged,
            aggregateType: 'workspace_invitation',
            aggregateUid: $invitation->uid,
            payload: [
                'workspace_uid' => $workspaceUid,
                'invitation_uid' => $invitation->uid,
                'invited_by_user_uid' => $actorUid,
                'invited_email' => $invitation->invited_email,
                'previous_role' => $previousRole->value,
                'new_role' => $role->value,
            ],
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUid,
            auditableType: 'workspace_invitation',
            auditableUid: $invitation->uid,
            action: DomainEventType::WorkspaceInvitationRoleChanged,
            summary: 'Workspace invitation role changed.',
            metadata: [
                'workspace_uid' => $workspaceUid,
                'invited_email' => $invitation->invited_email,
                'previous_role' => $previousRole->value,
                'new_role' => $role->value,
            ],
        );

        $invitation = $invitation->refresh();
        $this->queueInvitationEmail($actor, $workspace, $invitation);

        return $invitation;
    }

    private function inactiveState(WorkspaceInvitation $invitation): ?string
    {
        if ($invitation->revoked_at instanceof DateTimeInterface) {
            return 'revoked';
        }

        if (!$invitation->expires_at instanceof DateTimeInterface) {
            return 'expired';
        }

        if (
            $invitation->expires_at->getTimestamp() < now()->getTimestamp()
        ) {
            return 'expired';
        }

        return null;
    }

    private function reactivateInvitation(
        User $actor,
        Workspace $workspace,
        WorkspaceInvitation $invitation,
        WorkspaceRole $role,
        string $previousState,
    ): WorkspaceInvitation {
        $actorUid = ActorId::fromUser($actor);
        $workspaceUid = $workspace->uid;
        $previousRole = $invitation->role;
        $invitation->forceFill([
            'role' => $role,
            'invited_by_user_uid' => $actorUid,
            'accepted_by_user_uid' => null,
            'accepted_at' => null,
            'revoked_at' => null,
            'expires_at' => $this->newExpiration(),
            // Rotate the link secret: the previous one belonged to a revoked or expired
            // invitation and may have leaked, so reactivating must invalidate it. The
            // re-queued email below carries the new token.
            'token' => WorkspaceInvitation::freshToken(),
        ])->save();

        $event = $this->events->record(
            eventType: DomainEventType::WorkspaceInvitationReactivated,
            aggregateType: 'workspace_invitation',
            aggregateUid: $invitation->uid,
            payload: [
                'workspace_uid' => $workspaceUid,
                'invitation_uid' => $invitation->uid,
                'invited_by_user_uid' => $actorUid,
                'invited_email' => $invitation->invited_email,
                'previous_state' => $previousState,
                'previous_role' => $previousRole->value,
                'new_role' => $role->value,
            ],
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUid,
            auditableType: 'workspace_invitation',
            auditableUid: $invitation->uid,
            action: DomainEventType::WorkspaceInvitationReactivated,
            summary: 'Workspace invitation reactivated.',
            metadata: [
                'workspace_uid' => $workspaceUid,
                'invited_email' => $invitation->invited_email,
                'previous_state' => $previousState,
                'previous_role' => $previousRole->value,
                'new_role' => $role->value,
            ],
        );

        $invitation = $invitation->refresh();
        $this->queueInvitationEmail($actor, $workspace, $invitation);

        return $invitation;
    }

    private function queueInvitationEmail(User $actor, Workspace $workspace, WorkspaceInvitation $invitation): void
    {
        $expiresAt = $invitation->expires_at;

        if (!$expiresAt instanceof DateTimeInterface) {
            throw new LogicException('Cannot email a workspace invitation without an expiration timestamp.');
        }

        $mail = new WorkspaceInvitationMail(
            invitedEmail: $invitation->invited_email,
            workspaceName: $workspace->name,
            roleLabel: ucfirst($invitation->role->value),
            inviterName: $actor->name,
            acceptUrl: $this->acceptUrl($invitation),
            expiresAt: $expiresAt,
        );

        DB::afterCommit(static function () use ($invitation, $mail): void {
            Mail::to($invitation->invited_email)->queue($mail);
        });
    }

    private function acceptUrl(WorkspaceInvitation $invitation): string
    {
        $appUrl = config('app.url');

        if (!is_string($appUrl) || trim($appUrl) === '') {
            throw new LogicException('Cannot email a workspace invitation without an application URL.');
        }

        // Link to the public token landing so an invited person who has no account
        // yet can finish registration and join. The landing routes existing
        // accounts to sign-in and matching logged-in users to the accept page.
        return rtrim($appUrl, '/') . route('workspace-invitations.join', ['invitation' => $invitation->token], false);
    }

    private function newExpiration(): \Illuminate\Support\Carbon
    {
        $configuredDays = config('pages.workspace_invitation_ttl_days', 7);
        $ttlDays = is_int($configuredDays) || is_string($configuredDays)
            ? (int) $configuredDays
            : 7;

        return now()->addDays(max(1, $ttlDays));
    }

    private function normalizeEmail(string $email): string
    {
        $normalizedEmail = strtolower(trim($email));

        if (filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainRuleViolation('Invitation email must be a valid email address.');
        }

        return $normalizedEmail;
    }

    private function requireWorkspaceUid(string $workspaceUid): string
    {
        $workspaceUid = trim($workspaceUid);

        if ($workspaceUid === '') {
            throw new DomainRuleViolation('Workspace is required to invite a member.');
        }

        return $workspaceUid;
    }
}
