<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpTagView implements McpWirePayload
{
    public function __construct(
        public string $uid,
        public McpUntrustedText $name,
        public McpUntrustedText $slug,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'uid' => $this->uid,
            'name' => $this->name->toWire(),
            'slug' => $this->slug->toWire(),
        ];
    }
}
