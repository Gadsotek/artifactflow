<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpListWorkspacesTool as Handler;
use App\Application\Mcp\McpToolArguments;
use App\Application\Mcp\McpToolResult;
use App\Models\McpAccessToken;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_workspaces')]
#[Description('List workspaces reachable by this token. Workspace-scoped tokens only see their scoped workspaces.')]
#[IsReadOnly]
final class ListWorkspacesTool extends ArtifactFlowTool
{
    public function __construct(
        \App\Application\Mcp\McpRequestContext $mcpContext,
        \App\Application\Mcp\McpToolGuard $guard,
        \Illuminate\Http\Request $httpRequest,
        private readonly Handler $handler,
    ) {
        parent::__construct($mcpContext, $guard, $httpRequest);
    }

    public function handle(Request $request): Response
    {
        return $this->invoke(
            $request,
            McpAccessTokenIssuer::SCOPE_SEARCH,
            false,
            fn (User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult => $this->handler->handle($actor, $token),
        );
    }
}
