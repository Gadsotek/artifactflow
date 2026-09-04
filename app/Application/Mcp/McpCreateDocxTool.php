<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpCreateDocxInput;
use App\Application\Mcp\Output\McpPageCreatedPayload;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\PageAccess;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\McpAccessToken;
use App\Models\User;

final readonly class McpCreateDocxTool
{
    public function __construct(
        private PageAccess $access,
        private CreatePage $createPage,
        private McpDocxUpload $upload,
        private McpPagePayload $payload,
        private McpDocxVersionPayload $docxPayload,
        private McpToolErrorMapper $errors,
        private McpStoredProvenancePayload $storedProvenance,
    ) {
    }

    public function handle(User $actor, McpAccessToken $token, McpCreateDocxInput $input): McpToolResult
    {
        return $this->errors->guard(function () use ($actor, $token, $input): McpToolResult {
            $this->access->ensureCanCreateInWorkspace($actor, $input->workspaceUid);
            if (($input->categoryName !== null || $input->tags !== [])
                && !$token->hasScope(McpAccessTokenIssuer::SCOPE_ORGANIZE)) {
                return McpToolResult::error(McpToolError::insufficientScope(
                    sprintf('The %s scope is required.', McpAccessTokenIssuer::SCOPE_ORGANIZE),
                ));
            }
            $page = $this->createPage->handle($actor, new CreatePageCommand(
                workspaceUid: $input->workspaceUid,
                type: PageType::Docx,
                title: $input->title,
                description: $input->description,
                content: $this->upload->decode($input->encodedDocx),
                status: $input->status,
                categoryUid: $input->categoryUid,
                parentPageUid: $input->parentPageUid,
                tagNames: $input->tags,
                sourceFilename: 'document.docx',
                source: PageVersionSource::Mcp,
                categoryName: $input->categoryName,
                provenance: $input->provenance,
                changeSummary: $input->changeSummary,
            ));
            $version = $page->currentVersion()->sole();
            $docx = $this->docxPayload->facts($version);
            if ($docx === null) {
                throw new DomainRuleViolation('DOCX processing facts are unavailable.');
            }

            return McpToolResult::success(new McpPageCreatedPayload(
                page: $this->payload->forPage($page),
                currentVersionUid: $page->current_version_uid,
                storedProvenance: $this->storedProvenance->forVersion($version),
                docx: $docx,
            ));
        }, authorizationResource: McpNotFoundResource::Workspace);
    }
}
