<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Application\Mcp\McpRequestContext;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class RestorePageVersion
{
    public function __construct(
        private PageAccess $access,
        private ArtifactContentReader $contentReader,
        private PageVersionAppender $versions,
        private RecordBlockedPageContentScan $recordBlockedScan,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private McpRequestContext $mcpContext,
        private PageVersionPruner $versionPruner,
        private ArtifactContentDeleter $artifactContentDeleter,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, RestorePageVersionCommand $command): PageVersion
    {
        $actorUid = ActorId::fromUser($actor);
        $page = PageFinder::requireByUid($command->pageUid);

        if (!$this->access->canEdit($actor, $page)) {
            throw new AuthorizationException('You cannot edit this page.');
        }

        $sourceVersion = $this->sourceVersion($command->versionUid, $page);

        if ($page->current_version_uid === $sourceVersion->uid) {
            return $sourceVersion;
        }

        $sourceContent = $this->contentReader->read($sourceVersion->content_storage_path);

        if ($sourceContent === null) {
            throw new DomainRuleViolation('Version content is missing from storage.');
        }

        $restoredVersion = null;
        $prunedStoragePaths = [];
        $closureCompleted = false;

        try {
            $restored = DB::transaction(function () use (
                $actor,
                $actorUid,
                $command,
                $page,
                &$prunedStoragePaths,
                &$restoredVersion,
                &$closureCompleted,
                $sourceContent,
                $sourceVersion,
            ): PageVersion {
                // Re-fetch under the page row lock and re-authorize against fresh authority.
                // The pre-transaction canEdit() ran against PageAccess's scoped cache; an
                // edit right revoked while this request waited for the lock must still block
                // the restore rather than resurrect content from a stale decision.
                $page = $this->access->lockAndReauthorize($command->pageUid, function (Page $lockedPage) use ($actor): void {
                    if (!$this->access->canEdit($actor, $lockedPage)) {
                        throw new AuthorizationException('You cannot edit this page.');
                    }
                });

                $previousCurrentVersionUid = $page->current_version_uid;
                $restoredVersion = $this->versions->append(
                    actor: $actor,
                    page: $page,
                    content: $sourceContent,
                    source: PageVersionSource::Restore,
                    expectedCurrentVersionUid: $command->expectedCurrentVersionUid,
                );

                $this->recordRestored($page, $sourceVersion, $restoredVersion, $actorUid, $previousCurrentVersionUid);

                $prunedStoragePaths = $this->versionPruner->pruneToCap($page, $actorUid);
                $closureCompleted = true;

                return $restoredVersion;
            });
        } catch (Throwable $exception) {
            // Delete the staged blob only on a pre-commit rollback (the closure
            // failed). A commit-phase failure after the closure completed leaves the
            // restored version row durable, so its blob must survive; the orphan
            // reaper handles any post-commit anomaly (matching UpdatePageContent).
            if (!$closureCompleted && $restoredVersion instanceof PageVersion) {
                Storage::disk('artifacts')->delete($restoredVersion->content_storage_path);
            }

            if ($exception instanceof BlockedPageContentException) {
                $this->recordBlockedScan->forPageVersion($actor, $page, $exception->findingCodes());
            }

            throw $exception;
        }

        $this->deletePrunedArtifacts($page->uid, $prunedStoragePaths);

        return $restored;
    }

    /**
     * Best-effort cleanup of pruned version blobs after the retention prune has
     * committed; an orphaned file left by a failure here is reaped by
     * PruneOrphanArtifacts and never becomes a dangling reference.
     *
     * @param list<string> $storagePaths
     */
    private function deletePrunedArtifacts(string $pageUid, array $storagePaths): void
    {
        if ($storagePaths === []) {
            return;
        }

        if (!$this->artifactContentDeleter->deleteMany($storagePaths)) {
            Log::warning('page.version.prune.artifact_delete_failed', [
                'page_uid' => $pageUid,
                'storage_path_count' => count($storagePaths),
            ]);
        }
    }

    private function sourceVersion(string $versionUid, Page $page): PageVersion
    {
        $version = PageVersion::query()->find($versionUid);

        if (!$version instanceof PageVersion) {
            throw new DomainRuleViolation('Version does not exist.');
        }

        if ($version->page_uid !== $page->uid) {
            throw new DomainRuleViolation('Version does not belong to the selected page.');
        }

        return $version;
    }

    private function recordRestored(
        Page $page,
        PageVersion $sourceVersion,
        PageVersion $restoredVersion,
        string $actorUid,
        ?string $previousCurrentVersionUid,
    ): void {
        $payload = [
            'page_uid' => $page->uid,
            'page_version_uid' => $restoredVersion->uid,
            'version_number' => $restoredVersion->version_number,
            'restored_from_version_uid' => $sourceVersion->uid,
            'restored_from_version_number' => $sourceVersion->version_number,
            'previous_current_version_uid' => $previousCurrentVersionUid,
            'restored_by_user_uid' => $actorUid,
        ] + $this->mcpContext->auditMetadata();
        $event = $this->events->record(
            eventType: DomainEventType::PageVersionRestored,
            aggregateType: 'page',
            aggregateUid: $page->uid,
            payload: $payload,
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUid,
            auditableType: 'page_version',
            auditableUid: $restoredVersion->uid,
            action: DomainEventType::PageVersionRestored,
            summary: 'Page version restored.',
            metadata: [
                'page_uid' => $page->uid,
                'page_version_uid' => $restoredVersion->uid,
                'version_number' => $restoredVersion->version_number,
                'restored_from_version_uid' => $sourceVersion->uid,
                'restored_from_version_number' => $sourceVersion->version_number,
                'previous_current_version_uid' => $previousCurrentVersionUid,
            ] + $this->mcpContext->auditMetadata(),
        );
    }
}
