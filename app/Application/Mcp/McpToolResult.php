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
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function success(array $payload): self
    {
        return new self($payload, false);
    }

    /**
     * @param array<string, mixed> $error
     */
    public static function error(array $error): self
    {
        return new self(['error' => $error], true);
    }

    public static function notFound(): self
    {
        return self::error([
            'type' => 'not_found',
            'message' => 'Page not found.',
        ]);
    }
}
