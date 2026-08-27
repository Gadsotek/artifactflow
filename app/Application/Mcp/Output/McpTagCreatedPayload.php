<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpTagCreatedPayload implements McpWirePayload
{
    public function __construct(
        public McpTagView $tag,
        public string $authorityWorkspaceUid,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'uid' => $this->tag->uid,
            'authority_workspace_uid' => $this->authorityWorkspaceUid,
            'name' => $this->tag->name->toWire(),
            'slug' => $this->tag->slug->toWire(),
        ];
    }
}
