<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageStatus;
use App\Models\Category;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class MovePageToWorkspace
{
    public function __construct(
        private PageAccess $access,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private PageSearchVectorUpdater $searchVectors,
        private WorkspaceStorageQuota $storageQuota,
        private SlugGenerator $slugs,
        private PageAccessRevision $revisions,
        private PageOwnershipTransferRecorder $ownershipTransfers,
        private CreateCategory $createCategory,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, MovePageToWorkspaceCommand $command): Page
    {
        $actorUid = ActorId::fromUser($actor);

        if (!$command->confirmed) {
            throw new DomainRuleViolation('Page workspace moves must be explicitly confirmed.');
        }

        return DB::transaction(function () use ($actor, $actorUid, $command): Page {
            $page = $this->pageForUpdate($command->pageUid);
            $targetWorkspace = $this->lockSourceAndTargetWorkspaces(
                $page->workspace_uid,
                $command->targetWorkspaceUid,
            );

            // The page and both workspace rows are now locked. Discard the request-scoped
            // authority cache so every capability check below reads committed state: a role
            // revoked in the source or target workspace while this request waited for these
            // locks must block the move rather than pass on a stale cached decision.
            $this->access->flushCache();

            $previousWorkspaceUid = $page->workspace_uid;
            $previousOwnerUserUid = $page->owner_user_uid;
            $previousStatus = $page->status;
            $newStatus = $this->statusAfterMove($page);

            if ($targetWorkspace->uid === $previousWorkspaceUid) {
                throw new DomainRuleViolation('Target workspace must be different from the current workspace.');
            }

            if (!$this->access->canHardDelete($actor, $page)) {
                throw new AuthorizationException('You cannot move this page out of its current workspace.');
            }

            if ($targetWorkspace->type === WorkspaceType::Personal) {
                throw new AuthorizationException('Pages cannot be moved into personal workspaces.');
            }

            if (!$this->access->canCreateInWorkspace($actor, $targetWorkspace->uid)) {
                throw new AuthorizationException('You cannot move pages into this workspace.');
            }

            $this->ensureTargetWorkspaceAdmin($actor, $targetWorkspace->uid);
            $this->ensureOwnerBelongsToTargetWorkspace($command->targetOwnerUserUid, $targetWorkspace->uid);
            $this->ensurePageHasNoChildren($page);
            $movingBytes = $this->storageQuota->storedPageBytes($page);
            $this->storageQuota->ensureWorkspaceAllowsNewBytes($targetWorkspace, $movingBytes);

            $tagCount = $page->tags()->count();
            $targetCategoryUid = $this->targetCategoryUid($actor, $page, $targetWorkspace->uid);
            $preservedCategory = $page->category_uid !== null && $targetCategoryUid !== null;
            $clearedParent = $page->parent_page_uid !== null;
            $revokedAccessGrantCount = PageAccessGrant::query()
                ->where('page_uid', $page->uid)
                ->count();
            $newSlug = $this->slugs->uniqueForWorkspace(
                workspaceUid: $targetWorkspace->uid,
                title: $page->title,
                exceptPageUid: $page->uid,
            );

            PageAccessGrant::query()
                ->where('page_uid', $page->uid)
                ->delete();

            $page->forceFill([
                'workspace_uid' => $targetWorkspace->uid,
                'owner_user_uid' => $command->targetOwnerUserUid,
                'parent_page_uid' => null,
                'category_uid' => $targetCategoryUid,
                'slug' => $newSlug,
                'access_mode' => PageAccessMode::Inherited,
                'status' => $newStatus,
            ])->save();
            $this->revisions->bump($page);
            $this->storageQuota->recordBytesReleased($previousWorkspaceUid, $movingBytes);
            $this->storageQuota->recordBytesStored($targetWorkspace->uid, $movingBytes);

            $this->searchVectors->refreshPage($page->uid);
            $this->recordPageMoved(
                page: $page,
                previousWorkspaceUid: $previousWorkspaceUid,
                newWorkspaceUid: $targetWorkspace->uid,
                previousOwnerUserUid: $previousOwnerUserUid,
                newOwnerUserUid: $command->targetOwnerUserUid,
                actorUid: $actorUid,
                preservedCategory: $preservedCategory,
                clearedParent: $clearedParent,
                revokedAccessGrantCount: $revokedAccessGrantCount,
                tagCount: $tagCount,
                previousStatus: $previousStatus,
                newStatus: $newStatus,
            );

            if ($previousOwnerUserUid !== $command->targetOwnerUserUid) {
                $this->ownershipTransfers->record(
                    page: $page,
                    previousOwnerUserUid: $previousOwnerUserUid,
                    newOwnerUserUid: $command->targetOwnerUserUid,
                    actorUid: $actorUid,
                    reason: 'workspace_move',
                );
            }

            return $page->refresh();
        });
    }

    private function pageForUpdate(string $pageUid): Page
    {
        $page = Page::query()
            ->lockForUpdate()
            ->find($pageUid);

        if (!$page instanceof Page) {
            throw new DomainRuleViolation('Page does not exist.');
        }

        return $page;
    }

    /**
     * Locks the source and target workspace rows in a stable (uid) order so
     * concurrent moves between the same pair of workspaces cannot deadlock,
     * then returns the locked target workspace. Both storage counters are
     * mutated by the move and both rows must be locked before the quota check.
     */
    private function lockSourceAndTargetWorkspaces(
        string $sourceWorkspaceUid,
        string $targetWorkspaceUid,
    ): Workspace {
        $workspaceUids = array_values(array_unique([$sourceWorkspaceUid, $targetWorkspaceUid]));
        sort($workspaceUids);
        $targetWorkspace = null;

        foreach ($workspaceUids as $workspaceUid) {
            $workspace = Workspace::query()
                ->whereKey($workspaceUid)
                ->lockForUpdate()
                ->first();

            if ($workspace instanceof Workspace && $workspace->uid === $targetWorkspaceUid) {
                $targetWorkspace = $workspace;
            }
        }

        if (!$targetWorkspace instanceof Workspace) {
            throw new DomainRuleViolation('Target workspace does not exist.');
        }

        return $targetWorkspace;
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureTargetWorkspaceAdmin(User $actor, string $workspaceUid): void
    {
        if ($this->access->workspaceRole($actor, $workspaceUid) === WorkspaceRole::Admin) {
            return;
        }

        throw new AuthorizationException('You must be a target workspace Admin to move pages into it.');
    }

    private function statusAfterMove(Page $page): PageStatus
    {
        return $page->status->statusAfterWorkspaceMove();
    }

    private function ensureOwnerBelongsToTargetWorkspace(string $ownerUserUid, string $workspaceUid): void
    {
        $membership = WorkspaceMembership::query()
            ->where('workspace_uid', $workspaceUid)
            ->where('user_uid', $ownerUserUid)
            ->first();

        if (!$membership instanceof WorkspaceMembership) {
            throw new DomainRuleViolation('Page owner must belong to the target workspace.');
        }

        if ($membership->role === WorkspaceRole::Reader) {
            throw new DomainRuleViolation('Page owner must be a target workspace editor or admin.');
        }
    }

    private function ensurePageHasNoChildren(Page $page): void
    {
        $hasChildren = Page::query()
            ->where('workspace_uid', $page->workspace_uid)
            ->where('parent_page_uid', $page->uid)
            ->exists();

        if ($hasChildren) {
            throw new DomainRuleViolation('Move or detach child pages before moving this page to another workspace.');
        }
    }

    private function targetCategoryUid(User $actor, Page $page, string $targetWorkspaceUid): ?string
    {
        if ($page->category_uid === null) {
            return null;
        }

        $sourceCategory = Category::query()->find($page->category_uid);

        if (!$sourceCategory instanceof Category) {
            return null;
        }

        $targetCategory = Category::query()
            ->where('workspace_uid', $targetWorkspaceUid)
            ->where('slug', $sourceCategory->slug)
            ->first();

        if ($targetCategory instanceof Category) {
            return $targetCategory->uid;
        }

        return $this->createCategory->handle($actor, new CreateCategoryCommand(
            workspaceUid: $targetWorkspaceUid,
            name: $sourceCategory->name,
        ))->uid;
    }

    private function recordPageMoved(
        Page $page,
        string $previousWorkspaceUid,
        string $newWorkspaceUid,
        string $previousOwnerUserUid,
        string $newOwnerUserUid,
        string $actorUid,
        bool $preservedCategory,
        bool $clearedParent,
        int $revokedAccessGrantCount,
        int $tagCount,
        PageStatus $previousStatus,
        PageStatus $newStatus,
    ): void {
        $event = $this->events->record(
            eventType: DomainEventType::PageWorkspaceMoved,
            aggregateType: 'page',
            aggregateUid: $page->uid,
            payload: [
                'page_uid' => $page->uid,
                'previous_workspace_uid' => $previousWorkspaceUid,
                'new_workspace_uid' => $newWorkspaceUid,
                'previous_owner_user_uid' => $previousOwnerUserUid,
                'new_owner_user_uid' => $newOwnerUserUid,
                'moved_by_user_uid' => $actorUid,
                'preserved_category' => $preservedCategory,
                'cleared_parent' => $clearedParent,
                'revoked_access_grant_count' => $revokedAccessGrantCount,
                'tag_count' => $tagCount,
                'previous_status' => $previousStatus->value,
                'new_status' => $newStatus->value,
            ],
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUid,
            auditableType: 'page',
            auditableUid: $page->uid,
            action: DomainEventType::PageWorkspaceMoved,
            summary: 'Page moved to another workspace.',
            metadata: [
                'previous_workspace_uid' => $previousWorkspaceUid,
                'new_workspace_uid' => $newWorkspaceUid,
                'previous_owner_user_uid' => $previousOwnerUserUid,
                'new_owner_user_uid' => $newOwnerUserUid,
                'preserved_category' => $preservedCategory,
                'cleared_parent' => $clearedParent,
                'revoked_access_grant_count' => $revokedAccessGrantCount,
                'tag_count' => $tagCount,
                'previous_status' => $previousStatus->value,
                'new_status' => $newStatus->value,
            ],
        );
    }
}
