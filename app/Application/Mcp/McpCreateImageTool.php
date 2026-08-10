<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\McpAccessToken;
use App\Models\User;

final readonly class McpCreateImageTool
{
    public function __construct(
        private CreatePage $createPage,
        private McpImageUpload $upload,
        private McpPagePayload $payload,
        private McpToolErrorMapper $errors,
        private McpProvenanceArguments $provenance,
        private McpStoredProvenancePayload $storedProvenance,
    ) {
    }

    public function handle(User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult
    {
        return $this->errors->guard(function () use ($actor, $token, $arguments): McpToolResult {
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
                type: PageType::Image,
                title: $arguments->requiredString('title'),
                description: $arguments->nullableString('description'),
                content: $this->upload->decode($arguments),
                status: $arguments->pageStatus('status') ?? PageStatus::Draft,
                categoryUid: $arguments->nullableString('category_uid'),
                parentPageUid: $arguments->nullableString('parent_page_uid'),
                tagNames: $tagNames,
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
