<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Application\Mcp\McpAccessTokenIssuer;
use PHPUnit\Framework\TestCase;

final class McpWriteScopeTest extends TestCase
{
    public function test_create_and_update_scopes_are_write_scopes(): void
    {
        $this->assertTrue(McpAccessTokenIssuer::includesWriteScope([McpAccessTokenIssuer::SCOPE_CREATE]));
        $this->assertTrue(McpAccessTokenIssuer::includesWriteScope([McpAccessTokenIssuer::SCOPE_UPDATE]));
        $this->assertTrue(McpAccessTokenIssuer::includesWriteScope([
            McpAccessTokenIssuer::SCOPE_READ,
            McpAccessTokenIssuer::SCOPE_UPDATE,
        ]));
    }

    public function test_read_and_search_scopes_are_not_write_scopes(): void
    {
        $this->assertFalse(McpAccessTokenIssuer::includesWriteScope([]));
        $this->assertFalse(McpAccessTokenIssuer::includesWriteScope([McpAccessTokenIssuer::SCOPE_READ]));
        $this->assertFalse(McpAccessTokenIssuer::includesWriteScope([
            McpAccessTokenIssuer::SCOPE_READ,
            McpAccessTokenIssuer::SCOPE_SEARCH,
        ]));
    }
}
