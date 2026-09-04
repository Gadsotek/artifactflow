<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Mcp\McpRequestContext;
use App\Application\Provenance\RecordPageVersionProvenance;
use App\Application\Provenance\VersionLineage;
use App\Application\Provenance\VersionProvenanceInput;
use App\Application\Provenance\VersionProvenanceRules;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\PageContentEncoding;
use App\Domain\PageCatalog\PageSecurityScanStatus;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\Provenance\VersionOperation;
use App\Models\DocxVersionFact;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use App\Models\PdfVersionFact;
use App\Models\XlsxVersionFact;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

final readonly class PageVersionWriter
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private McpRequestContext $mcpContext,
        private WorkspaceStorageQuota $storageQuota,
        private RecordPageVersionProvenance $provenanceRecorder,
        private VersionProvenanceRules $provenanceRules,
        private PageVersionChangeSummaryRules $changeSummaryRules,
        private PdfExtractionPersistence $pdfExtractionPersistence,
    ) {
    }

    public function writeInitialVersion(
        Page $page,
        PreparedPageContent $prepared,
        PageVersionSource $source,
        string $actorUid,
        ?VersionProvenanceInput $provenance = null,
        ?string $changeSummary = null,
    ): PageVersion {
        return $this->write(
            page: $page,
            prepared: $prepared,
            source: $source,
            actorUid: $actorUid,
            versionNumber: 1,
            failureMessage: 'Failed to store page content.',
            operation: VersionOperation::Create,
            provenance: $provenance,
            lineage: null,
            changeSummary: $changeSummary,
        );
    }

    public function appendVersion(
        Page $page,
        PreparedPageContent $prepared,
        PageVersionSource $source,
        string $actorUid,
        ?VersionProvenanceInput $provenance = null,
        VersionOperation $operation = VersionOperation::Update,
        ?VersionLineage $lineage = null,
        ?string $changeSummary = null,
    ): PageVersion {
        return $this->write(
            page: $page,
            prepared: $prepared,
            source: $source,
            actorUid: $actorUid,
            versionNumber: $this->nextVersionNumber($page),
            failureMessage: 'Failed to store page version content.',
            operation: $operation,
            provenance: $provenance,
            lineage: $lineage,
            changeSummary: $changeSummary,
        );
    }

    private function write(
        Page $page,
        PreparedPageContent $prepared,
        PageVersionSource $source,
        string $actorUid,
        int $versionNumber,
        string $failureMessage,
        VersionOperation $operation,
        ?VersionProvenanceInput $provenance,
        ?VersionLineage $lineage,
        ?string $changeSummary,
    ): PageVersion {
        $changeSummary = $this->changeSummaryRules->normalize($changeSummary);
        $this->provenanceRules->ensureValid($provenance);

        $content = $prepared->content;
        $scan = $prepared->scan;
        // Last-line guard shared by every write path (editor, upload, MCP): the
        // derived source_text/extracted_text columns are PostgreSQL text and
        // cannot hold a NUL byte or malformed UTF-8. HTTP requests are screened
        // earlier for a field-level 422; this backstop keeps the MCP path and any
        // future caller from turning bad bytes into a 500 mid-transaction.
        if ($prepared->textProjection->sourceText !== null && !PageContentEncoding::isStorable($content)) {
            throw new DomainRuleViolation('Page content must be valid UTF-8 text without control characters.');
        }

        $versionUid = (string) Str::ulid();
        $storagePath = $this->storagePath(
            $page,
            $versionNumber,
            $versionUid,
            $prepared->storageFilename,
        );
        $derivativeStoragePath = $prepared->derivative instanceof PreparedArtifactDerivative
            ? $this->storagePath(
                $page,
                $versionNumber,
                $versionUid,
                $prepared->derivative->storageFilename,
            )
            : null;
        $effectivePdfResult = $prepared->pdfProcessingResult
            ?? $prepared->docxProcessingResult?->pdf;
        $pdfExtraction = $effectivePdfResult instanceof PdfProcessingResult
            ? $this->pdfExtractionPersistence->fromResult($effectivePdfResult)
            : null;

        if ($prepared->requiresPrivateStaging && !($prepared->stagedContent instanceof StagedArtifactContent)) {
            throw new LogicException('Prepared artifact content must be privately staged before persistence.');
        }

        if (
            $prepared->derivative instanceof PreparedArtifactDerivative
            && !($prepared->derivative->stagedContent instanceof StagedArtifactContent)
        ) {
            throw new LogicException('Prepared artifact derivative must be privately staged before persistence.');
        }

        try {
            if ($prepared->stagedContent instanceof StagedArtifactContent) {
                $prepared->stagedContent->promoteTo($storagePath, $failureMessage);
            } elseif (Storage::disk('artifacts')->put($storagePath, $content) === false) {
                throw new RuntimeException($failureMessage);
            }

            if (
                $prepared->derivative instanceof PreparedArtifactDerivative
                && is_string($derivativeStoragePath)
            ) {
                $prepared->derivative->stagedContent->promoteTo(
                    $derivativeStoragePath,
                    'Failed to store artifact derivative.',
                );
            }
        } catch (Throwable $exception) {
            Storage::disk('artifacts')->delete(array_filter([
                $storagePath,
                $derivativeStoragePath,
            ], is_string(...)));

            throw $exception;
        }

        try {
            $version = PageVersion::query()->forceCreate([
                'uid' => $versionUid,
                'page_uid' => $page->uid,
                'version_number' => $versionNumber,
                'content_storage_path' => $storagePath,
                'content_hash' => $prepared->contentHash(),
                'byte_size' => $prepared->originalByteSize(),
                'scan_status' => $scan->hasWarningFindings()
                    ? PageSecurityScanStatus::Warnings
                    : PageSecurityScanStatus::Clean,
                'scan_findings' => $scan->persistedFindings(),
                'source' => $source,
                'change_summary' => $changeSummary,
                'created_by_user_uid' => $actorUid,
                // Cap at write like source_text: search only indexes and snippets the
                // first MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS, so persisting more is dead
                // weight that TOAST-bloats the row.
                'extracted_text' => $pdfExtraction->text
                    ?? $this->cappedText($prepared->textProjection->extractedText),
                'source_text' => $this->cappedText($prepared->textProjection->sourceText),
            ]);
            $derivative = $this->persistDerivative($version, $prepared, $derivativeStoragePath);
            $version->setRelation(
                'derivatives',
                new \Illuminate\Database\Eloquent\Collection(
                    $derivative instanceof PageVersionDerivative ? [$derivative] : [],
                ),
            );
            $pdfMetadata = $this->persistPdfFacts(
                $version,
                $prepared->pdfProcessingResult,
                $pdfExtraction,
            );
            $xlsxMetadata = $this->persistXlsxFacts($version, $prepared->xlsxProcessingResult, $derivative);
            $docxMetadata = $this->persistDocxFacts(
                $version,
                $prepared->docxProcessingResult,
                $pdfExtraction,
                $derivative,
            );

            $this->storageQuota->recordBytesStored($page->workspace_uid, $prepared->byteSize());
            $this->clearPreviousCurrentVersionExtractedText($page);
            $this->recordPageVersionCreated(
                $page,
                $version,
                $actorUid,
                $provenance?->wasSupplied() ?? false,
                $pdfMetadata + $xlsxMetadata + $docxMetadata,
            );
            $this->provenanceRecorder->record(
                page: $page,
                version: $version,
                actorUid: $actorUid,
                source: $source,
                operation: $operation,
                declared: $provenance,
                lineage: $lineage,
            );

            return $version;
        } catch (Throwable $exception) {
            Storage::disk('artifacts')->delete(array_filter([
                $storagePath,
                $derivativeStoragePath,
            ], is_string(...)));

            throw $exception;
        }
    }

    /**
     * Only the current version's extracted_text is ever read (search vector,
     * search snippets, MCP read). Restore, revert, and reindex re-extract from
     * the stored artifact file, so the text of the version being replaced can
     * be dropped instead of keeping a full copy per historic version.
     */
    private function clearPreviousCurrentVersionExtractedText(Page $page): void
    {
        if ($page->current_version_uid === null) {
            return;
        }

        PageVersion::query()
            ->whereKey($page->current_version_uid)
            ->update(['extracted_text' => null]);
    }

    private function nextVersionNumber(Page $page): int
    {
        $maxVersionNumber = PageVersion::query()
            ->where('page_uid', $page->uid)
            ->max('version_number');

        if (is_int($maxVersionNumber)) {
            return $maxVersionNumber + 1;
        }

        if (is_string($maxVersionNumber) && ctype_digit($maxVersionNumber)) {
            return ((int) $maxVersionNumber) + 1;
        }

        return 1;
    }

    private function storagePath(
        Page $page,
        int $versionNumber,
        string $versionUid,
        string $storageFilename,
    ): string {
        if (
            preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $storageFilename) !== 1
            || str_contains($storageFilename, '..')
        ) {
            throw new LogicException('Prepared page content has an invalid storage filename.');
        }

        return sprintf(
            'pages/%s/versions/%d-%s/%s',
            $page->uid,
            $versionNumber,
            $versionUid,
            $storageFilename,
        );
    }

    private function cappedText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return mb_substr(
            $text,
            0,
            PageSearchVectorUpdater::MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS,
        );
    }

    /**
     * @param array<string, bool|int|string|null> $pdfMetadata
     */
    private function recordPageVersionCreated(
        Page $page,
        PageVersion $version,
        string $actorUid,
        bool $provenanceSuppliedAtIngest,
        array $pdfMetadata,
    ): void {
        $mcpMetadata = $this->mcpContext->auditMetadata();
        $event = $this->events->record(
            eventType: DomainEventType::PageVersionCreated,
            aggregateType: 'page',
            aggregateUid: $page->uid,
            payload: [
                'page_uid' => $page->uid,
                'page_version_uid' => $version->uid,
                'version_number' => $version->version_number,
                'created_by_user_uid' => $actorUid,
                'content_hash' => $version->content_hash,
                'byte_size' => $version->byte_size,
                'scan_status' => $version->scan_status->value,
                'source' => $version->source->value,
                'provenance_supplied_at_ingest' => $provenanceSuppliedAtIngest,
            ] + $pdfMetadata + $mcpMetadata,
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUid,
            auditableType: 'page_version',
            auditableUid: $version->uid,
            action: DomainEventType::PageVersionCreated,
            summary: 'Page version created.',
            metadata: [
                'page_uid' => $page->uid,
                'version_number' => $version->version_number,
                'byte_size' => $version->byte_size,
                'scan_status' => $version->scan_status->value,
                'source' => $version->source->value,
                'provenance_supplied_at_ingest' => $provenanceSuppliedAtIngest,
            ] + $pdfMetadata + $mcpMetadata,
        );
    }

    /**
     * @return array{
     *     pdf_extraction_state: string
     * }|array{}
     */
    private function persistPdfFacts(
        PageVersion $version,
        ?PdfProcessingResult $result,
        ?PersistedPdfExtraction $extraction,
    ): array {
        if (!($result instanceof PdfProcessingResult) || !($extraction instanceof PersistedPdfExtraction)) {
            return [];
        }

        PdfVersionFact::query()->forceCreate([
            'page_version_uid' => $version->uid,
            'page_count' => $result->pageCount,
            'pdf_version' => $result->pdfVersion,
            'extraction_state' => $extraction->state,
            'processor_profile' => $result->processorProfile,
        ]);

        return [
            'pdf_extraction_state' => $extraction->state->value,
        ];
    }

    private function persistDerivative(
        PageVersion $version,
        PreparedPageContent $prepared,
        ?string $storagePath,
    ): ?PageVersionDerivative {
        if (!($prepared->derivative instanceof PreparedArtifactDerivative) || !is_string($storagePath)) {
            return null;
        }

        return PageVersionDerivative::query()->forceCreate([
            'uid' => (string) Str::ulid(),
            'page_version_uid' => $version->uid,
            'kind' => $prepared->derivative->kind,
            'storage_path' => $storagePath,
            'content_hash' => $prepared->derivative->contentHash(),
            'byte_size' => $prepared->derivative->byteSize(),
        ]);
    }

    /**
     * @return array{xlsx_truncated: bool}|array{}
     */
    private function persistXlsxFacts(
        PageVersion $version,
        ?XlsxProcessingResult $result,
        ?PageVersionDerivative $derivative,
    ): array {
        if (!($result instanceof XlsxProcessingResult)) {
            return [];
        }

        if (!($derivative instanceof PageVersionDerivative)) {
            throw new LogicException('XLSX facts require a persisted manifest derivative.');
        }

        XlsxVersionFact::query()->forceCreate([
            'page_version_uid' => $version->uid,
            'manifest_derivative_uid' => $derivative->uid,
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
        ]);

        return ['xlsx_truncated' => $result->truncated];
    }

    /** @return array{docx_extraction_state: string}|array{} */
    private function persistDocxFacts(
        PageVersion $version,
        ?DocxProcessingResult $result,
        ?PersistedPdfExtraction $extraction,
        ?PageVersionDerivative $derivative,
    ): array {
        if (!($result instanceof DocxProcessingResult)) {
            return [];
        }
        if (!($derivative instanceof PageVersionDerivative) || !($extraction instanceof PersistedPdfExtraction)) {
            throw new LogicException('DOCX facts require a persisted PDF derivative and extraction.');
        }

        $conversion = $result->conversion;
        DocxVersionFact::query()->forceCreate([
            'page_version_uid' => $version->uid,
            'preview_derivative_uid' => $derivative->uid,
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
        ]);

        return ['docx_extraction_state' => $extraction->state->value];
    }
}
