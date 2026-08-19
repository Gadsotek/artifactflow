<?php

declare(strict_types=1);

namespace App\Application\Mcp;

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
    /**
     * @return array<string, mixed>
     */
    public function forPage(Page $page): array
    {
        $page->loadMissing('tags');

        return [
            'uid' => $page->uid,
            'title' => McpDataEnvelope::text($page->title),
            'description' => McpDataEnvelope::text($page->description),
            'type' => $page->type->value,
            'status' => $page->status->value,
            'metadata_revision' => $page->metadata_revision,
            'tags' => array_map(
                static fn (Tag $tag): array => McpDataEnvelope::text($tag->name),
                $page->tags->sortBy('name')->values()->all(),
            ),
            'updated_at' => $page->updated_at?->toISOString(),
        ];
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
