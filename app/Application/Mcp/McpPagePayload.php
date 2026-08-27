<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Output\McpPageView;
use App\Application\Mcp\Output\McpUntrustedText;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\Tag;
use LogicException;

/**
 * Serializes a page into the MCP tool payload shape. All user-authored values
 * are wrapped in the untrusted-data envelope so agents never receive raw
 * page-derived text as instructions.
 */
final readonly class McpPagePayload
{
    public function forPage(Page $page): McpPageView
    {
        $page->loadMissing('tags');

        return new McpPageView(
            uid: $page->uid,
            title: new McpUntrustedText($page->title),
            description: $page->description === null ? null : new McpUntrustedText($page->description),
            type: $page->type->value,
            status: $page->status->value,
            metadataRevision: $page->metadata_revision,
            tags: array_values(array_map(
                static fn (Tag $tag): McpUntrustedText => new McpUntrustedText($tag->name),
                $page->tags->sortBy('name')->values()->all(),
            )),
            updatedAt: $page->updated_at?->toISOString(),
        );
    }

    /**
     * The text media type for a page rendered as an MCP text block. Image pages
     * are returned as a binary image block with an inspector-derived concrete
     * media type instead, so they never reach this method.
     */
    public function mediaType(Page $page): string
    {
        return match ($page->type) {
            PageType::Markdown => 'text/markdown',
            PageType::HtmlArtifact => 'text/html',
            PageType::Image => throw new LogicException('Image pages are served as a binary image block, not a text media type.'),
            PageType::Pdf => throw new LogicException('PDF bytes are not returned through MCP.'),
        };
    }
}
