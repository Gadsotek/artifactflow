<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Application\Mcp\McpRequestContext;
use App\Application\Provenance\VersionLineage;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Domain\Provenance\VersionOperation;
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
        private PageVersionChangeSummaryRules $changeSummaryRules,
        private PageVersionStorage $versionStorage,
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

        $this->ensureExpectedCurrentVersion($page, $command->expectedCurrentVersionUid);

        $sourceContent = $this->contentReader->read($sourceVersion->content_storage_path);

        if ($sourceContent === null) {
            throw new DomainRuleViolation('Version content is missing from storage.');
        }

        if (
            strlen($sourceContent) !== $sourceVersion->byte_size
            || !hash_equals($sourceVersion->content_hash, hash('sha256', $sourceContent))
        ) {
            throw new DomainRuleViolation('Version content failed integrity verification.');
        }

        $restoredVersion = null;
        $restoredStoragePaths = [];
        $prunedStoragePaths = [];
        $preparedAppend = null;
        $transactionCallbackCompleted = false;

        try {
            $changeSummary = $this->changeSummaryRules->normalize($command->changeSummary);
            $preparedAppend = $this->versions->prepare(
                $actor,
                $page,
                $sourceContent,
                PageVersionSource::Restore,
                expectedCurrentVersionUid: $command->expectedCurrentVersionUid,
                operation: VersionOperation::Restore,
                lineage: new VersionLineage(
                    sourceVersionUid: $sourceVersion->uid,
                    sourceContentHash: $sourceVersion->content_hash,
                ),
                changeSummary: $changeSummary,
            );

            $restored = DB::transaction(function () use (
                $actor,
                $actorUid,
                $command,
                $page,
                &$prunedStoragePaths,
                &$restoredStoragePaths,
                &$restoredVersion,
                &$transactionCallbackCompleted,
                $preparedAppend,
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
                $restoredVersion = $preparedAppend->append(
                    page: $page,
                    expectedCurrentVersionUid: $command->expectedCurrentVersionUid,
                );
                $restoredStoragePaths = $this->versionStorage->paths($restoredVersion);

                $this->recordRestored($page, $sourceVersion, $restoredVersion, $actorUid, $previousCurrentVersionUid);

                $prunedStoragePaths = $this->versionPruner->pruneToCap($page, $actorUid);
                $transactionCallbackCompleted = true;

                return $restoredVersion;
            });
        } catch (Throwable $exception) {
            // Preserve promoted paths after callback completion because PostgreSQL
            // may have committed before the connection failure was observed.
            if (!$transactionCallbackCompleted && $restoredStoragePaths !== []) {
                Storage::disk('artifacts')->delete($restoredStoragePaths);
            }

            if ($exception instanceof BlockedPageContentException) {
                $this->recordBlockedScan->forPageVersion($actor, $page, $exception->findingCodes());
            }

            throw $exception;
        } finally {
            $preparedAppend?->discard();
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

    /**
     * Refuse an already-stale request before reading content or spending parser
     * capacity. PageVersionAppender repeats this assertion under the page lock,
     * which remains the authoritative guard against a concurrent save during
     * preparation.
     */
    private function ensureExpectedCurrentVersion(Page $page, ?string $expectedCurrentVersionUid): void
    {
        if ($expectedCurrentVersionUid === null || $page->current_version_uid === $expectedCurrentVersionUid) {
            return;
        }

        throw new StalePageVersionException(
            currentVersionUid: (string) $page->current_version_uid,
            submittedBaseVersionUid: $expectedCurrentVersionUid,
        );
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
