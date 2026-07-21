<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpSearchTool as Handler;
use App\Application\Mcp\McpToolArguments;
use App\Application\Mcp\McpToolResult;
use App\Application\PageCatalog\PageSearchSort;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search')]
#[Description('Search ArtifactFlow pages reachable by this token, with visibility-filtered hierarchy metadata.')]
#[IsReadOnly]
final class SearchTool extends ArtifactFlowTool
{
    public function __construct(
        \App\Application\Mcp\McpRequestContext $mcpContext,
        \App\Application\Mcp\McpToolGuard $guard,
        \Illuminate\Http\Request $httpRequest,
        private readonly Handler $handler,
    ) {
        parent::__construct($mcpContext, $guard, $httpRequest);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Full-text query.'),
            'workspace_uid' => $schema->string()->description('Narrow to one reachable workspace.'),
            'type' => $schema->string()->enum(PageType::class),
            'status' => $schema->string()->enum(PageStatus::class),
            'category_uid' => $schema->string(),
            'tag_uids' => $schema->array()->items($schema->string()),
            'owner_user_uid' => $schema->string(),
            'include_archived' => $schema->boolean()->default(false),
            'include_snippet' => $schema->boolean()
                ->description('Include a content snippet; requires the mcp:read scope.')
                ->default(false),
            'sort' => $schema->string()->enum(PageSearchSort::class)->default(PageSearchSort::Relevance->value),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->invoke(
            $request,
            McpAccessTokenIssuer::SCOPE_SEARCH,
            false,
            fn (User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult => $this->handler->handle($actor, $token, $arguments),
        );
    }
}
