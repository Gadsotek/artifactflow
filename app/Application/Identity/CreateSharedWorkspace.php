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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class CreateSharedWorkspace
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private WorkspaceHierarchyGraph $hierarchy,
        private WorkspaceAccess $workspaceAccess,
    ) {
    }

    public function handle(
        User $actor,
        string $name,
        ?string $parentWorkspaceUid = null,
        bool $inheritsParentMemberships = true,
    ): Workspace {
        $workspaceName = trim($name);

        if ($workspaceName === '') {
            throw new DomainRuleViolation('Workspace name must not be blank.');
        }

        // Reject a NUL byte or malformed UTF-8 that survives trim() before it reaches the
        // PostgreSQL text column as a 500.
        if (!PageContentEncoding::isStorable($workspaceName)) {
            throw new DomainRuleViolation('Workspace name must not contain control characters or invalid text.');
        }

        return DB::transaction(function () use (
            $actor,
            $workspaceName,
            $parentWorkspaceUid,
            $inheritsParentMemberships,
        ): Workspace {
            $actorUid = ActorId::fromUser(
                $actor,
                'Cannot create a shared workspace for an unsaved user.',
            );
            $parent = null;
            $parentAncestorRows = [];

            if ($parentWorkspaceUid !== null) {
                $this->hierarchy->acquireMutationLock();
                $parent = Workspace::query()->whereKey($parentWorkspaceUid)->lockForUpdate()->first();

                // A submitted workspace UID is not an authority credential. Keep a
                // missing parent indistinguishable from an existing parent outside
                // the actor's reach before revealing its type or hierarchy depth.
                if (
                    !$parent instanceof Workspace
                    || !$this->workspaceAccess->isAdmin($actorUid, $parent->uid)
                ) {
                    throw new AuthorizationException('Only workspace admins can create a child workspace.');
                }

                if ($parent->type !== WorkspaceType::Shared) {
                    throw new DomainRuleViolation('Personal workspaces cannot participate in a workspace hierarchy.');
                }
                $parentAncestorRows = $this->hierarchy->ancestorRows($parent->uid);

                foreach ($parentAncestorRows as $ancestorRow) {
                    if ($ancestorRow->depth >= 2) {
                        throw new DomainRuleViolation('Workspace hierarchy is limited to three levels.');
                    }
                }
            }

            $workspace = Workspace::query()->forceCreate([
                'name' => $workspaceName,
                'type' => WorkspaceType::Shared,
                'personal_owner_uid' => null,
                'parent_workspace_uid' => $parent?->uid,
                'inherits_parent_memberships' => $parent === null || $inheritsParentMemberships,
                'created_by_user_uid' => $actorUid,
            ]);

            if ($parent instanceof Workspace) {
                $this->hierarchy->replaceParent(
                    workspace: $workspace,
                    newParentWorkspaceUid: $parent->uid,
                    subtreeRows: $this->hierarchy->subtreeRows($workspace->uid),
                    newParentAncestorRows: $parentAncestorRows,
                );
            }

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
                    'parent_workspace_uid' => $parent?->uid,
                    'inherits_parent_memberships' => $workspace->inherits_parent_memberships,
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
                    'parent_workspace_uid' => $parent?->uid,
                    'inherits_parent_memberships' => $workspace->inherits_parent_memberships,
                ],
            );

            return $workspace->refresh();
        });
    }
}
