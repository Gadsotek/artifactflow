<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\ArtifactContentReader;
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
    ) {
    }

    public function handle(User $actor, McpToolArguments $arguments): McpToolResult
    {
        $page = $this->pages->viewablePage($actor, $arguments->requiredString('page_uid'));

        if (!$page instanceof Page) {
            return McpToolResult::notFound();
        }

        $version = $page->currentVersion;
        $content = $version instanceof PageVersion ? $this->contentReader->read($version->content_storage_path) : null;

        if ($content === null) {
            return McpToolResult::error([
                'type' => 'content_unavailable',
                'message' => 'Page content is unavailable.',
            ]);
        }

        $hierarchy = $this->hierarchy->forPages($actor, [$page]);

        return McpToolResult::success($this->payload->forPage($page) + [
            'current_version_uid' => $version?->uid,
            'hierarchy' => $hierarchy[$page->uid],
            'content' => McpDataEnvelope::text($content, $this->payload->mediaType($page)),
            'extracted_text' => McpDataEnvelope::text($version?->extracted_text),
        ]);
    }
}
