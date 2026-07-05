<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\PageAccessRevision;
use App\Application\PageCatalog\PagePresenceRevoker;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Domain\PageCatalog\PageAccessMode;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class ChangeWorkspaceMembershipRole
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private PageAccess $access,
        private WorkspaceAccess $workspaceAccess,
        private PageAccessRevision $revisions,
        private PagePresenceRevoker $presence,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, ChangeWorkspaceMembershipRoleCommand $command): WorkspaceMembership
    {
        $actorUid = ActorId::fromUser($actor);
        $previousRole = null;

        // Lock ordering: bumpWorkspace() below row-locks every page in the workspace via
        // an UPDATE. Acquiring those page rows FOR UPDATE in ascending uid order FIRST, and
        // only then the workspace row, makes this handler follow the catalog-wide
        // page->workspace order a concurrent save takes (page then workspace) instead of
        // inverting it -- so the two can no longer form the lock cycle Postgres would break
        // by aborting one side with a deadlock (40P01) -> 500. The workspace lock stays
        // load-bearing (last-admin invariant) and the revision bump commits atomically with
        // the role change; the retry below is now defense-in-depth for other concurrency
        // errors. The aborted side replays cleanly on a fresh snapshot; presence kick post-commit.
        // A page created after the snapshot but before bumpWorkspace() is a phantom the
        // retry intentionally guards (it is pre-locked on replay); the bump stays
        // workspace-scoped because it is load-bearing security -- see the phantom-window
        // note and regression test referenced from RemoveWorkspaceMember::handle().
        $membership = DB::transaction(function () use ($actorUid, $command, &$previousRole): WorkspaceMembership {
            $this->lockWorkspacePagesInCanonicalOrder($command->workspaceUid);

            $workspace = Workspace::query()
                ->lockForUpdate()
                ->find($command->workspaceUid);

            if (!$workspace instanceof Workspace) {
                throw new DomainRuleViolation('Workspace does not exist.');
            }

            if ($workspace->type === WorkspaceType::Personal) {
                throw new DomainRuleViolation('Personal workspace membership roles cannot be changed.');
            }

            $this->ensureWorkspaceAdmin($actorUid, $workspace->uid);

            $membership = WorkspaceMembership::query()
                ->where('uid', $command->membershipUid)
                ->where('workspace_uid', $workspace->uid)
                ->lockForUpdate()
                ->first();

            if (!$membership instanceof WorkspaceMembership) {
                throw new DomainRuleViolation('Workspace membership does not exist.');
            }

            if ($membership->role === $command->role) {
                return $membership;
            }

            $this->ensureAdminRemains($membership, $command->role, $workspace->uid);
            $this->ensureOwnedPagesRemainWithAnEligibleOwner($membership, $command->role, $workspace->uid);
            $previousRole = $membership->role;
            $membership->forceFill(['role' => $command->role])->save();
            $invalidatedPreviewPageCount = $this->revisions->bumpWorkspace($workspace->uid);

            $event = $this->events->record(
                eventType: DomainEventType::WorkspaceMembershipRoleChanged,
                aggregateType: 'workspace_membership',
                aggregateUid: $membership->uid,
                payload: [
                    'workspace_uid' => $workspace->uid,
                    'workspace_membership_uid' => $membership->uid,
                    'member_user_uid' => $membership->user_uid,
                    'changed_by_user_uid' => $actorUid,
                    'previous_role' => $previousRole->value,
                    'new_role' => $command->role->value,
                    'invalidated_preview_page_count' => $invalidatedPreviewPageCount,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'workspace_membership',
                auditableUid: $membership->uid,
                action: DomainEventType::WorkspaceMembershipRoleChanged,
                summary: 'Workspace member role changed.',
                metadata: [
                    'workspace_uid' => $workspace->uid,
                    'member_user_uid' => $membership->user_uid,
                    'previous_role' => $previousRole->value,
                    'new_role' => $command->role->value,
                    'invalidated_preview_page_count' => $invalidatedPreviewPageCount,
                ],
            );

            return $membership->refresh();
        }, attempts: 3);

        $this->access->flushCache();

        if ($previousRole === WorkspaceRole::Admin && $membership->role !== WorkspaceRole::Admin) {
            $member = User::query()->find($membership->user_uid);

            if ($member instanceof User) {
                $this->presence->kickUserFromPagesWhereViewLost(
                    $member,
                    Page::query()
                        ->where('workspace_uid', $command->workspaceUid)
                        ->where('access_mode', PageAccessMode::Restricted)
                        ->orderBy('uid')
                        ->get(),
                );
            }
        }

        return $membership;
    }

    /**
     * Lock every page in the workspace FOR UPDATE, one row at a time in ascending
     * uid order, before the caller takes the workspace row lock. Locking the rows
     * individually in sorted order (rather than letting bumpWorkspace()'s UPDATE
     * lock them in scan order, or a single ordered SELECT ... FOR UPDATE whose lock
     * order follows the scan, not the ORDER BY) guarantees the ascending acquisition
     * order the catalog-wide page->workspace discipline depends on.
     */
    private function lockWorkspacePagesInCanonicalOrder(string $workspaceUid): void
    {
        /** @var list<string> $pageUids */
        $pageUids = Page::query()
            ->where('workspace_uid', $workspaceUid)
            ->orderBy('uid')
            ->pluck('uid')
            ->all();

        foreach ($pageUids as $pageUid) {
            Page::query()->whereKey($pageUid)->lockForUpdate()->first();
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureWorkspaceAdmin(string $actorUid, string $workspaceUid): void
    {
        $this->workspaceAccess->ensureAdmin($actorUid, $workspaceUid, 'Only workspace admins can change member roles.');
    }

    private function ensureAdminRemains(
        WorkspaceMembership $membership,
        WorkspaceRole $newRole,
        string $workspaceUid,
    ): void {
        if ($membership->role !== WorkspaceRole::Admin || $newRole === WorkspaceRole::Admin) {
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

    private function ensureOwnedPagesRemainWithAnEligibleOwner(
        WorkspaceMembership $membership,
        WorkspaceRole $newRole,
        string $workspaceUid,
    ): void {
        if ($newRole !== WorkspaceRole::Reader) {
            return;
        }

        $ownsPages = Page::query()
            ->where('workspace_uid', $workspaceUid)
            ->where('owner_user_uid', $membership->user_uid)
            ->exists();

        if ($ownsPages) {
            throw new DomainRuleViolation(
                'Reassign pages owned by this member before changing their role to Reader.',
            );
        }
    }
}
