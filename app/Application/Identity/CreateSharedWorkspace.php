<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Domain\PageCatalog\PageContentEncoding;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;

final readonly class CreateSharedWorkspace
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    public function handle(User $actor, string $name): Workspace
    {
        $workspaceName = trim($name);

        if ($workspaceName === '') {
            throw new DomainRuleViolation('Workspace name must not be blank.');
        }

        // Reject a NUL byte or malformed UTF-8 that survives trim() before it reaches the
        // PostgreSQL text column as a 500.
        if (!PageContentEncoding::isStorable($workspaceName)) {
            throw new DomainRuleViolation('Workspace name must not contain control characters or invalid text.');
        }

        return DB::transaction(function () use ($actor, $workspaceName): Workspace {
            $actorUid = ActorId::fromUser(
                $actor,
                'Cannot create a shared workspace for an unsaved user.',
            );

            $workspace = Workspace::query()->forceCreate([
                'name' => $workspaceName,
                'type' => WorkspaceType::Shared,
                'personal_owner_uid' => null,
                'created_by_user_uid' => $actorUid,
            ]);

            WorkspaceMembership::query()->forceCreate([
                'workspace_uid' => $workspace->uid,
                'user_uid' => $actorUid,
                'role' => WorkspaceRole::Admin,
                'accepted_at' => now(),
            ]);

            $event = $this->events->record(
                eventType: DomainEventType::WorkspaceSharedCreated,
                aggregateType: 'workspace',
                aggregateUid: $workspace->uid,
                payload: [
                    'workspace_uid' => $workspace->uid,
                    'created_by_user_uid' => $actorUid,
                    'workspace_name' => $workspace->name,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'workspace',
                auditableUid: $workspace->uid,
                action: DomainEventType::WorkspaceSharedCreated,
                summary: 'Shared workspace created.',
                metadata: [
                    'workspace_name' => $workspace->name,
                    'workspace_type' => WorkspaceType::Shared->value,
                ],
            );

            return $workspace;
        });
    }
}
