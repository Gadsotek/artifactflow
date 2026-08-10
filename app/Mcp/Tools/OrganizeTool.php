<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpOrganizeTool as Handler;
use App\Application\Mcp\McpToolArguments;
use App\Application\Mcp\McpToolResult;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('organize')]
#[Description('Rename, categorize, tag, or reparent an editable page with metadata concurrency protection.')]
final class OrganizeTool extends ArtifactFlowTool
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
            'expected_metadata_revision' => $schema->integer()
                ->description('Must match metadata_revision returned by read or search.')
                ->required(),
            'title' => $schema->string(),
            'parent_page_uid' => $schema->string()
                ->description('Set a visible parent in the same workspace, or null to detach.')
                ->nullable(),
            'category_uid' => $schema->string()
                ->description('Set a category in the page workspace, or null to clear it.')
                ->nullable(),
            'tags' => $schema->array()
                ->description('Replace the complete tag set; an empty list clears it.')
                ->items($schema->string()),
        ];
    }

    public function handle(Request $request): Response|\Laravel\Mcp\ResponseFactory
    {
        return $this->invoke(
            $request,
            McpAccessTokenIssuer::SCOPE_ORGANIZE,
            true,
            fn (User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult => $this->handler->handle($actor, $arguments),
        );
    }
}
