<?php

declare(strict_types=1);

namespace App\Application\Mcp;

/**
 * Transport-neutral result from an ArtifactFlow MCP use case. The Laravel MCP
 * adapter decides how to encode this payload on the wire.
 */
final readonly class McpToolResult
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public array $payload,
        public bool $isError,
        public ?McpImageContent $image = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function success(array $payload, ?McpImageContent $image = null): self
    {
        return new self($payload, false, $image);
    }

    /**
     * @param array<string, mixed> $error
     */
    public static function error(array $error): self
    {
        return new self(['error' => $error], true);
    }

    public static function notFound(McpNotFoundResource $resource = McpNotFoundResource::Page): self
    {
        return self::error([
            'type' => 'not_found',
            'message' => sprintf('%s not found.', $resource->value),
        ]);
    }
}
