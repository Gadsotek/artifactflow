<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpCategoryCreatedPayload implements McpWirePayload
{
    public function __construct(public McpCategoryView $category)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'uid' => $this->category->uid,
            'workspace_uid' => $this->category->workspaceUid,
            'name' => $this->category->name->toWire(),
            'slug' => $this->category->slug->toWire(),
        ];
    }
}
