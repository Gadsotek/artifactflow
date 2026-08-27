<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpToolError;
use App\Application\Mcp\McpWirePayload;

final readonly class McpErrorPayload implements McpWirePayload
{
    public function __construct(public McpToolError $error)
    {
    }

    /**
     * @return array{error: array<string, mixed>}
     */
    public function toWire(): array
    {
        return ['error' => $this->error->toWire()];
    }
}
