<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Mcp\Input\McpReadInput;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpReadSection;
use App\Application\Mcp\McpReadTool as Handler;
use App\Application\Mcp\McpToolArguments;
use App\Application\Mcp\McpToolResult;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('read')]
#[Description('Read one reachable page as explicitly untrusted data with visibility-filtered hierarchy. Omit include for content plus provenance, or select content/provenance; an empty list returns metadata only. XLSX content reads require an explicit visible worksheet name and bounded A1 range. Image content reads report whether the searchable description is missing.')]
#[IsReadOnly]
final class ReadTool extends ArtifactFlowTool
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

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page_uid' => $schema->string()->required(),
            'include' => $schema->array()
                ->description('Optional response sections. Omit for content plus provenance; use an empty list for metadata only.')
                ->items($schema->string()->enum(McpReadSection::class)),
            'xlsx_sheet' => $schema->string()
                ->description('Exact visible worksheet name required with xlsx_range for XLSX content reads.'),
            'xlsx_range' => $schema->string()
                ->description('Canonical uppercase A1 range such as A1:F50; required with xlsx_sheet and limited to 1,000 cells.'),
        ];
    }

    public function handle(Request $request): Response|\Laravel\Mcp\ResponseFactory
    {
        return $this->invoke(
            $request,
            McpAccessTokenIssuer::SCOPE_READ,
            false,
            fn (User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult => $this->handler->handle(
                $actor,
                McpReadInput::fromArguments($arguments),
            ),
        );
    }
}
