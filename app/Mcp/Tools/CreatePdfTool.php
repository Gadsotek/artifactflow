<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpCreatePdfTool as Handler;
use App\Application\Mcp\McpToolArguments;
use App\Application\Mcp\McpToolResult;
use App\Domain\PageCatalog\PageStatus;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_pdf')]
#[Description('Create a private native-text PDF artifact after isolated validation, extraction, and scanning.')]
final class CreatePdfTool extends ArtifactFlowTool
{
    public function __construct(
        \App\Application\Mcp\McpRequestContext $mcpContext,
        \App\Application\Mcp\McpToolGuard $guard,
        \Illuminate\Http\Request $httpRequest,
        private readonly Handler $handler,
        private readonly McpProvenanceSchema $provenanceSchema,
    ) {
        parent::__construct($mcpContext, $guard, $httpRequest);
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_uid' => $schema->string()->required(),
            'title' => $schema->string()->required(),
            'pdf_base64' => $schema->string()
                ->description('Canonical standard Base64 PDF bytes; data URLs and remote URLs are not accepted.')
                ->required(),
            'change_summary' => $schema->string()
                ->description('Required concise summary of what this PDF version contains, up to 255 characters.')
                ->required(),
            'description' => $schema->string(),
            'status' => $schema->string()->enum(PageStatus::class)->default(PageStatus::Draft->value),
            'category_uid' => $schema->string()->description('Existing category in the target workspace.'),
            'category_name' => $schema->string()->description('Create a target-workspace category atomically; also requires mcp:organize.'),
            'parent_page_uid' => $schema->string()->description('Existing visible parent page in the target workspace.'),
            'tags' => $schema->array()
                ->description('Tag names may create global taxonomy and therefore also require mcp:organize.')
                ->items($schema->string()),
            'provenance' => $this->provenanceSchema->make($schema),
        ];
    }

    public function handle(Request $request): Response|\Laravel\Mcp\ResponseFactory
    {
        return $this->invoke(
            $request,
            [McpAccessTokenIssuer::SCOPE_CREATE, McpAccessTokenIssuer::SCOPE_UPLOAD],
            true,
            fn (User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult => $this->handler->handle($actor, $token, $arguments),
        );
    }
}
