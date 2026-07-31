<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpCreateExternalShareTool as Handler;
use App\Application\Mcp\McpToolArguments;
use App\Application\Mcp\McpToolResult;
use App\Domain\ExternalSharing\ExternalShareMode;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_external_share')]
#[Description('Create a one-time or expiring bearer-capability URL for an MCP-owned page. The secret URL is returned exactly once.')]
final class CreateExternalShareTool extends ArtifactFlowTool
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
            'page_uid' => $schema->string()
                ->description('Page owned by the MCP principal within this token\'s workspace scope.')
                ->required(),
            'mode' => $schema->string()
                ->enum(ExternalShareMode::class)
                ->description('Use one_time for one successful browser window or expires_at for reusable access until expiry.')
                ->required(),
            'expires_at' => $schema->string()
                ->description('Required only for expires_at mode. ISO 8601 timestamp with an explicit time-zone offset.'),
        ];
    }

    public function handle(Request $request): Response|\Laravel\Mcp\ResponseFactory
    {
        return $this->invoke(
            $request,
            McpAccessTokenIssuer::SCOPE_SHARE,
            true,
            fn (User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult => $this->handler->handle(
                $actor,
                $arguments,
            ),
        );
    }
}
