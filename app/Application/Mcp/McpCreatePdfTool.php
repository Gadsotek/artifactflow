<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\PageAccess;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageStatus;
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
        private McpProvenanceArguments $provenance,
        private McpStoredProvenancePayload $storedProvenance,
    ) {
    }

    public function handle(User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult
    {
        return $this->errors->guard(function () use ($actor, $token, $arguments): McpToolResult {
            $workspaceUid = $arguments->requiredString('workspace_uid');

            // Scope checks happen in the transport guard. Resolve exact workspace
            // authority next, before touching the large Base64 field or parser.
            $this->access->ensureCanCreateInWorkspace($actor, $workspaceUid);
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
                workspaceUid: $workspaceUid,
                type: PageType::Pdf,
                title: $arguments->requiredString('title'),
                description: $arguments->nullableString('description'),
                content: $this->upload->decode($arguments),
                status: $arguments->pageStatus('status') ?? PageStatus::Draft,
                categoryUid: $arguments->nullableString('category_uid'),
                parentPageUid: $arguments->nullableString('parent_page_uid'),
                tagNames: $tagNames,
                sourceFilename: 'document.pdf',
                source: PageVersionSource::Mcp,
                categoryName: $arguments->nullableString('category_name'),
                provenance: $declaredProvenance,
                changeSummary: $arguments->requiredString('change_summary'),
            ));
            $version = $page->currentVersion()->sole();
            $pdf = $this->pdfPayload->forVersion($version);

            if ($pdf === null) {
                throw new DomainRuleViolation('PDF processing facts are unavailable.');
            }

            return McpToolResult::success($this->payload->forPage($page) + [
                'current_version_uid' => $page->current_version_uid,
                'pdf' => $pdf,
            ] + $this->storedProvenance->forVersion($version));
        }, authorizationResource: McpNotFoundResource::Workspace);
    }
}
