<?php

declare(strict_types=1);

namespace App\Application\Mcp;

final readonly class McpImageContent
{
    /**
     * Base64 expands bytes by roughly one third before JSON/MCP framing. Keep a
     * single read response bounded even when the installation accepts larger
     * retained derivatives for browser preview.
     */
    public const int MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        public string $bytes,
        public string $mediaType,
    ) {
    }
}
