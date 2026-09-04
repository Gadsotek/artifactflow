<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpCreatePageInput;
use App\Application\Mcp\Output\McpPageCreatedPayload;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\DomainRuleViolation;
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
        private McpStoredProvenancePayload $storedProvenance,
    ) {
    }

    public function handle(User $actor, McpAccessToken $token, McpCreatePageInput $input): McpToolResult
    {
        return $this->errors->guard(function () use ($actor, $token, $input): McpToolResult {
            $type = $input->type;

            if (in_array($type, [PageType::Image, PageType::Pdf, PageType::Xlsx, PageType::Docx], true)) {
                throw new DomainRuleViolation('Binary artifacts must be created through a dedicated authenticated upload.');
            }

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
                type: $type,
                title: $input->title,
                description: $input->description,
                content: $input->content,
                status: $input->status,
                categoryUid: $input->categoryUid,
                parentPageUid: $input->parentPageUid,
                tagNames: $tagNames,
                sourceFilename: $input->sourceFilename,
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
