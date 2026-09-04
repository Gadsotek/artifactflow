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
use App\Models\DocxVersionFact;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class ReprocessDocxArtifact
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
        private DocxProcessorConfiguration $configuration,
        private PdfProcessorConfiguration $pdfConfiguration,
        private PdfExtractionPersistence $pdfExtractionPersistence,
        private OfficeArtifactStoragePreflight $officeStoragePreflight,
    ) {
    }

    /** @throws AuthorizationException */
    public function handle(User $actor, ReprocessDocxArtifactCommand $command): PageVersion
    {
        $page = PageFinder::requireByUid($command->pageUid);
        $this->ensureCanReprocess($actor, $page, $command->expectedCurrentVersionUid);
        $version = $this->currentVersion($page);
        $this->officeStoragePreflight->forDerivativeReplacement($page, $this->currentDerivative($version));
        $original = $this->verifiedOriginal($version);
        $actorUid = ActorId::fromUser($actor);

        try {
            $prepared = $this->contentPreparer->prepare(
                PageType::Docx,
                $original,
                $actorUid,
                $version->source,
            );
        } catch (BlockedPageContentException $exception) {
            $this->recordBlockedScan->forPageVersion(
                $actor,
                $page,
                $exception->findingCodes(),
                'reprocess_docx',
            );

            throw $exception;
        }

        $result = $prepared->docxProcessingResult;
        $candidate = $prepared->derivative;
        if (!$result instanceof DocxProcessingResult || !$candidate instanceof PreparedArtifactDerivative) {
            throw new DomainRuleViolation('DOCX processors did not return a PDF preview and facts.');
        }
        $extraction = $this->pdfExtractionPersistence->fromResult($result->pdf);
        $staged = $this->stager->stageDerivative($candidate);
        $newStoragePath = sprintf(
            'pages/%s/versions/%d-%s/preview-%s.pdf',
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
                $extraction,
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
                $facts = DocxVersionFact::query()->whereKey($lockedVersion->uid)->lockForUpdate()->first();
                if (!$facts instanceof DocxVersionFact) {
                    throw new DomainRuleViolation('DOCX processing facts are unavailable.');
                }
                $derivative = PageVersionDerivative::query()
                    ->whereKey($facts->preview_derivative_uid)
                    ->lockForUpdate()
                    ->first();
                if (
                    !$derivative instanceof PageVersionDerivative
                    || $derivative->page_version_uid !== $lockedVersion->uid
                    || $derivative->kind !== ArtifactDerivativeKind::DocxPreviewPdf
                ) {
                    throw new DomainRuleViolation('DOCX preview derivative is unavailable.');
                }

                $lockedWorkspace = $this->storageQuota->lockWorkspaceForStorageUpdate($lockedPage->workspace_uid);
                $byteDelta = $staged->byteSize() - $derivative->byte_size;
                if ($byteDelta > 0) {
                    $this->storageQuota->ensureWorkspaceAllowsNewBytes($lockedWorkspace, $byteDelta);
                    $this->storageQuota->ensurePageAllowsAdditionalStoredBytes($lockedPage->uid, $byteDelta);
                }
                if (!$staged->stagedContent instanceof StagedArtifactContent) {
                    throw new DomainRuleViolation('DOCX preview staging is unavailable.');
                }

                $staged->stagedContent->promoteTo($newStoragePath, 'Failed to store reprocessed DOCX preview.');
                $newPromoted = true;
                $oldStoragePath = $derivative->storage_path;
                $derivative->forceFill([
                    'storage_path' => $newStoragePath,
                    'content_hash' => $staged->contentHash(),
                    'byte_size' => $staged->byteSize(),
                ])->save();
                $scan = $prepared->scan;
                $lockedVersion->forceFill([
                    'extracted_text' => $extraction->text,
                    'scan_status' => $scan->hasWarningFindings()
                        ? PageSecurityScanStatus::Warnings
                        : PageSecurityScanStatus::Clean,
                    'scan_findings' => $scan->persistedFindings(),
                ])->save();
                $this->updateFacts($facts, $result, $extraction);

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
                    eventType: DomainEventType::PageDocxReprocessed,
                    aggregateType: 'page',
                    aggregateUid: $lockedPage->uid,
                    payload: [
                        'workspace_uid' => $lockedPage->workspace_uid,
                        'page_uid' => $lockedPage->uid,
                        'page_version_uid' => $lockedVersion->uid,
                        'actor_user_uid' => $actorUid,
                        'docx_extraction_state' => $extraction->state->value,
                        'scan_status' => $lockedVersion->scan_status->value,
                    ],
                );
                $this->audit->record(
                    event: $event,
                    actorUserUid: $actorUid,
                    auditableType: 'page_version',
                    auditableUid: $lockedVersion->uid,
                    action: DomainEventType::PageDocxReprocessed,
                    summary: 'DOCX preview, search text, and facts reprocessed.',
                    metadata: [
                        'workspace_uid' => $lockedPage->workspace_uid,
                        'page_uid' => $lockedPage->uid,
                        'docx_extraction_state' => $extraction->state->value,
                        'scan_status' => $lockedVersion->scan_status->value,
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
            Log::warning('page.docx.reprocess.old_preview_delete_failed', [
                'page_uid' => $page->uid,
                'storage_path_hash' => hash('sha256', $oldStoragePath),
            ]);
        }

        return $updated;
    }

    private function updateFacts(
        DocxVersionFact $facts,
        DocxProcessingResult $result,
        PersistedPdfExtraction $extraction,
    ): void {
        $conversion = $result->conversion;
        $facts->forceFill([
            'docx_processor_profile' => $conversion->processorProfile,
            'pdf_processor_profile' => $result->pdf->processorProfile,
            'engine_name' => $conversion->engineName,
            'engine_version' => $conversion->engineVersion,
            'package_entry_count' => $conversion->packageEntryCount,
            'expanded_bytes' => $conversion->expandedBytes,
            'relationship_count' => $conversion->relationshipCount,
            'media_count' => $conversion->mediaCount,
            'external_hyperlink_count' => $conversion->externalHyperlinkCount,
            'page_count' => $result->pdf->pageCount,
            'pdf_version' => $result->pdf->pdfVersion,
            'extraction_state' => $extraction->state,
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
        if ($page->type !== PageType::Docx) {
            throw new DomainRuleViolation('Only Word document artifacts can be reprocessed.');
        }
        if (!$this->configuration->enabled() || !$this->pdfConfiguration->enabled()) {
            throw new DomainRuleViolation('Word document artifacts are disabled for this installation.');
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
            throw new DomainRuleViolation('DOCX current version is unavailable.');
        }

        return $version;
    }

    private function currentDerivative(PageVersion $version): PageVersionDerivative
    {
        $derivative = PageVersionDerivative::query()
            ->where('page_version_uid', $version->uid)
            ->where('kind', ArtifactDerivativeKind::DocxPreviewPdf)
            ->first();

        if (
            !$derivative instanceof PageVersionDerivative
            || $derivative->page_version_uid !== $version->uid
            || $derivative->kind !== ArtifactDerivativeKind::DocxPreviewPdf
        ) {
            throw new DomainRuleViolation('DOCX preview derivative is unavailable.');
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
            throw new DomainRuleViolation('DOCX current version is unavailable.');
        }

        return $version;
    }

    private function verifiedOriginal(PageVersion $version): string
    {
        $content = $this->contentReader->read(
            $version->content_storage_path,
            DocxProcessorConfiguration::MAX_INPUT_BYTES,
        );
        if (
            !is_string($content)
            || strlen($content) !== $version->byte_size
            || !hash_equals($version->content_hash, hash('sha256', $content))
        ) {
            throw new DomainRuleViolation(
                'The retained DOCX original is unavailable or failed integrity verification.',
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
