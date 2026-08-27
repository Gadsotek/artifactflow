<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpTaxonomyPayload implements McpWirePayload
{
    /**
     * @param list<McpCategoryView> $categories
     * @param list<McpTagView> $tags
     */
    public function __construct(
        public array $categories,
        public array $tags,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'categories' => array_map(
                static fn (McpCategoryView $category): array => $category->toWire(),
                $this->categories,
            ),
            'tags' => array_map(
                static fn (McpTagView $tag): array => $tag->toWire(),
                $this->tags,
            ),
        ];
    }
}
