<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\PageSearch;
use App\Application\PageCatalog\PageSearchFilters;
use App\Application\PageCatalog\PageSearchResult;
use App\Application\PageCatalog\PageSearchSort;
use App\Application\PageCatalog\PdfProcessorConfiguration;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\Provenance\ProvenanceSearchScope;
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

    public function handle(User $actor, McpAccessToken $token, McpToolArguments $arguments): McpToolResult
    {
        $includeSnippet = $arguments->bool('include_snippet', false);

        if ($includeSnippet && !$token->hasScope(McpAccessTokenIssuer::SCOPE_READ)) {
            return McpToolResult::error([
                'type' => 'insufficient_scope',
                'message' => 'The mcp:read scope is required for snippets.',
            ]);
        }

        $provenanceScopeValue = $arguments->string(
            'provenance_scope',
            ProvenanceSearchScope::AnyVersion->value,
        );
        $provenanceScope = ProvenanceSearchScope::tryFrom($provenanceScopeValue);

        if (!$provenanceScope instanceof ProvenanceSearchScope) {
            throw new DomainRuleViolation('Argument [provenance_scope] has an unsupported value.');
        }

        $status = $arguments->pageStatus('status');
        $statuses = $status instanceof PageStatus
            ? [$status]
            : ($arguments->bool('include_archived', false)
                ? PageStatus::cases()
                : PageSearchFilters::activeStatuses());
        $categoryUid = $arguments->nullableString('category_uid');
        $provider = $arguments->nullableString('ai_provider');

        $type = $arguments->pageType('type');

        if ($type === PageType::Pdf && !$this->pdfConfiguration->enabled()) {
            return McpToolResult::error([
                'type' => 'unsupported_content_type',
                'message' => 'PDF content is not available through MCP yet.',
            ]);
        }

        $filters = new PageSearchFilters(
            query: $arguments->nullableString('query'),
            workspaceUid: $arguments->nullableString('workspace_uid'),
            type: $type,
            statuses: $statuses,
            categoryUids: $categoryUid === null ? [] : [$categoryUid],
            tagUids: $arguments->stringList('tag_uids'),
            ownerUserUid: $arguments->nullableString('owner_user_uid'),
            sort: PageSearchSort::tryFrom($arguments->string('sort', PageSearchSort::Relevance->value))
                ?? PageSearchSort::Relevance,
            aiProviders: $provider === null ? [] : [$provider],
            aiModelQuery: $arguments->nullableString('ai_model_query'),
            provenanceScope: $provenanceScope,
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

        return McpToolResult::success([
            'results' => array_map(function (PageSearchResult $result) use (
                $hierarchyByPageUid,
                $includeSnippet,
            ): array {
                $page = $result->page;
                $payload = [
                    'uid' => $page->uid,
                    'title' => McpDataEnvelope::text($page->title),
                    'type' => $page->type->value,
                    'status' => $page->status->value,
                    'current_version_uid' => $page->current_version_uid,
                    'metadata_revision' => $page->metadata_revision,
                    'tags' => array_map(
                        static fn (Tag $tag): array => McpDataEnvelope::text($tag->name),
                        array_values($page->tags->all()),
                    ),
                    'hierarchy' => $hierarchyByPageUid[$page->uid],
                    'updated_at' => $page->updated_at?->toISOString(),
                ];

                if ($includeSnippet) {
                    $payload['snippet'] = McpDataEnvelope::text($result->snippet);
                }

                return $payload;
            }, $results),
        ]);
    }
}
