<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * MCP list_workspaces tool: the workspaces the actor belongs to, narrowed to
 * the token's workspace scope.
 */
final readonly class McpListWorkspacesTool
{
    public function __construct(
        private McpWorkspaceListing $workspaceListing,
        private McpJsonRpc $jsonRpc,
    ) {
    }

    public function handle(mixed $id, User $actor, McpAccessToken $token): JsonResponse
    {
        return $this->jsonRpc->toolSuccess($id, [
            'workspaces' => $this->workspaceListing->forActor($actor, $token),
        ]);
    }
}
