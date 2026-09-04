<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Mcp\Input\McpCreateDocxInput;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpCreateDocxTool as Handler;
use App\Application\Mcp\McpProvenanceArguments;
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

#[Name('create_docx')]
#[Description('Create a private Word document artifact with an isolated searchable PDF preview.')]
final class CreateDocxTool extends ArtifactFlowTool
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
            'title' => $schema->string()->required(),
            'docx_base64' => $schema->string()->description('Canonical standard Base64 DOCX bytes; URLs and data URLs are not accepted.')->required(),
            'change_summary' => $schema->string()->description('Required concise version summary, up to 255 characters.')->required(),
            'description' => $schema->string(),
            'status' => $schema->string()->enum(PageStatus::class)->default(PageStatus::Draft->value),
            'category_uid' => $schema->string(),
            'category_name' => $schema->string()->description('Also requires mcp:organize.'),
            'parent_page_uid' => $schema->string(),
            'tags' => $schema->array()->description('Also requires mcp:organize.')->items($schema->string()),
            'provenance' => $this->provenanceSchema->make($schema),
        ];
    }

    public function handle(Request $request): Response|\Laravel\Mcp\ResponseFactory
    {
        return $this->invoke(
            $request,
            [McpAccessTokenIssuer::SCOPE_CREATE, McpAccessTokenIssuer::SCOPE_UPLOAD],
            true,
            fn (User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult => $this->handler->handle(
                $actor,
                $token,
                McpCreateDocxInput::fromArguments($arguments, $this->provenanceArguments),
            ),
        );
    }
}
