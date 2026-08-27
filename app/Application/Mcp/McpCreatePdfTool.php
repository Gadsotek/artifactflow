<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpCreatePdfInput;
use App\Application\Mcp\Output\McpPageCreatedPayload;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\PageAccess;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\McpAccessToken;
use App\Models\User;

final readonly class McpCreatePdfTool
{
    public function __construct(
        private PageAccess $access,
        private CreatePage $createPage,
        private McpPdfUpload $upload,
        private McpPagePayload $payload,
        private McpPdfVersionPayload $pdfPayload,
        private McpToolErrorMapper $errors,
        private McpStoredProvenancePayload $storedProvenance,
    ) {
    }

    public function handle(User $actor, McpAccessToken $token, McpCreatePdfInput $input): McpToolResult
    {
        return $this->errors->guard(function () use ($actor, $token, $input): McpToolResult {
            $workspaceUid = $input->workspaceUid;

            // Scope checks happen in the transport guard. Resolve exact workspace
            // authority next, before decoding the Base64 field or invoking the parser.
            $this->access->ensureCanCreateInWorkspace($actor, $workspaceUid);
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
                workspaceUid: $workspaceUid,
                type: PageType::Pdf,
                title: $input->title,
                description: $input->description,
                content: $this->upload->decode($input->encodedPdf),
                status: $input->status,
                categoryUid: $input->categoryUid,
                parentPageUid: $input->parentPageUid,
                tagNames: $tagNames,
                sourceFilename: 'document.pdf',
                source: PageVersionSource::Mcp,
                categoryName: $input->categoryName,
                provenance: $input->provenance,
                changeSummary: $input->changeSummary,
            ));
            $version = $page->currentVersion()->sole();
            $pdf = $this->pdfPayload->forVersion($version);

            if ($pdf === null) {
                throw new DomainRuleViolation('PDF processing facts are unavailable.');
            }

            return McpToolResult::success(new McpPageCreatedPayload(
                page: $this->payload->forPage($page),
                currentVersionUid: $page->current_version_uid,
                storedProvenance: $this->storedProvenance->forVersion($version),
                pdf: $pdf,
            ));
        }, authorizationResource: McpNotFoundResource::Workspace);
    }
}
