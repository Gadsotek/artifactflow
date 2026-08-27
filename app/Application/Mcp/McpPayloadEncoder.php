<?php

declare(strict_types=1);

namespace App\Application\Mcp;

final readonly class McpPayloadEncoder
{
    /**
     * @return array<string, mixed>
     */
    public function encode(McpWirePayload $payload): array
    {
        return $payload->toWire();
    }
}
