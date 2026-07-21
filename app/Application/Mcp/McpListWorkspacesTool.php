<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Models\McpAccessToken;
use App\Models\User;

/**
 * MCP list_workspaces tool: the workspaces the actor belongs to, narrowed to
 * the token's workspace scope.
 */
final readonly class McpListWorkspacesTool
{
    public function __construct(
        private McpWorkspaceListing $workspaceListing,
    ) {
    }

    public function handle(User $actor, McpAccessToken $token): McpToolResult
    {
        return McpToolResult::success([
            'workspaces' => $this->workspaceListing->forActor($actor, $token),
        ]);
    }
}
