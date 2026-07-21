<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpToolArguments;
use App\Application\Mcp\McpToolResult;
use App\Application\Mcp\McpUpdateTool as Handler;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update')]
#[Description('Append a page version using optimistic concurrency and the normal ArtifactFlow update handler.')]
final class UpdateTool extends ArtifactFlowTool
{
    public function __construct(
        \App\Application\Mcp\McpRequestContext $mcpContext,
        \App\Application\Mcp\McpToolGuard $guard,
        \Illuminate\Http\Request $httpRequest,
        private readonly Handler $handler,
    ) {
        parent::__construct($mcpContext, $guard, $httpRequest);
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page_uid' => $schema->string()->required(),
            'content' => $schema->string()->required(),
            'base_version_uid' => $schema->string()
                ->description('Must match the current version UID.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->invoke(
            $request,
            McpAccessTokenIssuer::SCOPE_UPDATE,
            true,
            fn (User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult => $this->handler->handle($actor, $arguments),
        );
    }
}
