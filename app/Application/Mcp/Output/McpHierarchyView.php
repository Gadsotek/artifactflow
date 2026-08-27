<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpHierarchyView implements McpWirePayload
{
    /**
     * @param list<McpPageReferenceView> $ancestors
     */
    public function __construct(
        public ?McpPageReferenceView $parent,
        public array $ancestors,
        public int $visibleDepth,
        public int $visibleChildCount,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'parent' => $this->parent?->toWire(),
            'ancestors' => array_map(
                static fn (McpPageReferenceView $ancestor): array => $ancestor->toWire(),
                $this->ancestors,
            ),
            'visible_depth' => $this->visibleDepth,
            'visible_child_count' => $this->visibleChildCount,
        ];
    }
}
