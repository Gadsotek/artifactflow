<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Mcp\Input\McpRevertInput;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpRevertTool as Handler;
use App\Application\Mcp\McpToolArguments;
use App\Application\Mcp\McpToolResult;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('revert')]
#[Description('Restore the previous page version when base_version_uid matches the current version.')]
final class RevertTool extends ArtifactFlowTool
{
    public function __construct(
        \App\Application\Mcp\McpRequestContext $mcpContext,
        \App\Application\Mcp\McpToolGuard $guard,
        \Illuminate\Http\Request $httpRequest,
        \App\Application\Mcp\McpPayloadEncoder $payloadEncoder,
        private readonly Handler $handler,
    ) {
        parent::__construct($mcpContext, $guard, $httpRequest, $payloadEncoder);
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page_uid' => $schema->string()->required(),
            'base_version_uid' => $schema->string()
                ->description('Must match the current version UID.')
                ->required(),
            'change_summary' => $schema->string()
                ->description('Required concise reason for reverting this version, up to 255 characters.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|\Laravel\Mcp\ResponseFactory
    {
        return $this->invoke(
            $request,
            McpAccessTokenIssuer::SCOPE_UPDATE,
            true,
            fn (User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult => $this->handler->handle(
                $actor,
                McpRevertInput::fromArguments($arguments),
            ),
        );
    }
}
