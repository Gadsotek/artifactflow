<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Application\PageCatalog\ArtifactContentReader;
use App\Application\PageCatalog\DocxPreviewContentReader;
use App\Application\PageCatalog\DocxProcessorConfiguration;
use App\Application\PageCatalog\MarkdownPageRenderer;
use App\Application\PageCatalog\PdfProcessorConfiguration;
use App\Application\PageCatalog\XlsxManifestContentReader;
use App\Application\PageCatalog\XlsxProcessorConfiguration;
use App\Domain\ExternalSharing\ExternalPagePresentation;
use App\Domain\PageCatalog\PageType;
use App\Models\PageVersion;

final readonly class ExternalShareViewerContent
{
    public function __construct(
        private ExternalPagePresentationRegistry $presentations,
        private ArtifactContentReader $contentReader,
        private MarkdownPageRenderer $markdown,
        private ExternalArtifactPreviewUrl $artifactUrls,
        private PdfProcessorConfiguration $pdfConfiguration,
        private XlsxProcessorConfiguration $xlsxConfiguration,
        private XlsxManifestContentReader $xlsxManifestReader,
        private DocxProcessorConfiguration $docxConfiguration,
        private DocxPreviewContentReader $docxPreviewReader,
    ) {
    }

    public function forContext(ExternalShareViewContext $context): ?ExternalShareViewerPage
    {
        if ($context->page->type === PageType::Pdf && !$this->pdfConfiguration->enabled()) {
            return null;
        }

        if ($context->page->type === PageType::Xlsx && !$this->xlsxConfiguration->enabled()) {
            return null;
        }

        if ($context->page->type === PageType::Docx
            && (!$this->docxConfiguration->enabled() || !$this->pdfConfiguration->enabled())) {
            return null;
        }

        $versionUid = $context->page->current_version_uid;

        if ($versionUid === null) {
            return null;
        }

        $version = PageVersion::query()
            ->whereKey($versionUid)
            ->where('page_uid', $context->page->uid)
            ->first();

        if (!$version instanceof PageVersion) {
            return null;
        }

        $presentation = $this->presentations->forPageType($context->page->type);

        if ($presentation === ExternalPagePresentation::Markdown) {
            $source = $this->contentReader->read($version->content_storage_path);

            if ($source === null) {
                return null;
            }

            return new ExternalShareViewerPage(
                context: $context,
                version: $version,
                presentation: $presentation,
                renderedMarkdown: $this->markdown->render($source),
                artifactPreviewUrl: null,
            );
        }

        if (
            !$this->contentReader->isAvailable($version->content_storage_path)
            || ($context->page->type === PageType::Xlsx && $this->xlsxManifestReader->read($version) === null)
            || ($context->page->type === PageType::Docx && $this->docxPreviewReader->read($version) === null)
        ) {
            return null;
        }

        return new ExternalShareViewerPage(
            context: $context,
            version: $version,
            presentation: $presentation,
            renderedMarkdown: null,
            artifactPreviewUrl: $this->artifactUrls->temporaryUrl($context, $version),
        );
    }
}
