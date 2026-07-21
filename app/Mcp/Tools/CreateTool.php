<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpCreateTool as Handler;
use App\Application\Mcp\McpToolArguments;
use App\Application\Mcp\McpToolResult;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create')]
#[Description('Create a page through ArtifactFlow policies, scanners, versioning, and audit behavior.')]
final class CreateTool extends ArtifactFlowTool
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
            'workspace_uid' => $schema->string()->required(),
            'type' => $schema->string()->enum(PageType::class)->required(),
            'title' => $schema->string()->required(),
            'content' => $schema->string()->required(),
            'description' => $schema->string(),
            'status' => $schema->string()->enum(PageStatus::class)->default(PageStatus::Draft->value),
            'category_uid' => $schema->string()->description('Existing category in the target workspace.'),
            'category_name' => $schema->string()->description('Create a target-workspace category atomically.'),
            'tags' => $schema->array()->items($schema->string()),
            'source_filename' => $schema->string(),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->invoke(
            $request,
            McpAccessTokenIssuer::SCOPE_CREATE,
            true,
            fn (User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult => $this->handler->handle($actor, $arguments),
        );
    }
}
