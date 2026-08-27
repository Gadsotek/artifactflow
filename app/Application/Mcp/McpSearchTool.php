<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpSearchInput;
use App\Application\Mcp\Output\McpSearchPayload;
use App\Application\Mcp\Output\McpSearchResultView;
use App\Application\Mcp\Output\McpUntrustedText;
use App\Application\PageCatalog\PageSearch;
use App\Application\PageCatalog\PageSearchFilters;
use App\Application\PageCatalog\PageSearchResult;
use App\Application\PageCatalog\PdfProcessorConfiguration;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\McpAccessToken;
use App\Models\Page;
use App\Models\Tag;
use App\Models\User;

/**
 * MCP search tool: full-text page search through the same PageSearch the
 * human UI uses. Snippets additionally require the mcp:read scope because
 * they expose page content, not just metadata.
 */
final readonly class McpSearchTool
{
    public function __construct(
        private PageSearch $pageSearch,
        private McpPageHierarchy $hierarchy,
        private PdfProcessorConfiguration $pdfConfiguration,
    ) {
    }

    public function handle(User $actor, McpAccessToken $token, McpSearchInput $input): McpToolResult
    {
        $includeSnippet = $input->includeSnippet;

        if ($includeSnippet && !$token->hasScope(McpAccessTokenIssuer::SCOPE_READ)) {
            return McpToolResult::error(McpToolError::insufficientScope(
                'The mcp:read scope is required for snippets.',
            ));
        }

        $status = $input->status;
        $statuses = $status instanceof PageStatus
            ? [$status]
            : ($input->includeArchived
                ? PageStatus::cases()
                : PageSearchFilters::activeStatuses());
        $categoryUid = $input->categoryUid;
        $provider = $input->aiProvider;
        $type = $input->type;

        if ($type === PageType::Pdf && !$this->pdfConfiguration->enabled()) {
            return McpToolResult::error(McpToolError::unsupportedContentType(
                'PDF content is not available through MCP yet.',
            ));
        }

        $filters = new PageSearchFilters(
            query: $input->query,
            workspaceUid: $input->workspaceUid,
            type: $type,
            statuses: $statuses,
            categoryUids: $categoryUid === null ? [] : [$categoryUid],
            tagUids: $input->tagUids,
            ownerUserUid: $input->ownerUserUid,
            sort: $input->sort,
            aiProviders: $provider === null ? [] : [$provider],
            aiModelQuery: $input->aiModelQuery,
            provenanceScope: $input->provenanceScope,
            excludedTypes: $this->pdfConfiguration->enabled() ? [] : [PageType::Pdf],
        );
        $results = $this->pageSearch->search(
            actor: $actor,
            filters: $filters,
            includeSnippets: $includeSnippet,
        );
        $hierarchyByPageUid = $this->hierarchy->forPages(
            $actor,
            array_map(static fn (PageSearchResult $result): Page => $result->page, $results),
        );

        return McpToolResult::success(new McpSearchPayload(
            results: array_map(function (PageSearchResult $result) use (
                $hierarchyByPageUid,
                $includeSnippet,
            ): McpSearchResultView {
                $page = $result->page;
                return new McpSearchResultView(
                    uid: $page->uid,
                    title: new McpUntrustedText($page->title),
                    type: $page->type->value,
                    status: $page->status->value,
                    currentVersionUid: $page->current_version_uid,
                    metadataRevision: $page->metadata_revision,
                    tags: array_map(
                        static fn (Tag $tag): McpUntrustedText => new McpUntrustedText($tag->name),
                        array_values($page->tags->all()),
                    ),
                    hierarchy: $hierarchyByPageUid[$page->uid],
                    updatedAt: $page->updated_at?->toISOString(),
                    snippet: $includeSnippet ? McpUntrustedText::fromNullable($result->snippet) : null,
                );
            }, $results),
        ));
    }
}
