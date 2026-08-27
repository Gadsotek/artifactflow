<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpPageReferenceView implements McpWirePayload
{
    public function __construct(
        public string $uid,
        public McpUntrustedText $title,
    ) {
    }

    /**
     * @return array{uid: string, title: array<string, mixed>}
     */
    public function toWire(): array
    {
        return [
            'uid' => $this->uid,
            'title' => $this->title->toWire(),
        ];
    }
}
