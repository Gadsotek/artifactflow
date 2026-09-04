<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Mcp\Input\McpReplaceXlsxInput;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpProvenanceArguments;
use App\Application\Mcp\McpReplaceXlsxTool as Handler;
use App\Application\Mcp\McpToolArguments;
use App\Application\Mcp\McpToolResult;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('replace_xlsx')]
#[Description('Append an Excel workbook original to an editable workbook page with version concurrency and isolated processing.')]
final class ReplaceXlsxTool extends ArtifactFlowTool
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
            'page_uid' => $schema->string()->required(),
            'base_version_uid' => $schema->string()
                ->description('Must match the current version UID.')
                ->required(),
            'xlsx_base64' => $schema->string()
                ->description('Canonical standard Base64 XLSX bytes; data URLs and remote URLs are not accepted.')
                ->required(),
            'change_summary' => $schema->string()
                ->description('Required concise summary of what changed in this workbook version, up to 255 characters.')
                ->required(),
            'provenance' => $this->provenanceSchema->make($schema),
        ];
    }

    public function handle(Request $request): Response|\Laravel\Mcp\ResponseFactory
    {
        return $this->invoke(
            $request,
            [McpAccessTokenIssuer::SCOPE_UPDATE, McpAccessTokenIssuer::SCOPE_UPLOAD],
            true,
            fn (User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult => $this->handler->handle(
                $actor,
                McpReplaceXlsxInput::fromArguments($arguments, $this->provenanceArguments),
            ),
        );
    }
}
