<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpWorkspaceView implements McpWirePayload
{
    public function __construct(
        public string $uid,
        public McpUntrustedText $name,
    ) {
    }

    /**
     * @return array{uid: string, name: array<string, mixed>}
     */
    public function toWire(): array
    {
        return [
            'uid' => $this->uid,
            'name' => $this->name->toWire(),
        ];
    }
}
