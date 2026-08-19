<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\McpAccessToken;
use App\Models\User;

/**
 * MCP create tool: create a page through the same CreatePage handler,
 * policies, scanners, and audit trail as the human UI.
 */
final readonly class McpCreateTool
{
    public function __construct(
        private CreatePage $createPage,
        private McpPagePayload $payload,
        private McpToolErrorMapper $errors,
        private McpProvenanceArguments $provenance,
        private McpStoredProvenancePayload $storedProvenance,
    ) {
    }

    public function handle(User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult
    {
        return $this->errors->guard(function () use ($actor, $token, $arguments): McpToolResult {
            $type = $arguments->requiredPageType('type');

            if ($type === PageType::Image || $type === PageType::Pdf) {
                throw new DomainRuleViolation('Binary artifacts must be created through a dedicated authenticated upload.');
            }

            $tagNames = $arguments->stringList('tags');

            if (
                ($arguments->nullableString('category_name') !== null || $tagNames !== [])
                && !$token->hasScope(McpAccessTokenIssuer::SCOPE_ORGANIZE)
            ) {
                return McpToolResult::error([
                    'type' => 'insufficient_scope',
                    'message' => sprintf('The %s scope is required.', McpAccessTokenIssuer::SCOPE_ORGANIZE),
                ]);
            }

            $declaredProvenance = $this->provenance->fromArguments($arguments);
            $page = $this->createPage->handle($actor, new CreatePageCommand(
                workspaceUid: $arguments->requiredString('workspace_uid'),
                type: $type,
                title: $arguments->requiredString('title'),
                description: $arguments->nullableString('description'),
                content: $arguments->requiredString('content'),
                status: $arguments->pageStatus('status') ?? PageStatus::Draft,
                categoryUid: $arguments->nullableString('category_uid'),
                parentPageUid: $arguments->nullableString('parent_page_uid'),
                tagNames: $tagNames,
                sourceFilename: $arguments->nullableString('source_filename'),
                source: PageVersionSource::Mcp,
                categoryName: $arguments->nullableString('category_name'),
                provenance: $declaredProvenance,
                changeSummary: $arguments->requiredString('change_summary'),
            ));
            $version = $page->currentVersion()->sole();

            return McpToolResult::success($this->payload->forPage($page) + [
                'current_version_uid' => $page->current_version_uid,
            ] + $this->storedProvenance->forVersion($version));
        }, authorizationResource: McpNotFoundResource::Workspace);
    }
}
