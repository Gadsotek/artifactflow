<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\InvalidPageStatusTransition;
use App\Domain\PageCatalog\PageSecurityScanStatus;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PdfVersionFact;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class ReprocessPdfArtifact
{
    public function __construct(
        private PageAccess $access,
        private ArtifactContentReader $contentReader,
        private PageContentPreparer $contentPreparer,
        private RecordBlockedPageContentScan $recordBlockedScan,
        private PageSearchVectorUpdater $searchVectorUpdater,
        private PageSecurityWarningRecorder $securityWarnings,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private PdfProcessorConfiguration $configuration,
        private PdfExtractionPersistence $pdfExtractionPersistence,
    ) {
    }

    /**
     * Refresh the current version's derived PDF projections without creating a
     * new content version or changing the retained original.
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, ReprocessPdfArtifactCommand $command): PageVersion
    {
        $page = PageFinder::requireByUid($command->pageUid);
        $this->ensureCanReprocess($actor, $page, $command->expectedCurrentVersionUid);
        $version = $this->currentVersion($page);
        $original = $this->verifiedOriginal($version);
        $actorUid = ActorId::fromUser($actor);

        try {
            // Native parsing and the processor network round trip must never hold
            // a database transaction or page row lock.
            $prepared = $this->contentPreparer->prepare(
                PageType::Pdf,
                $original,
                $actorUid,
                $version->source,
            );
        } catch (BlockedPageContentException $exception) {
            $this->recordBlockedScan->forPageVersion(
                $actor,
                $page,
                $exception->findingCodes(),
                'reprocess_pdf',
            );

            throw $exception;
        }

        $result = $prepared->pdfProcessingResult;

        if (!$result instanceof PdfProcessingResult) {
            throw new DomainRuleViolation('PDF processor did not return PDF facts.');
        }
        $extraction = $this->pdfExtractionPersistence->fromResult($result);

        return DB::transaction(function () use (
            $actor,
            $actorUid,
            $command,
            $prepared,
            $extraction,
            $result,
            $version,
        ): PageVersion {
            $lockedPage = $this->access->lockAndReauthorize(
                $command->pageUid,
                function (Page $page) use ($actor): void {
                    if (!$this->access->canEdit($actor, $page)) {
                        throw new AuthorizationException('You cannot edit this page.');
                    }
                },
            );
            $this->ensureReprocessableState($lockedPage, $command->expectedCurrentVersionUid);
            $lockedVersion = $this->lockedCurrentVersion($lockedPage);
            $this->ensureSameOriginal($version, $lockedVersion);
            $facts = PdfVersionFact::query()->whereKey($lockedVersion->uid)->lockForUpdate()->first();

            if (!$facts instanceof PdfVersionFact) {
                throw new DomainRuleViolation('PDF processing facts are unavailable.');
            }

            $scan = $prepared->scan;
            $lockedVersion->forceFill([
                'extracted_text' => $extraction->text,
                'scan_status' => $scan->hasWarningFindings()
                    ? PageSecurityScanStatus::Warnings
                    : PageSecurityScanStatus::Clean,
                'scan_findings' => $scan->persistedFindings(),
            ])->save();
            $facts->forceFill([
                'page_count' => $result->pageCount,
                'pdf_version' => $result->pdfVersion,
                'extraction_state' => $extraction->state,
                'processor_profile' => $result->processorProfile,
            ])->save();

            $this->searchVectorUpdater->refreshPage($lockedPage->uid);

            if ($scan->hasWarningFindings()) {
                $this->securityWarnings->record($lockedPage, $lockedVersion, $actorUid, $scan);
            }

            $event = $this->events->record(
                eventType: DomainEventType::PagePdfReprocessed,
                aggregateType: 'page',
                aggregateUid: $lockedPage->uid,
                payload: [
                    'workspace_uid' => $lockedPage->workspace_uid,
                    'page_uid' => $lockedPage->uid,
                    'page_version_uid' => $lockedVersion->uid,
                    'actor_user_uid' => $actorUid,
                    'pdf_extraction_state' => $extraction->state->value,
                    'scan_status' => $lockedVersion->scan_status->value,
                ],
            );
            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'page_version',
                auditableUid: $lockedVersion->uid,
                action: DomainEventType::PagePdfReprocessed,
                summary: 'PDF derived text and facts reprocessed.',
                metadata: [
                    'workspace_uid' => $lockedPage->workspace_uid,
                    'page_uid' => $lockedPage->uid,
                    'pdf_extraction_state' => $extraction->state->value,
                    'scan_status' => $lockedVersion->scan_status->value,
                ],
            );

            return $lockedVersion->refresh();
        });
    }

    /** @throws AuthorizationException */
    private function ensureCanReprocess(User $actor, Page $page, string $expectedCurrentVersionUid): void
    {
        if (!$this->access->canEdit($actor, $page)) {
            throw new AuthorizationException('You cannot edit this page.');
        }

        $this->ensureReprocessableState($page, $expectedCurrentVersionUid);
    }

    private function ensureReprocessableState(Page $page, string $expectedCurrentVersionUid): void
    {
        if ($page->type !== PageType::Pdf) {
            throw new DomainRuleViolation('Only PDF artifacts can be reprocessed.');
        }

        if (!$this->configuration->enabled()) {
            throw new DomainRuleViolation('PDF artifacts are disabled for this installation.');
        }

        if ($page->status === PageStatus::Archived) {
            throw new InvalidPageStatusTransition(
                'Archived pages must be unarchived before reprocessing.',
            );
        }

        if ($page->current_version_uid !== $expectedCurrentVersionUid) {
            throw new StalePageVersionException(
                currentVersionUid: (string) $page->current_version_uid,
                submittedBaseVersionUid: $expectedCurrentVersionUid,
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
            throw new DomainRuleViolation('PDF current version is unavailable.');
        }

        return $version;
    }

    private function lockedCurrentVersion(Page $page): PageVersion
    {
        $version = PageVersion::query()
            ->whereKey($page->current_version_uid)
            ->where('page_uid', $page->uid)
            ->lockForUpdate()
            ->first();

        if (!$version instanceof PageVersion) {
            throw new DomainRuleViolation('PDF current version is unavailable.');
        }

        return $version;
    }

    private function verifiedOriginal(PageVersion $version): string
    {
        $content = $this->contentReader->read(
            $version->content_storage_path,
            PdfProcessorConfiguration::MAX_INPUT_BYTES,
        );

        if (
            !is_string($content)
            || strlen($content) !== $version->byte_size
            || !hash_equals($version->content_hash, hash('sha256', $content))
        ) {
            throw new DomainRuleViolation(
                'The retained PDF original is unavailable or failed integrity verification.',
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
