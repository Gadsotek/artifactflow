<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Output\McpErrorPayload;

/**
 * Transport-neutral result from an ArtifactFlow MCP use case. The Laravel MCP
 * adapter decides how to encode this payload on the wire.
 */
final readonly class McpToolResult
{
    private function __construct(
        public McpWirePayload $payload,
        public bool $isError,
        public ?McpImageContent $image = null,
    ) {
    }

    public static function success(McpWirePayload $payload, ?McpImageContent $image = null): self
    {
        return new self($payload, false, $image);
    }

    public static function error(McpToolError $error): self
    {
        return new self(new McpErrorPayload($error), true);
    }

    public static function notFound(McpNotFoundResource $resource = McpNotFoundResource::Page): self
    {
        return self::error(McpToolError::notFound($resource));
    }
}
