<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Models\McpAccessToken;

final readonly class McpIssuedAccessToken
{
    public function __construct(
        public McpAccessToken $accessToken,
        public string $plainTextToken,
    ) {
    }
}
