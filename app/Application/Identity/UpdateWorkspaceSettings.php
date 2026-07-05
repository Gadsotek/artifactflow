<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\PageCatalog\PageSearchVectorUpdater;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceType;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class UpdateWorkspaceSettings
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private PageSearchVectorUpdater $searchVectors,
        private WorkspaceAccess $workspaceAccess,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, UpdateWorkspaceSettingsCommand $command): Workspace
    {
        $actorUid = ActorId::fromUser($actor);
        $name = $this->normalizedName($command->name);

        return DB::transaction(function () use ($actorUid, $command, $name): Workspace {
            $workspace = Workspace::query()
                ->lockForUpdate()
                ->find($command->workspaceUid);

            if (!$workspace instanceof Workspace) {
                throw new DomainRuleViolation('Workspace does not exist.');
            }

            $this->ensureWorkspaceAdmin($actorUid, $workspace->uid);

            if ($workspace->type === WorkspaceType::Personal) {
                throw new DomainRuleViolation('Personal workspace settings cannot be changed.');
            }

            if (
                $workspace->name === $name
                && $workspace->allow_editor_invites === $command->allowEditorInvites
                && $workspace->allow_editor_page_sharing === $command->allowEditorPageSharing
            ) {
                return $workspace;
            }

            $previousName = $workspace->name;
            $previousAllowEditorInvites = $workspace->allow_editor_invites;
            $previousAllowEditorPageSharing = $workspace->allow_editor_page_sharing;

            if ($previousName !== $name) {
                $this->ensureRenameCooldownAllows($workspace->uid);
            }

            $workspace->forceFill([
                'name' => $name,
                'allow_editor_invites' => $command->allowEditorInvites,
                'allow_editor_page_sharing' => $command->allowEditorPageSharing,
            ])->save();

            if ($previousName !== $name) {
                $workspaceUid = $workspace->uid;

                // Refresh the workspace's page search vectors AFTER commit rather
                // than inside this transaction. Running it here would issue
                // `UPDATE pages ... WHERE workspace_uid = ?`, write-locking every
                // page row while this transaction already holds the workspace row
                // lock -- the reverse of the page-then-workspace order used by
                // version append, hard delete, and moves. Two of those running
                // concurrently would deadlock. Deferring keeps the rename
                // transaction to just the workspace row, and the post-commit
                // refresh only ever locks page rows (it reads the workspace name
                // without a row lock), so no cross-table lock cycle is possible.
                DB::afterCommit(function () use ($workspaceUid): void {
                    // Set the cooldown first so its throttle window opens the moment
                    // the rename commits, not only after the slower page refresh.
                    Cache::put(
                        $this->renameCooldownCacheKey($workspaceUid),
                        true,
                        $this->renameCooldownSeconds(),
                    );

                    // The rename is already committed; a transient search-vector
                    // refresh failure must not turn it into a post-commit 500.
                    // Stale vectors self-heal on the next page edit or a reindex.
                    try {
                        $this->searchVectors->refreshWorkspace($workspaceUid);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                });
            }

            $event = $this->events->record(
                eventType: DomainEventType::WorkspaceSettingsUpdated,
                aggregateType: 'workspace',
                aggregateUid: $workspace->uid,
                payload: [
                    'workspace_uid' => $workspace->uid,
                    'updated_by_user_uid' => $actorUid,
                    'previous_name' => $previousName,
                    'new_name' => $name,
                    'previous_allow_editor_invites' => $previousAllowEditorInvites,
                    'new_allow_editor_invites' => $command->allowEditorInvites,
                    'previous_allow_editor_page_sharing' => $previousAllowEditorPageSharing,
                    'new_allow_editor_page_sharing' => $command->allowEditorPageSharing,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'workspace',
                auditableUid: $workspace->uid,
                action: DomainEventType::WorkspaceSettingsUpdated,
                summary: 'Workspace settings updated.',
                metadata: [
                    'previous_name' => $previousName,
                    'new_name' => $name,
                    'previous_allow_editor_invites' => $previousAllowEditorInvites,
                    'new_allow_editor_invites' => $command->allowEditorInvites,
                    'previous_allow_editor_page_sharing' => $previousAllowEditorPageSharing,
                    'new_allow_editor_page_sharing' => $command->allowEditorPageSharing,
                ],
            );

            return $workspace->refresh();
        });
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureWorkspaceAdmin(string $actorUid, string $workspaceUid): void
    {
        $this->workspaceAccess->ensureAdmin($actorUid, $workspaceUid, 'Only workspace admins can update workspace settings.');
    }

    private function normalizedName(string $name): string
    {
        $normalized = trim($name);

        if ($normalized === '') {
            throw new DomainRuleViolation('Workspace name must not be blank.');
        }

        if (mb_strlen($normalized) > 160) {
            throw new DomainRuleViolation('Workspace name must be 160 characters or fewer.');
        }

        return $normalized;
    }

    private function ensureRenameCooldownAllows(string $workspaceUid): void
    {
        if ($this->renameCooldownSeconds() < 1) {
            return;
        }

        if (Cache::has($this->renameCooldownCacheKey($workspaceUid))) {
            throw new DomainRuleViolation('Workspace name was changed recently. Try again later.');
        }
    }

    private function renameCooldownSeconds(): int
    {
        $value = config('pages.workspace_rename_cooldown_seconds', 60);

        if (is_int($value) || is_string($value)) {
            return max(0, (int) $value);
        }

        return 60;
    }

    private function renameCooldownCacheKey(string $workspaceUid): string
    {
        return 'workspace-name-renamed:' . $workspaceUid;
    }
}
