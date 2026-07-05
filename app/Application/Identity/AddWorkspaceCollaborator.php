<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\PageCatalog\PageAccess;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Mail\WorkspaceMembershipAddedMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;

/**
 * Adds a registered human coworker straight into a shared workspace as an
 * accepted member — no email invitation and no accept/reject step. The added
 * user receives an informational email. Authorization and role rules are shared
 * with the invitation flow; the submitted user UID identifies a target but
 * never substitutes for the actor's workspace authority.
 */
final readonly class AddWorkspaceCollaborator
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private WorkspaceInvitationAccess $access,
        private WorkspaceCollaboratorDirectory $directory,
        private PageAccess $pageAccess,
    ) {
    }

    /**
     * @throws AuthorizationException
     * @throws DomainRuleViolation
     */
    public function handle(User $actor, AddWorkspaceCollaboratorCommand $command): WorkspaceMembership
    {
        $membership = DB::transaction(function () use ($actor, $command): WorkspaceMembership {
            $actorUid = ActorId::fromUser($actor);
            $workspace = Workspace::query()->lockForUpdate()->find($command->workspaceUid);

            if (!$workspace instanceof Workspace) {
                throw new DomainRuleViolation('Workspace does not exist.');
            }

            $this->access->ensureCanInvite($actor, $workspace, $command->role);

            if ($workspace->type !== WorkspaceType::Shared) {
                throw new DomainRuleViolation('Only shared workspaces can add members.');
            }

            $targetUser = User::query()->find($command->userUid);

            if (!$targetUser instanceof User) {
                throw new DomainRuleViolation('Selected person could not be found.');
            }

            if (!$this->directory->isEligibleCoworker($actor, $targetUser)) {
                throw new DomainRuleViolation('Select another registered human coworker.');
            }

            $existing = WorkspaceMembership::query()
                ->where('workspace_uid', $workspace->uid)
                ->where('user_uid', $targetUser->uid)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof WorkspaceMembership) {
                throw new DomainRuleViolation('That person is already a member of this workspace.');
            }

            $membership = WorkspaceMembership::query()->forceCreate([
                'workspace_uid' => $workspace->uid,
                'user_uid' => $targetUser->uid,
                'role' => $command->role,
                'accepted_at' => now(),
            ]);

            $event = $this->events->record(
                eventType: DomainEventType::WorkspaceMembershipAdded,
                aggregateType: 'workspace',
                aggregateUid: $workspace->uid,
                payload: [
                    'workspace_uid' => $workspace->uid,
                    'membership_uid' => $membership->uid,
                    'user_uid' => $targetUser->uid,
                    'added_by_user_uid' => $actorUid,
                    'role' => $command->role->value,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'workspace_membership',
                auditableUid: $membership->uid,
                action: DomainEventType::WorkspaceMembershipAdded,
                summary: 'Existing collaborator added to workspace.',
                metadata: [
                    'workspace_uid' => $workspace->uid,
                    'user_uid' => $targetUser->uid,
                    'role' => $command->role->value,
                ],
            );

            $this->queueNotification($actor, $workspace, $targetUser, $command->role);

            return $membership;
        });

        $this->pageAccess->flushCache();

        return $membership;
    }

    private function queueNotification(
        User $actor,
        Workspace $workspace,
        User $target,
        WorkspaceRole $role,
    ): void {
        $mail = new WorkspaceMembershipAddedMail(
            recipientEmail: $target->email,
            workspaceName: $workspace->name,
            roleLabel: ucfirst($role->value),
            addedByName: $actor->name,
            workspaceUrl: $this->workspaceUrl(),
        );

        DB::afterCommit(static function () use ($target, $mail): void {
            Mail::to($target->email)->queue($mail);
        });
    }

    private function workspaceUrl(): string
    {
        $appUrl = config('app.url');

        if (!is_string($appUrl) || trim($appUrl) === '') {
            throw new LogicException('Cannot email a membership notification without an application URL.');
        }

        return rtrim($appUrl, '/') . route('dashboard', absolute: false);
    }
}
