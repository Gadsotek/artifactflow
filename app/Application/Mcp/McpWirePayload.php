<?php

declare(strict_types=1);

namespace App\Application\Mcp;

interface McpWirePayload
{
    /**
     * @return array<string, mixed>
     */
    public function toWire(): array;
}
