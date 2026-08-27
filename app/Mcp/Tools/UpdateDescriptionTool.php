<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Mcp\Input\McpUpdateDescriptionInput;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpToolArguments;
use App\Application\Mcp\McpToolResult;
use App\Application\Mcp\McpUpdateDescriptionTool as Handler;
use App\Application\PageCatalog\PageMetadataRules;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_description')]
#[Description('Set or clear a page description with metadata concurrency, normal authorization, scanning, search, and audit behavior. Image pixels are not OCR-indexed, so add a searchable description after inspecting the image.')]
final class UpdateDescriptionTool extends ArtifactFlowTool
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
            'expected_current_version_uid' => $schema->string()
                ->description('Must match current_version_uid returned by read or search.')
                ->required(),
            'expected_metadata_revision' => $schema->integer()
                ->description('Must match metadata_revision returned by read or search.')
                ->required(),
            'description' => $schema->string()
                ->max(PageMetadataRules::MAX_DESCRIPTION_CHARACTERS)
                ->description('New description. For image pages, describe only visible content using terms useful for search. Omit it to clear the description.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->invoke(
            $request,
            McpAccessTokenIssuer::SCOPE_UPDATE,
            true,
            fn (User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult => $this->handler->handle(
                $actor,
                McpUpdateDescriptionInput::fromArguments($arguments),
            ),
        );
    }
}
