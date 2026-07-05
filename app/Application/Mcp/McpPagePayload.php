<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\Tag;

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
            'tags' => array_map(
                static fn (Tag $tag): array => McpDataEnvelope::text($tag->name),
                $page->tags->sortBy('name')->values()->all(),
            ),
            'updated_at' => $page->updated_at?->toISOString(),
        ];
    }

    public function mediaType(Page $page): string
    {
        return $page->type === PageType::HtmlArtifact ? 'text/html' : 'text/markdown';
    }
}
