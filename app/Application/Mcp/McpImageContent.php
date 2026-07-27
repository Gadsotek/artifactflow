<?php

declare(strict_types=1);

namespace App\Application\Mcp;

final readonly class McpImageContent
{
    public function __construct(
        public string $bytes,
        public string $mediaType,
    ) {
    }
}
