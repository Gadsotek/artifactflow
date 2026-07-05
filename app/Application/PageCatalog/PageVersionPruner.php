<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Mcp\McpRequestContext;
use App\Domain\Events\DomainEventType;
use App\Models\Page;
use App\Models\PageVersion;
use RuntimeException;

/**
 * Enforces the per-page version retention cap (pages.max_page_versions) by
 * pruning the oldest surplus versions when a page exceeds it. The cap is a
 * retention limit, not a hard wall: appends never fail on version count, they
 * roll the oldest whole versions off the end.
 *
 * pruneToCap() runs INSIDE the append transaction, under the page + workspace
 * row locks the append path already holds, so the row deletions and the
 * workspace storage-counter release commit atomically with the new version.
 * Blob files are NOT deleted here: it returns their storage paths so the caller
 * can delete them AFTER commit. Deleting a blob inside the transaction would, on
 * a rolled-back append, leave a restored version row pointing at a file that is
 * already gone -- a dangling reference PruneOrphanArtifacts cannot repair (it
 * only reaps files without rows, never rows without files). A blob orphaned by a
 * post-commit delete failure is the safe direction: the reaper sweeps it later.
 */
final readonly class PageVersionPruner
{
    public function __construct(
        private InstallationLimitSettings $limits,
        private WorkspaceStorageQuota $storageQuota,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private McpRequestContext $mcpContext,
    ) {
    }

    /**
     * Prunes the oldest versions of $page beyond the retention cap, keeping the
     * newest N. Records one durable domain event + audit entry per pruned version
     * and releases their bytes from the workspace counter.
     *
     * @return list<string> storage paths of the pruned blobs, to delete after commit
     */
    public function pruneToCap(Page $page, string $actorUid): array
    {
        $limit = $this->limits->integer('pages.max_page_versions');

        if ($limit < 1) {
            return [];
        }

        // Pruning needs only identity, ordering, storage accounting, and the blob path;
        // never hydrate source_text/extracted_text for every version while holding locks.
        $versions = PageVersion::query()
            ->select(['uid', 'page_uid', 'version_number', 'byte_size', 'content_storage_path'])
            ->where('page_uid', $page->uid)
            ->orderByDesc('version_number')
            ->get();

        if ($versions->count() <= $limit) {
            return [];
        }

        // Resolve the page's live current version AND workspace under the page lock
        // the enclosing append transaction already holds. The caller's $page can be
        // stale: the appender re-fetches into a new locked instance, and a concurrent
        // workspace move may have committed before that lock, so $page->workspace_uid
        // could name a workspace this transaction does NOT hold locked -- releasing
        // bytes against it would either trip the used_storage_bytes >= 0 CHECK (500)
        // or drift both workspaces' counters. The newest $limit versions are kept;
        // the current version is always the newest row and can never be surplus, but
        // guard explicitly rather than rely on that invariant holding.
        $currentState = Page::query()->whereKey($page->uid)->first(['current_version_uid', 'workspace_uid']);

        if (!$currentState instanceof Page) {
            throw new RuntimeException('Page no longer exists while pruning versions.');
        }

        $currentVersionUid = $currentState->current_version_uid;
        $workspaceUid = $currentState->workspace_uid;

        /** @var list<string> $prunedPaths */
        $prunedPaths = [];
        $releasedBytes = 0;

        foreach ($versions->slice($limit) as $version) {
            if ($version->uid === $currentVersionUid) {
                continue;
            }

            $this->recordPruned($page, $version, $actorUid);

            if ($version->delete() === false) {
                throw new RuntimeException('Failed to prune page version.');
            }

            $releasedBytes += $version->byte_size;
            $prunedPaths[] = $version->content_storage_path;
        }

        $this->storageQuota->recordBytesReleased($workspaceUid, $releasedBytes);

        return $prunedPaths;
    }

    private function recordPruned(Page $page, PageVersion $version, string $actorUid): void
    {
        $mcpMetadata = $this->mcpContext->auditMetadata();

        $event = $this->events->record(
            eventType: DomainEventType::PageVersionPruned,
            aggregateType: 'page',
            aggregateUid: $page->uid,
            payload: [
                'page_uid' => $page->uid,
                'page_version_uid' => $version->uid,
                'version_number' => $version->version_number,
                'byte_size' => $version->byte_size,
                'pruned_by_user_uid' => $actorUid,
                'reason' => 'version_retention_cap',
            ] + $mcpMetadata,
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUid,
            auditableType: 'page_version',
            auditableUid: $version->uid,
            action: DomainEventType::PageVersionPruned,
            summary: 'Older page version pruned to stay within the retention limit.',
            metadata: [
                'page_uid' => $page->uid,
                'version_number' => $version->version_number,
                'byte_size' => $version->byte_size,
                'reason' => 'version_retention_cap',
            ] + $mcpMetadata,
        );
    }
}
