<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpReadInput;
use App\Application\Mcp\Output\McpImageSearchabilityView;
use App\Application\Mcp\Output\McpReadPayload;
use App\Application\Mcp\Output\McpUntrustedImage;
use App\Application\Mcp\Output\McpUntrustedText;
use App\Application\PageCatalog\ArtifactContentReader;
use App\Application\PageCatalog\ImageArtifactLimits;
use App\Application\PageCatalog\PdfProcessorConfiguration;
use App\Application\PageCatalog\RasterImageInspector;
use App\Application\Provenance\ProvenanceReadModel;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;

/**
 * MCP read tool: return one reachable page with its current content wrapped
 * in the untrusted-data envelope.
 */
final readonly class McpReadTool
{
    public function __construct(
        private McpPageResolver $pages,
        private ArtifactContentReader $contentReader,
        private McpPagePayload $payload,
        private McpPageHierarchy $hierarchy,
        private RasterImageInspector $images,
        private ImageArtifactLimits $limits,
        private ProvenanceReadModel $provenance,
        private McpProvenancePayload $provenancePayload,
        private McpPdfVersionPayload $pdfPayload,
        private PdfProcessorConfiguration $pdfConfiguration,
    ) {
    }

    public function handle(User $actor, McpReadInput $input): McpToolResult
    {
        $page = $this->pages->viewablePage($actor, $input->pageUid);

        if (!$page instanceof Page) {
            return McpToolResult::notFound();
        }

        if ($page->type === PageType::Pdf && !$this->pdfConfiguration->enabled()) {
            return McpToolResult::error(McpToolError::unsupportedContentType(
                'PDF content is not available through MCP yet.',
            ));
        }

        $version = $page->currentVersion;

        if (!$version instanceof PageVersion) {
            return McpToolResult::error(McpToolError::contentUnavailable(
                'Page content is unavailable.',
            ));
        }

        if ($page->type === PageType::Pdf) {
            $pdf = $this->pdfPayload->forVersion($version);

            if ($pdf === null) {
                return McpToolResult::error(McpToolError::contentUnavailable(
                    'Page content is unavailable.',
                ));
            }

            $hierarchy = $this->hierarchy->forPages($actor, [$page]);

            return McpToolResult::success(new McpReadPayload(
                page: $this->payload->forPage($page),
                currentVersionUid: $version->uid,
                currentVersionChangeSummary: $this->changeSummary($version),
                hierarchy: $hierarchy[$page->uid],
                provenance: $input->includes(McpReadSection::Provenance)
                    ? $this->provenancePayload->make($this->provenance->forVersion($version))
                    : null,
                content: $input->includes(McpReadSection::Content)
                    ? McpUntrustedText::fromNullable($version->extracted_text)
                    : null,
                pdf: $pdf,
            ));
        }

        // Image reads share the installation's configured artifact byte limit
        // with every other read path, so any derivative small enough to be
        // stored is small enough to return. Base64 framing expands this by
        // roughly one third; the operator bounds it through the same setting.
        $imageReadLimit = $this->limits->maxStoredBytes();

        if (
            $input->includes(McpReadSection::Content)
            && $page->type === PageType::Image
            && $version->byte_size > $imageReadLimit
        ) {
            return McpToolResult::error(McpToolError::contentTooLarge(
                'Image content exceeds the MCP response size limit.',
            ));
        }

        $content = $input->includes(McpReadSection::Content)
            ? $this->contentReader->read(
                $version->content_storage_path,
                $page->type === PageType::Image ? $imageReadLimit : null,
            )
            : null;

        if ($input->includes(McpReadSection::Content) && $content === null) {
            return McpToolResult::error(McpToolError::contentUnavailable(
                'Page content is unavailable.',
            ));
        }

        $hierarchy = $this->hierarchy->forPages($actor, [$page]);
        $provenance = $input->includes(McpReadSection::Provenance)
            ? $this->provenancePayload->make($this->provenance->forVersion($version))
            : null;

        if (!$input->includes(McpReadSection::Content)) {
            return McpToolResult::success(new McpReadPayload(
                page: $this->payload->forPage($page),
                currentVersionUid: $version->uid,
                currentVersionChangeSummary: $this->changeSummary($version),
                hierarchy: $hierarchy[$page->uid],
                provenance: $provenance,
                imageSearchability: $page->type === PageType::Image
                    ? $this->imageSearchability($page)
                    : null,
            ));
        }

        if (!is_string($content)) {
            return McpToolResult::error(McpToolError::contentUnavailable(
                'Page content is unavailable.',
            ));
        }

        if ($page->type === PageType::Image) {
            try {
                $image = $this->images->inspectStored($content);
            } catch (DomainRuleViolation) {
                return McpToolResult::error(McpToolError::contentUnavailable(
                    'Page content is unavailable.',
                ));
            }

            return McpToolResult::success(new McpReadPayload(
                page: $this->payload->forPage($page),
                currentVersionUid: $version->uid,
                currentVersionChangeSummary: $this->changeSummary($version),
                hierarchy: $hierarchy[$page->uid],
                provenance: $provenance,
                content: new McpUntrustedImage($image->mediaType),
                extractedText: McpUntrustedText::fromNullable(null),
                imageSearchability: $this->imageSearchability($page),
            ), new McpImageContent($content, $image->mediaType));
        }

        return McpToolResult::success(new McpReadPayload(
            page: $this->payload->forPage($page),
            currentVersionUid: $version->uid,
            currentVersionChangeSummary: $this->changeSummary($version),
            hierarchy: $hierarchy[$page->uid],
            provenance: $provenance,
            content: new McpUntrustedText($content, $this->payload->mediaType($page)),
            extractedText: McpUntrustedText::fromNullable($version->extracted_text),
        ));
    }

    private function imageSearchability(Page $page): McpImageSearchabilityView
    {
        return new McpImageSearchabilityView(
            $page->description === null || trim($page->description) === '',
        );
    }

    private function changeSummary(PageVersion $version): ?McpUntrustedText
    {
        return $version->change_summary === null
            ? null
            : new McpUntrustedText($version->change_summary);
    }
}
