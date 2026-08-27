<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpCreateImageInput;
use App\Application\Mcp\Output\McpPageCreatedPayload;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
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
        private McpStoredProvenancePayload $storedProvenance,
    ) {
    }

    public function handle(User $actor, McpAccessToken $token, McpCreateImageInput $input): McpToolResult
    {
        return $this->errors->guard(function () use ($actor, $token, $input): McpToolResult {
            $tagNames = $input->tags;

            if (
                ($input->categoryName !== null || $tagNames !== [])
                && !$token->hasScope(McpAccessTokenIssuer::SCOPE_ORGANIZE)
            ) {
                return McpToolResult::error(McpToolError::insufficientScope(
                    sprintf('The %s scope is required.', McpAccessTokenIssuer::SCOPE_ORGANIZE),
                ));
            }

            $page = $this->createPage->handle($actor, new CreatePageCommand(
                workspaceUid: $input->workspaceUid,
                type: PageType::Image,
                title: $input->title,
                description: $input->description,
                content: $this->upload->decode($input->encodedImage, $input->mediaType),
                status: $input->status,
                categoryUid: $input->categoryUid,
                parentPageUid: $input->parentPageUid,
                tagNames: $tagNames,
                source: PageVersionSource::Mcp,
                categoryName: $input->categoryName,
                provenance: $input->provenance,
                changeSummary: $input->changeSummary,
            ));
            $version = $page->currentVersion()->sole();

            return McpToolResult::success(new McpPageCreatedPayload(
                page: $this->payload->forPage($page),
                currentVersionUid: $page->current_version_uid,
                storedProvenance: $this->storedProvenance->forVersion($version),
            ));
        }, authorizationResource: McpNotFoundResource::Workspace);
    }
}
