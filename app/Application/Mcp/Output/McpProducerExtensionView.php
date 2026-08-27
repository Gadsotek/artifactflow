<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpProducerExtensionView implements McpWirePayload
{
    public function __construct(
        public McpUntrustedText $key,
        public McpUntrustedText $value,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'key' => $this->key->toWire(),
            'value' => $this->value->toWire(),
        ];
    }
}
