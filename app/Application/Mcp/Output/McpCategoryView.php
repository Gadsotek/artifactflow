<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpCategoryView implements McpWirePayload
{
    public function __construct(
        public string $uid,
        public McpUntrustedText $name,
        public McpUntrustedText $slug,
        public string $workspaceUid,
        public ?McpUntrustedText $workspaceName = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $payload = [
            'uid' => $this->uid,
            'name' => $this->name->toWire(),
            'slug' => $this->slug->toWire(),
            'workspace_uid' => $this->workspaceUid,
        ];

        if ($this->workspaceName !== null) {
            $payload['workspace_name'] = $this->workspaceName->toWire();
        }

        return $payload;
    }
}
