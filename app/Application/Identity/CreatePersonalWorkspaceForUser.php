<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;

final readonly class CreatePersonalWorkspaceForUser
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    public function handle(User $user): Workspace
    {
        return DB::transaction(function () use ($user): Workspace {
            $userUid = ActorId::fromUser(
                $user,
                'Cannot create a personal workspace for an unsaved user.',
            );

            $existingWorkspace = Workspace::query()
                ->where('personal_owner_uid', $userUid)
                ->first();

            if ($existingWorkspace instanceof Workspace) {
                $this->ensureAdminMembership($existingWorkspace, $userUid);

                return $existingWorkspace;
            }

            $workspace = Workspace::query()->forceCreate([
                'name' => $user->name,
                'type' => WorkspaceType::Personal,
                'personal_owner_uid' => $userUid,
                'created_by_user_uid' => $userUid,
            ]);

            $this->ensureAdminMembership($workspace, $userUid);

            $event = $this->events->record(
                eventType: DomainEventType::WorkspacePersonalCreated,
                aggregateType: 'workspace',
                aggregateUid: $workspace->uid,
                payload: [
                    'workspace_uid' => $workspace->uid,
                    'user_uid' => $userUid,
                    'workspace_name' => $workspace->name,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $userUid,
                auditableType: 'workspace',
                auditableUid: $workspace->uid,
                action: DomainEventType::WorkspacePersonalCreated,
                summary: 'Personal workspace created.',
                metadata: [
                    'workspace_name' => $workspace->name,
                    'workspace_type' => WorkspaceType::Personal->value,
                ],
            );

            return $workspace;
        });
    }

    private function ensureAdminMembership(Workspace $workspace, string $userUid): void
    {
        $membership = WorkspaceMembership::query()->firstOrNew([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $userUid,
        ]);

        $membership->forceFill([
            'role' => WorkspaceRole::Admin,
            'accepted_at' => now(),
        ])->save();
    }
}
