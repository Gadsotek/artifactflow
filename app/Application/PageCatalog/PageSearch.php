<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Mcp\McpEffectiveAuthority;
use App\Domain\PageCatalog\PageStatus;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class PageSearch
{
    private const int RESULT_LIMIT = 100;
    private const string TEXT_SEARCH_CONFIG = PageSearchVectorUpdater::TEXT_SEARCH_CONFIG;
    private const int MAX_QUERY_CHARACTERS = 200;

    public function __construct(
        private readonly PageAccess $access,
        private readonly McpEffectiveAuthority $mcpAuthority,
        private readonly PageVisibilityQuery $visibility,
    ) {
    }

    /**
     * @return list<PageSearchResult>
     */
    public function search(
        User $actor,
        PageSearchFilters $filters,
        bool $includeSnippets = true,
    ): array {
        $query = Page::query()
            ->with(['accessGrants', 'category', 'currentVersion', 'owner', 'tags', 'workspace'])
            ->limit(self::RESULT_LIMIT);

        $visibilityScope = $this->visibility->apply($query, $actor);
        $this->applyFilters($query, $filters);

        if ($filters->hasQuery()) {
            $this->applyTextSearch($query, (string) $filters->query);
        }

        $this->applySort($query, $filters);
        // The SQL visibility clause over-approximates in rare cases (grant-role
        // lowering, membership-removal rules), so canView() re-filters after the
        // LIMIT; a full result page can therefore come back slightly thinner
        // than RESULT_LIMIT rather than leak a page the actor cannot view.
        $pages = $query->get()
            ->filter(fn (Page $page): bool => $this->access->canView($actor, $page))
            ->values();

        $results = [];

        foreach ($pages as $page) {
            $searchRank = $page->getAttribute('search_rank');
            $results[] = new PageSearchResult(
                page: $page,
                snippet: $includeSnippets ? $this->snippet($page, $filters->query) : null,
                rank: is_numeric($searchRank) ? (float) $searchRank : 0.0,
                workspaceName: $this->mcpAuthority->canExposeWorkspaceName(
                    $page->workspace_uid,
                    $visibilityScope->membershipWorkspaceUids,
                )
                    ? $page->workspace->name
                    : null,
            );
        }

        return $results;
    }

    /**
     * @param Builder<Page> $query
     */
    private function applyFilters(Builder $query, PageSearchFilters $filters): void
    {
        if ($filters->workspaceUid !== null && $filters->workspaceUid !== PageSearchFilters::ALL_WORKSPACES) {
            $query->where('workspace_uid', $filters->workspaceUid);
        }

        if (!$filters->includeArchived && $filters->status !== PageStatus::Archived) {
            $query->where('status', '!=', PageStatus::Archived);
        }

        if ($filters->type !== null) {
            $query->where('type', $filters->type);
        }

        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }

        if ($filters->categoryUid !== null) {
            $query->where('category_uid', $filters->categoryUid);
        }

        if ($filters->ownerUserUid !== null) {
            $query->where('owner_user_uid', $filters->ownerUserUid);
        }

        foreach ($filters->tagUids as $tagUid) {
            $query->whereHas('tags', static function (Builder $query) use ($tagUid): void {
                $query->where('tags.uid', $tagUid);
            });
        }
    }

    /**
     * @param Builder<Page> $query
     */
    private function applyTextSearch(Builder $query, string $rawSearch): void
    {
        $search = $this->normalizedSearch($rawSearch);

        if ($search === null) {
            return;
        }

        $searchVector = 'pages.search_vector';
        $searchQuery = sprintf("websearch_to_tsquery('%s', ?)", self::TEXT_SEARCH_CONFIG);
        $rank = sprintf(
            <<<'SQL'
                CASE WHEN lower(pages.title) = lower(?) THEN 1000.0 ELSE 0.0 END
                + CASE WHEN strpos(lower(pages.title), lower(?)) > 0 THEN 750.0 ELSE 0.0 END
                + CASE WHEN EXISTS (
                    SELECT 1
                    FROM page_tag
                    INNER JOIN tags ON tags.uid = page_tag.tag_uid
                    WHERE page_tag.page_uid = pages.uid
                      AND (lower(tags.name) = lower(?) OR lower(tags.slug) = lower(?))
                ) THEN 500.0 ELSE 0.0 END
                + (ts_rank_cd(%s, %s, 32) * 100.0)
                SQL,
            $searchVector,
            $searchQuery,
        );
        /** @var literal-string $selectRank */
        $selectRank = sprintf('(%s) AS search_rank', $rank);
        /** @var literal-string $match */
        $match = sprintf('%s @@ %s', $searchVector, $searchQuery);

        $query
            ->select('pages.*')
            ->selectRaw($selectRank, [$search, $search, $search, $search, $search])
            ->whereRaw($match, [$search]);
    }

    /**
     * @param Builder<Page> $query
     */
    private function applySort(Builder $query, PageSearchFilters $filters): void
    {
        if ($filters->sort === PageSearchSort::Title) {
            $query->orderBy('pages.title')
                ->orderByDesc('pages.updated_at');

            return;
        }

        if ($filters->sort === PageSearchSort::RecentlyUpdated || !$filters->hasQuery()) {
            $query->orderByDesc('pages.updated_at')
                ->orderBy('pages.title');

            return;
        }

        $query->orderByDesc('search_rank')
            ->orderByDesc('pages.updated_at')
            ->orderBy('pages.title');
    }

    private function snippet(Page $page, ?string $rawSearch): ?string
    {
        $search = $this->normalizedSearch($rawSearch);
        if ($page->description !== null) {
            return $this->shorten($page->description);
        }

        $candidates = [$page->currentVersion instanceof PageVersion ? $page->currentVersion->extracted_text : null];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            if ($search === null || mb_stripos($candidate, $search) !== false) {
                return $this->shorten($candidate);
            }
        }

        return null;
    }

    private function shorten(string $text): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($text));

        if (!is_string($normalized)) {
            return '';
        }

        if (mb_strlen($normalized) <= 180) {
            return $normalized;
        }

        return mb_substr($normalized, 0, 177) . '...';
    }

    private function normalizedSearch(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($search));

        return $normalized === '' ? null : mb_substr($normalized, 0, self::MAX_QUERY_CHARACTERS);
    }
}
