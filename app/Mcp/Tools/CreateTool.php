<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Mcp\Input\McpCreatePageInput;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpCreateTool as Handler;
use App\Application\Mcp\McpProvenanceArguments;
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
        \App\Application\Mcp\McpPayloadEncoder $payloadEncoder,
        private readonly Handler $handler,
        private readonly McpProvenanceSchema $provenanceSchema,
        private readonly McpProvenanceArguments $provenanceArguments,
    ) {
        parent::__construct($mcpContext, $guard, $httpRequest, $payloadEncoder);
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_uid' => $schema->string()->required(),
            'type' => $schema->string()->enum([
                PageType::Markdown->value,
                PageType::HtmlArtifact->value,
            ])->required(),
            'title' => $schema->string()->required(),
            'content' => $schema->string()
                ->description('Page source. For html_artifact, provide one self-contained HTML document with all dependencies inline; CDNs, external URLs, fetch, and other network access will not work.')
                ->required(),
            'change_summary' => $schema->string()
                ->description('Required concise summary of what this initial version contains, up to 255 characters.')
                ->required(),
            'description' => $schema->string(),
            'status' => $schema->string()->enum(PageStatus::class)->default(PageStatus::Draft->value),
            'category_uid' => $schema->string()->description('Existing category in the target workspace.'),
            'category_name' => $schema->string()->description('Create a target-workspace category atomically; also requires mcp:organize.'),
            'parent_page_uid' => $schema->string()->description('Existing visible parent page in the target workspace.'),
            'tags' => $schema->array()
                ->description('Tag names may create global taxonomy and therefore also require mcp:organize.')
                ->items($schema->string()),
            'source_filename' => $schema->string(),
            'provenance' => $this->provenanceSchema->make($schema),
        ];
    }

    public function handle(Request $request): Response|\Laravel\Mcp\ResponseFactory
    {
        return $this->invoke(
            $request,
            McpAccessTokenIssuer::SCOPE_CREATE,
            true,
            fn (User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult => $this->handler->handle(
                $actor,
                $token,
                McpCreatePageInput::fromArguments($arguments, $this->provenanceArguments),
            ),
        );
    }
}
