<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class HardDeletePage
{
    public function __construct(
        private PageAccess $access,
        private ArtifactContentDeleter $artifactContentDeleter,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private WorkspaceStorageQuota $storageQuota,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, HardDeletePageCommand $command): void
    {
        $actorUid = ActorId::fromUser($actor);
        $page = PageFinder::requireByUid($command->pageUid);

        if (!$this->access->canHardDelete($actor, $page)) {
            throw new AuthorizationException('You cannot permanently delete this page.');
        }

        if ($page->title !== $command->confirmation) {
            throw new DomainRuleViolation('Type the page title exactly to permanently delete it.');
        }

        $storagePaths = [];

        DB::transaction(function () use ($actor, $actorUid, $command, $page, &$storagePaths): void {
            // Lock page then workspace, matching the append path's lock order,
            // so the storage counter decrement cannot race a version append.
            $page = $this->lockPageForHardDelete($page->uid);

            if ($page->title !== $command->confirmation) {
                throw new DomainRuleViolation('Type the page title exactly to permanently delete it.');
            }

            // Re-authorize under the page lock with fresh authority. canHardDelete()
            // above ran before the lock and against PageAccess's request-scoped cache,
            // so an admin capability revoked while this request waited for the lock must
            // still block the delete rather than let a stale grant tear the page down.
            $this->access->flushCache();

            if (!$this->access->canHardDelete($actor, $page)) {
                throw new AuthorizationException('You cannot permanently delete this page.');
            }

            // Refuse to delete a page that still has children -- consistent with
            // MovePageToWorkspace. Otherwise $page->delete() below fires the
            // parent_page_uid ON DELETE SET NULL cascade, which locks each child row
            // AFTER the workspace lock and inverts the child -> workspace order a
            // concurrent append/rename on a child uses, deadlocking. Checked under the
            // page lock, so no child can be attached between here and the delete.
            $this->ensurePageHasNoChildren($page);
            $this->storageQuota->lockWorkspaceForStorageUpdate($page->workspace_uid);
            $deletedBytes = $this->storageQuota->storedPageBytes($page);

            // Snapshot versions and grants UNDER the page lock. The append path locks the
            // same page row before writing a version, so once we hold it no new version can
            // be inserted, and a version that committed just before we acquired the lock is
            // now visible -- its blob is captured for cleanup below. Snapshotting before the
            // lock (as this once did) would miss that version's blob and orphan its raw
            // private content with no cleanup-failure record.
            $versions = PageVersion::query()
                ->where('page_uid', $page->uid)
                ->orderBy('version_number')
                ->get();

            foreach ($versions as $version) {
                $storagePaths[] = $version->content_storage_path;
            }

            $accessGrantCount = PageAccessGrant::query()
                ->where('page_uid', $page->uid)
                ->count();

            $event = $this->events->record(
                eventType: DomainEventType::PageHardDeleted,
                aggregateType: 'page',
                aggregateUid: $page->uid,
                payload: [
                    'page_uid' => $page->uid,
                    'workspace_uid' => $page->workspace_uid,
                    'owner_user_uid' => $page->owner_user_uid,
                    'deleted_by_user_uid' => $actorUid,
                    'page_type' => $page->type->value,
                    'status' => $page->status->value,
                    'version_count' => $versions->count(),
                    'access_grant_count' => $accessGrantCount,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'page',
                auditableUid: $page->uid,
                action: DomainEventType::PageHardDeleted,
                summary: 'Page permanently deleted.',
                metadata: [
                    'workspace_uid' => $page->workspace_uid,
                    'owner_user_uid' => $page->owner_user_uid,
                    'page_type' => $page->type->value,
                    'status' => $page->status->value,
                    'version_count' => $versions->count(),
                    'access_grant_count' => $accessGrantCount,
                ],
            );

            if ($page->delete() === false) {
                throw new RuntimeException('Failed to delete page.');
            }

            $this->storageQuota->recordBytesReleased($page->workspace_uid, $deletedBytes);
        });

        if ($storagePaths !== [] && !$this->artifactContentDeleter->deleteMany($storagePaths)) {
            $this->recordArtifactDeletionFailure($page, $actorUid, count($storagePaths));
        }
    }

    private function ensurePageHasNoChildren(Page $page): void
    {
        $hasChildren = Page::query()
            ->where('parent_page_uid', $page->uid)
            ->exists();

        if ($hasChildren) {
            throw new DomainRuleViolation('Delete or detach child pages before permanently deleting this page.');
        }
    }

    private function lockPageForHardDelete(string $pageUid): Page
    {
        $page = Page::query()
            ->whereKey($pageUid)
            ->lockForUpdate()
            ->first();

        if (!$page instanceof Page) {
            throw new DomainRuleViolation('Page does not exist.');
        }

        return $page;
    }

    private function recordArtifactDeletionFailure(Page $page, string $actorUid, int $storagePathCount): void
    {
        // Record the cleanup-failure event and its audit entry atomically. This runs
        // after the main delete transaction has committed, so a crash between the two
        // inserts would otherwise leave the domain event without its paired audit entry.
        DB::transaction(function () use ($page, $actorUid, $storagePathCount): void {
            $event = $this->events->record(
                eventType: DomainEventType::PageArtifactDeleteFailed,
                aggregateType: 'page',
                aggregateUid: $page->uid,
                payload: [
                    'page_uid' => $page->uid,
                    'workspace_uid' => $page->workspace_uid,
                    'deleted_by_user_uid' => $actorUid,
                    'storage_path_count' => $storagePathCount,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'page',
                auditableUid: $page->uid,
                action: DomainEventType::PageArtifactDeleteFailed,
                summary: 'Stored page content cleanup failed after hard delete.',
                metadata: [
                    'workspace_uid' => $page->workspace_uid,
                    'storage_path_count' => $storagePathCount,
                ],
            );
        });
    }
}
