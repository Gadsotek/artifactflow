<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\ArtifactDerivativeKind;
use App\Domain\PageCatalog\InvalidPageStatusTransition;
use App\Domain\PageCatalog\PageSecurityScanStatus;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use App\Models\User;
use App\Models\XlsxVersionFact;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class ReprocessXlsxArtifact
{
    public function __construct(
        private PageAccess $access,
        private ArtifactContentReader $contentReader,
        private PageContentPreparer $contentPreparer,
        private PageContentStager $stager,
        private RecordBlockedPageContentScan $recordBlockedScan,
        private PageSearchVectorUpdater $searchVectorUpdater,
        private PageSecurityWarningRecorder $securityWarnings,
        private WorkspaceStorageQuota $storageQuota,
        private ArtifactContentDeleter $contentDeleter,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private XlsxProcessorConfiguration $configuration,
        private OfficeArtifactStoragePreflight $officeStoragePreflight,
    ) {
    }

    /** @throws AuthorizationException */
    public function handle(User $actor, ReprocessXlsxArtifactCommand $command): PageVersion
    {
        $page = PageFinder::requireByUid($command->pageUid);
        $this->ensureCanReprocess($actor, $page, $command->expectedCurrentVersionUid);
        $version = $this->currentVersion($page);
        $this->officeStoragePreflight->forDerivativeReplacement($page, $this->currentDerivative($version));
        $original = $this->verifiedOriginal($version);
        $actorUid = ActorId::fromUser($actor);

        try {
            $prepared = $this->contentPreparer->prepare(
                PageType::Xlsx,
                $original,
                $actorUid,
                $version->source,
            );
        } catch (BlockedPageContentException $exception) {
            $this->recordBlockedScan->forPageVersion(
                $actor,
                $page,
                $exception->findingCodes(),
                'reprocess_xlsx',
            );

            throw $exception;
        }

        $result = $prepared->xlsxProcessingResult;
        $candidate = $prepared->derivative;

        if (!$result instanceof XlsxProcessingResult || !$candidate instanceof PreparedArtifactDerivative) {
            throw new DomainRuleViolation('XLSX processor did not return a manifest and facts.');
        }

        $staged = $this->stager->stageDerivative($candidate);
        $newStoragePath = sprintf(
            'pages/%s/versions/%d-%s/manifest-%s.json',
            $page->uid,
            $version->version_number,
            $version->uid,
            strtolower((string) Str::ulid()),
        );
        $newPromoted = false;
        $oldStoragePath = null;
        $transactionCallbackCompleted = false;

        try {
            $updated = DB::transaction(function () use (
                $actor,
                $actorUid,
                $command,
                $prepared,
                $result,
                $staged,
                $newStoragePath,
                $version,
                &$newPromoted,
                &$oldStoragePath,
                &$transactionCallbackCompleted,
            ): PageVersion {
                $lockedPage = $this->access->lockAndReauthorize(
                    $command->pageUid,
                    function (Page $candidatePage) use ($actor): void {
                        if (!$this->access->canEdit($actor, $candidatePage)) {
                            throw new AuthorizationException('You cannot edit this page.');
                        }
                    },
                );
                $this->ensureReprocessableState($lockedPage, $command->expectedCurrentVersionUid);
                $lockedVersion = $this->lockedCurrentVersion($lockedPage);
                $this->ensureSameOriginal($version, $lockedVersion);
                $facts = XlsxVersionFact::query()->whereKey($lockedVersion->uid)->lockForUpdate()->first();

                if (!$facts instanceof XlsxVersionFact) {
                    throw new DomainRuleViolation('XLSX processing facts are unavailable.');
                }

                $derivative = PageVersionDerivative::query()
                    ->whereKey($facts->manifest_derivative_uid)
                    ->lockForUpdate()
                    ->first();

                if (
                    !$derivative instanceof PageVersionDerivative
                    || $derivative->page_version_uid !== $lockedVersion->uid
                    || $derivative->kind !== ArtifactDerivativeKind::XlsxManifest
                ) {
                    throw new DomainRuleViolation('XLSX manifest derivative is unavailable.');
                }

                $lockedWorkspace = $this->storageQuota->lockWorkspaceForStorageUpdate($lockedPage->workspace_uid);
                $byteDelta = $staged->byteSize() - $derivative->byte_size;

                if ($byteDelta > 0) {
                    $this->storageQuota->ensureWorkspaceAllowsNewBytes($lockedWorkspace, $byteDelta);
                    $this->storageQuota->ensurePageAllowsAdditionalStoredBytes($lockedPage->uid, $byteDelta);
                }

                if (!$staged->stagedContent instanceof StagedArtifactContent) {
                    throw new DomainRuleViolation('XLSX manifest staging is unavailable.');
                }

                $staged->stagedContent->promoteTo($newStoragePath, 'Failed to store reprocessed XLSX manifest.');
                $newPromoted = true;
                $oldStoragePath = $derivative->storage_path;
                $derivative->forceFill([
                    'storage_path' => $newStoragePath,
                    'content_hash' => $staged->contentHash(),
                    'byte_size' => $staged->byteSize(),
                ])->save();
                $scan = $prepared->scan;
                $lockedVersion->forceFill([
                    'extracted_text' => mb_substr(
                        $result->searchText,
                        0,
                        PageSearchVectorUpdater::MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS,
                    ),
                    'scan_status' => $scan->hasWarningFindings()
                        ? PageSecurityScanStatus::Warnings
                        : PageSecurityScanStatus::Clean,
                    'scan_findings' => $scan->persistedFindings(),
                ])->save();
                $this->updateFacts($facts, $result);

                if ($byteDelta > 0) {
                    $this->storageQuota->recordBytesStored($lockedPage->workspace_uid, $byteDelta);
                } elseif ($byteDelta < 0) {
                    $this->storageQuota->recordBytesReleased($lockedPage->workspace_uid, -$byteDelta);
                }

                $this->searchVectorUpdater->refreshPage($lockedPage->uid);

                if ($scan->hasWarningFindings()) {
                    $this->securityWarnings->record($lockedPage, $lockedVersion, $actorUid, $scan);
                }

                $event = $this->events->record(
                    eventType: DomainEventType::PageXlsxReprocessed,
                    aggregateType: 'page',
                    aggregateUid: $lockedPage->uid,
                    payload: [
                        'workspace_uid' => $lockedPage->workspace_uid,
                        'page_uid' => $lockedPage->uid,
                        'page_version_uid' => $lockedVersion->uid,
                        'actor_user_uid' => $actorUid,
                        'scan_status' => $lockedVersion->scan_status->value,
                        'xlsx_truncated' => $result->truncated,
                    ],
                );
                $this->audit->record(
                    event: $event,
                    actorUserUid: $actorUid,
                    auditableType: 'page_version',
                    auditableUid: $lockedVersion->uid,
                    action: DomainEventType::PageXlsxReprocessed,
                    summary: 'XLSX manifest, search text, and facts reprocessed.',
                    metadata: [
                        'workspace_uid' => $lockedPage->workspace_uid,
                        'page_uid' => $lockedPage->uid,
                        'scan_status' => $lockedVersion->scan_status->value,
                        'xlsx_truncated' => $result->truncated,
                    ],
                );
                $refreshed = $lockedVersion->refresh();
                $transactionCallbackCompleted = true;

                return $refreshed;
            });
        } catch (Throwable $exception) {
            if (!$transactionCallbackCompleted && $newPromoted) {
                Storage::disk('artifacts')->delete($newStoragePath);
            }

            throw $exception;
        } finally {
            $staged->discardStaging();
        }

        if (is_string($oldStoragePath) && !$this->contentDeleter->deleteMany([$oldStoragePath])) {
            Log::warning('page.xlsx.reprocess.old_manifest_delete_failed', [
                'page_uid' => $page->uid,
                'storage_path_hash' => hash('sha256', $oldStoragePath),
            ]);
        }

        return $updated;
    }

    private function updateFacts(XlsxVersionFact $facts, XlsxProcessingResult $result): void
    {
        $facts->forceFill([
            'processor_profile' => $result->processorProfile,
            'manifest_schema' => 'xlsx-view-manifest-v1',
            'engine_name' => $result->engineName,
            'engine_version' => $result->engineVersion,
            'package_entry_count' => $result->packageEntryCount,
            'expanded_bytes' => $result->expandedBytes,
            'visible_sheet_count' => $result->visibleSheetCount,
            'omitted_hidden_sheet_count' => $result->omittedHiddenSheetCount,
            'projected_row_extent_count' => $result->projectedRowExtentCount,
            'projected_column_extent_count' => $result->projectedColumnExtentCount,
            'omitted_hidden_row_count' => $result->omittedHiddenRowCount,
            'omitted_hidden_column_count' => $result->omittedHiddenColumnCount,
            'cell_count' => $result->cellCount,
            'formula_count' => $result->formulaCount,
            'uncached_formula_count' => $result->formulasWithoutCachedResultCount,
            'link_count' => $result->linkCount,
            'merge_count' => $result->mergeCount,
            'truncated' => $result->truncated,
            'processed_at' => now(),
        ])->save();
    }

    /** @throws AuthorizationException */
    private function ensureCanReprocess(User $actor, Page $page, string $expectedVersionUid): void
    {
        if (!$this->access->canEdit($actor, $page)) {
            throw new AuthorizationException('You cannot edit this page.');
        }

        $this->ensureReprocessableState($page, $expectedVersionUid);
    }

    private function ensureReprocessableState(Page $page, string $expectedVersionUid): void
    {
        if ($page->type !== PageType::Xlsx) {
            throw new DomainRuleViolation('Only Excel workbook artifacts can be reprocessed.');
        }

        if (!$this->configuration->enabled()) {
            throw new DomainRuleViolation('XLSX artifacts are disabled for this installation.');
        }

        if ($page->status === PageStatus::Archived) {
            throw new InvalidPageStatusTransition('Archived pages must be unarchived before reprocessing.');
        }

        if ($page->current_version_uid !== $expectedVersionUid) {
            throw new StalePageVersionException(
                currentVersionUid: (string) $page->current_version_uid,
                submittedBaseVersionUid: $expectedVersionUid,
            );
        }
    }

    private function currentVersion(Page $page): PageVersion
    {
        $version = PageVersion::query()
            ->whereKey($page->current_version_uid)
            ->where('page_uid', $page->uid)
            ->first();

        if (!$version instanceof PageVersion) {
            throw new DomainRuleViolation('XLSX current version is unavailable.');
        }

        return $version;
    }

    private function currentDerivative(PageVersion $version): PageVersionDerivative
    {
        $derivative = PageVersionDerivative::query()
            ->where('page_version_uid', $version->uid)
            ->where('kind', ArtifactDerivativeKind::XlsxManifest)
            ->first();

        if (
            !$derivative instanceof PageVersionDerivative
            || $derivative->page_version_uid !== $version->uid
            || $derivative->kind !== ArtifactDerivativeKind::XlsxManifest
        ) {
            throw new DomainRuleViolation('XLSX manifest derivative is unavailable.');
        }

        return $derivative;
    }

    private function lockedCurrentVersion(Page $page): PageVersion
    {
        $version = PageVersion::query()
            ->whereKey($page->current_version_uid)
            ->where('page_uid', $page->uid)
            ->lockForUpdate()
            ->first();

        if (!$version instanceof PageVersion) {
            throw new DomainRuleViolation('XLSX current version is unavailable.');
        }

        return $version;
    }

    private function verifiedOriginal(PageVersion $version): string
    {
        $content = $this->contentReader->read(
            $version->content_storage_path,
            XlsxProcessorConfiguration::MAX_INPUT_BYTES,
        );

        if (
            !is_string($content)
            || strlen($content) !== $version->byte_size
            || !hash_equals($version->content_hash, hash('sha256', $content))
        ) {
            throw new DomainRuleViolation(
                'The retained XLSX original is unavailable or failed integrity verification.',
            );
        }

        return $content;
    }

    private function ensureSameOriginal(PageVersion $beforeProcessing, PageVersion $locked): void
    {
        if (
            $locked->content_storage_path !== $beforeProcessing->content_storage_path
            || $locked->content_hash !== $beforeProcessing->content_hash
            || $locked->byte_size !== $beforeProcessing->byte_size
        ) {
            throw new StalePageVersionException(
                currentVersionUid: $locked->uid,
                submittedBaseVersionUid: $beforeProcessing->uid,
            );
        }
    }
}
