<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Provenance\ProvenanceReadModel;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PdfVersionFact;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final readonly class PageVersionInspection
{
    public function __construct(
        private ArtifactContentReader $contentReader,
        private ArtifactPreviewUrl $artifactPreviewUrls,
        private PdfArtifactUrl $pdfArtifactUrls,
        private PdfProcessorConfiguration $pdfProcessorConfiguration,
        private XlsxProcessorConfiguration $xlsxProcessorConfiguration,
        private XlsxManifestContentReader $xlsxManifestReader,
        private DocxPreviewUrl $docxPreviewUrls,
        private DocxProcessorConfiguration $docxProcessorConfiguration,
        private DocxPreviewContentReader $docxPreviewReader,
        private MarkdownPageRenderer $markdownRenderer,
        private PageAccess $access,
        private PageVersionDiff $diff,
        private ProvenanceReadModel $provenance,
    ) {
    }

    public function forVersion(User $actor, Page $page, PageVersion $version): PageVersionInspectionData
    {
        $this->access->ensureCanView($actor, $page);

        if ($version->page_uid !== $page->uid || $page->current_version_uid === null) {
            abort(404);
        }

        $version->loadMissing('creator');

        $currentVersion = PageVersion::query()
            ->whereKey($page->current_version_uid)
            ->where('page_uid', $page->uid)
            ->first();

        if (!$currentVersion instanceof PageVersion) {
            abort(404);
        }

        $selectedSource = null;
        $currentSource = null;
        $binaryArtifact = in_array($page->type, [PageType::Image, PageType::Pdf, PageType::Xlsx, PageType::Docx], true);
        $selectedContentAvailable = $binaryArtifact
            ? $this->contentReader->isAvailable($version->content_storage_path)
            : false;

        if ($page->type === PageType::Xlsx && $selectedContentAvailable) {
            $selectedContentAvailable = $this->xlsxManifestReader->read($version) !== null;
        }
        if ($page->type === PageType::Docx && $selectedContentAvailable) {
            $selectedContentAvailable = $this->docxPreviewReader->read($version) !== null;
        }

        if (!$binaryArtifact) {
            $selectedSource = $this->contentReader->read($version->content_storage_path);
            $currentSource = $version->uid === $currentVersion->uid
                ? $selectedSource
                : $this->contentReader->read($currentVersion->content_storage_path);
            $selectedContentAvailable = $selectedSource !== null;
        }

        $renderedMarkdown = null;
        $artifactPreviewUrl = null;

        if ($selectedSource !== null && $page->type === PageType::Markdown) {
            $renderedMarkdown = $this->markdownRenderer->renderForPage($actor, $page, $selectedSource);
        }

        if (
            $selectedContentAvailable
            && $page->type->usesArtifactHostPreview()
            && ($page->type !== PageType::Pdf || $this->pdfProcessorConfiguration->enabled())
            && ($page->type !== PageType::Xlsx || $this->xlsxProcessorConfiguration->enabled())
            && ($page->type !== PageType::Docx
                || ($this->docxProcessorConfiguration->enabled() && $this->pdfProcessorConfiguration->enabled()))
        ) {
            $artifactPreviewUrl = match ($page->type) {
                PageType::Pdf => $this->pdfArtifactUrls->temporaryHistoryUrl($page, $version),
                PageType::Docx => $this->docxPreviewUrls->temporaryHistoryUrl($page, $version),
                default => $this->artifactPreviewUrls->temporaryHistoryUrl($page, $version),
            };
            Log::info('artifact_history_preview_url.issued', [
                'actor_user_uid' => $actor->uid,
                'page_uid' => $page->uid,
                'version_uid' => $version->uid,
            ]);
        }

        return new PageVersionInspectionData(
            version: $version,
            currentVersion: $currentVersion,
            olderVersion: $this->adjacentVersion($page, $version, newer: false),
            newerVersion: $this->adjacentVersion($page, $version, newer: true),
            renderedMarkdown: $renderedMarkdown,
            artifactPreviewUrl: $artifactPreviewUrl,
            pdfExtractionStatus: $this->pdfExtractionStatus($page, $version),
            contentUnavailable: !$selectedContentAvailable,
            comparisonUnavailable: $binaryArtifact
                || $selectedSource === null
                || $currentSource === null,
            diff: $binaryArtifact || $selectedSource === null || $currentSource === null
                ? new PageVersionDiffResult([], 0, 0, false)
                : $this->diff->compare($selectedSource, $currentSource),
            canRestore: $version->uid !== $currentVersion->uid
                && $page->status !== PageStatus::Archived
                && ($page->type !== PageType::Pdf || $this->pdfProcessorConfiguration->enabled())
                && ($page->type !== PageType::Xlsx || $this->xlsxProcessorConfiguration->enabled())
                && ($page->type !== PageType::Docx || ($this->docxProcessorConfiguration->enabled()
                    && $this->pdfProcessorConfiguration->enabled()))
                && $this->access->canEdit($actor, $page),
            provenance: $this->provenance->forVersion($version),
        );
    }

    private function pdfExtractionStatus(Page $page, PageVersion $version): ?PdfExtractionStatusView
    {
        if ($page->type !== PageType::Pdf) {
            return null;
        }

        $facts = $version->pdfFacts()->first();

        return $facts instanceof PdfVersionFact
            ? PdfExtractionStatusView::fromState($facts->extraction_state)
            : null;
    }

    private function adjacentVersion(Page $page, PageVersion $version, bool $newer): ?PageVersion
    {
        $query = PageVersion::query()->where('page_uid', $page->uid);

        if ($newer) {
            $query->where('version_number', '>', $version->version_number)->orderBy('version_number');
        } else {
            $query->where('version_number', '<', $version->version_number)->orderByDesc('version_number');
        }

        return $query->first();
    }
}
