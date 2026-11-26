<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

use App\Application\Mcp\Input\McpPassageSearchInput;
use App\Application\Mcp\McpToolError;
use App\Application\Mcp\McpToolResult;
use App\Application\Mcp\Output\McpPassageResults;
use App\Application\PageCatalog\ArtifactContentReader;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\PageSearch;
use App\Application\PageCatalog\PageVisibilityQuery;
use App\Domain\PageCatalog\PageType;
use App\Models\IndexedPassage;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class PassageSearch
{
    private const int BATCH = 24;
    private const int BUDGET_MS = 2000;

    public function __construct(
        private PagePassageIndex $index,
        private PageVisibilityQuery $visibility,
        private PageSearch $catalog,
        private PageAccess $access,
        private ArtifactContentReader $reader,
    ) {
    }

    public function handle(User $actor, McpPassageSearchInput $input): McpToolResult
    {
        try {
            return DB::transaction(function () use ($actor, $input): McpToolResult {
                $deadline = hrtime(true) + self::BUDGET_MS * 1_000_000;
                // Bound the ranking statement itself, including a query with no candidates.
                $previousTimeout = DB::scalar("SELECT current_setting('statement_timeout')");
                DB::select("SELECT set_config('statement_timeout', ?, true)", [(string) self::BUDGET_MS]);
                $generation = $this->index->activeGeneration(lock: true);
                $result = $generation === null ? $this->unavailable() : $this->search($actor, $input, $generation, $deadline);
                DB::select("SELECT set_config('statement_timeout', ?, true)", [$previousTimeout]);

                return $result;
            });
        } catch (QueryException) {
            // A timeout discards all hits. QueryException can contain private query bindings.
            return $this->unavailable();
        }
    }

    private function search(User $actor, McpPassageSearchInput $input, int $generation, int $deadline): McpToolResult
    {
        $results = new McpPassageResults();
        $base = Page::query()
            ->join('page_versions as v', 'v.uid', '=', 'pages.current_version_uid')->whereColumn('v.page_uid', 'pages.uid')
            ->join('passage_sets as s', 's.page_version_uid', '=', 'v.uid')->whereColumn('s.source_hash', 'v.content_hash')
            ->join('passages as p', 'p.passage_set_uid', '=', 's.uid')
            ->where('s.generation', $generation)->where('s.extractor_revision', PassageExtractor::REVISION)
            ->whereIn('pages.type', [PageType::Markdown, PageType::HtmlArtifact])
            ->whereRaw("p.search_vector @@ websearch_to_tsquery('simple', ?)", [$input->query]);

        $this->visibility->apply($base, $actor);
        $this->catalog->applyFilters($base, $input->filters);
        if ($input->pageUid !== null) {
            $base->where('pages.uid', $input->pageUid);
        }

        $ordered = (clone $base)->select(['pages.uid', 'pages.workspace_uid', 'pages.owner_user_uid', 'pages.access_mode', 'pages.current_version_uid', 'p.uid as passage_uid'])
            ->orderByRaw("ts_rank_cd(p.search_vector, websearch_to_tsquery('simple', ?)) DESC", [$input->query])
            ->orderBy('pages.uid')->orderBy('p.ordinal');
        $seen = [];
        foreach ($ordered->lazy(self::BATCH) as $candidate) {
            if (hrtime(true) >= $deadline) {
                return $this->unavailable();
            }
            $id = $candidate->getAttribute('passage_uid');
            if (is_string($id) && !isset($seen[$id]) && $this->access->canView($actor, $candidate)) {
                $before = $results->count();
                if (!$this->collectWithinBudget($actor, $candidate, $id, $base, $results)) {
                    return McpToolResult::success($results);
                }
                if ($before !== $results->count()) {
                    $seen[$id] = true;
                }
                if ($results->count() === $input->limit) {
                    break;
                }
            }
        }

        return hrtime(true) >= $deadline ? $this->unavailable() : McpToolResult::success($results);
    }

    /**
     * @param Builder<Page> $base
     * @return bool Whether to keep collecting candidates
     */
    private function collectWithinBudget(User $actor, Page $candidate, string $id, Builder $base, McpPassageResults $results): bool
    {
        // Read retained bytes only for exact-authorized candidates. A missing,
        // oversized or changed original cannot support a verifiable citation.
        $version = PageVersion::query()->whereKey($candidate->current_version_uid)->where('page_uid', $candidate->uid)
            ->first(['uid', 'page_uid', 'version_number', 'content_storage_path', 'content_hash']);
        if (!$version instanceof PageVersion) {
            return true;
        }
        $source = $this->reader->read($version->content_storage_path);
        if ($source === null || hash('sha256', $source) !== $version->content_hash) {
            return true;
        }

        $live = (clone $base)->where('p.uid', $id)->where('v.uid', $version->uid);
        $page = (clone $live)->first(['pages.uid', 'pages.workspace_uid', 'pages.owner_user_uid', 'pages.access_mode', 'pages.title', 'pages.type', 'pages.status', 'pages.metadata_revision', 'pages.preview_access_revision', 's.outcome']);
        $this->access->flushCache();
        if (!$page instanceof Page || !$this->access->canView($actor, $page)) {
            return true;
        }

        // Catalog and access mutations advance these revisions. If either commits
        // after canView(), this final SELECT cannot materialize the old decision.
        $live->where('pages.preview_access_revision', $page->preview_access_revision)
            ->where('pages.metadata_revision', $page->metadata_revision);
        $passage = IndexedPassage::query()->whereIn('uid', $live->select('p.uid'))->first(['heading_path', 'source_locator', 'excerpt']);
        return !$passage instanceof IndexedPassage
            || $results->tryAppend($page, $version, $passage, $page->getAttribute('outcome') === 'limited');
    }

    private function unavailable(): McpToolResult
    {
        return McpToolResult::error(McpToolError::temporarilyUnavailable('Passage search is temporarily unavailable.', 1));
    }
}
