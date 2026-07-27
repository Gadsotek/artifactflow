<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\ArtifactContentReader;
use App\Application\PageCatalog\RasterImageInspector;
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
    ) {
    }

    public function handle(User $actor, McpToolArguments $arguments): McpToolResult
    {
        $page = $this->pages->viewablePage($actor, $arguments->requiredString('page_uid'));

        if (!$page instanceof Page) {
            return McpToolResult::notFound();
        }

        $version = $page->currentVersion;

        if (
            $page->type === PageType::Image
            && $version instanceof PageVersion
            && $version->byte_size > McpImageContent::MAX_BYTES
        ) {
            return McpToolResult::error([
                'type' => 'content_too_large',
                'message' => 'Image content exceeds the MCP response size limit.',
            ]);
        }

        $content = $version instanceof PageVersion
            ? $this->contentReader->read(
                $version->content_storage_path,
                $page->type === PageType::Image ? McpImageContent::MAX_BYTES : null,
            )
            : null;

        if ($content === null) {
            return McpToolResult::error([
                'type' => 'content_unavailable',
                'message' => 'Page content is unavailable.',
            ]);
        }

        $hierarchy = $this->hierarchy->forPages($actor, [$page]);
        $payload = $this->payload->forPage($page) + [
            'current_version_uid' => $version?->uid,
            'hierarchy' => $hierarchy[$page->uid],
        ];

        if ($page->type === PageType::Image) {
            try {
                $image = $this->images->inspectStored($content);
            } catch (DomainRuleViolation) {
                return McpToolResult::error([
                    'type' => 'content_unavailable',
                    'message' => 'Page content is unavailable.',
                ]);
            }

            return McpToolResult::success($payload + [
                'content' => McpDataEnvelope::image($image->mediaType),
                'extracted_text' => McpDataEnvelope::text(null),
                'image_searchability' => $this->imageSearchability($page),
            ], new McpImageContent($content, $image->mediaType));
        }

        return McpToolResult::success($payload + [
            'content' => McpDataEnvelope::text($content, $this->payload->mediaType($page)),
            'extracted_text' => McpDataEnvelope::text($version?->extracted_text),
        ]);
    }

    /**
     * @return array{
     *     ocr_indexed: false,
     *     description_indexed: true,
     *     description_status: 'missing'|'present',
     *     recommended_tool: 'update_description'|null
     * }
     */
    private function imageSearchability(Page $page): array
    {
        $descriptionMissing = $page->description === null || trim($page->description) === '';

        return [
            'ocr_indexed' => false,
            'description_indexed' => true,
            'description_status' => $descriptionMissing ? 'missing' : 'present',
            'recommended_tool' => $descriptionMissing ? 'update_description' : null,
        ];
    }
}
