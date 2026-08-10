<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\Provenance\ProducerKind;
use App\Models\Page;
use App\Models\ProducerAssertion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds provenance facets only from pages the actor can actually view. The SQL
 * visibility predicate intentionally remains an over-approximation; canView()
 * is still the disclosure boundary before assertion labels become options.
 */
final readonly class PageFilterProvenance
{
    public function __construct(
        private PageVisibilityQuery $visibility,
        private PageAccess $access,
    ) {
    }

    public function forUser(User $actor, ?string $workspaceUid = null): PageFilterProvenanceResult
    {
        $query = Page::query()
            ->select(['uid', 'workspace_uid', 'owner_user_uid', 'access_mode'])
            ->with('accessGrants')
            ->orderBy('pages.uid');
        $this->visibility->apply($query, $actor);

        if ($workspaceUid !== null && $workspaceUid !== PageSearchFilters::ALL_WORKSPACES) {
            $query->where('workspace_uid', $workspaceUid);
        }

        /** @var array<string, string> $providers */
        $providers = [];
        /** @var array<string, string> $models */
        $models = [];
        $visiblePageUids = [];

        foreach ($query->lazyById(200, 'pages.uid', 'uid') as $page) {
            if (!$this->access->canView($actor, $page)) {
                continue;
            }

            $visiblePageUids[] = $page->uid;

            if (count($visiblePageUids) === 200) {
                $this->collectOptions($visiblePageUids, $providers, $models);
                $visiblePageUids = [];
            }
        }

        $this->collectOptions($visiblePageUids, $providers, $models);
        ksort($providers, SORT_NATURAL | SORT_FLAG_CASE);
        uasort($models, static fn (string $left, string $right): int => strnatcasecmp($left, $right));

        return new PageFilterProvenanceResult(
            providers: array_map(
                static fn (string $label, string $value): PageFilterProvenanceOption => new PageFilterProvenanceOption($value, $label),
                array_values($providers),
                array_keys($providers),
            ),
            models: array_map(
                static fn (string $label, string $value): PageFilterProvenanceOption => new PageFilterProvenanceOption($value, $label),
                array_values($models),
                array_keys($models),
            ),
        );
    }

    /**
     * @param list<string> $pageUids
     * @param array<string, string> $providers
     * @param array<string, string> $models
     */
    private function collectOptions(array $pageUids, array &$providers, array &$models): void
    {
        if ($pageUids === []) {
            return;
        }

        $providerAssertions = $this->activeAiAssertionsForPages($pageUids)
            ->select([
                'producer_assertions.provider_key',
                'producer_assertions.reported_provider',
            ])
            ->distinct()
            ->orderBy('producer_assertions.provider_key')
            ->orderBy('producer_assertions.reported_provider')
            ->cursor();

        foreach ($providerAssertions as $assertion) {
            if ($assertion->provider_key !== null) {
                $providers[$assertion->provider_key] = $assertion->reported_provider
                    ?? $assertion->provider_key;
            }
        }

        $modelAssertions = $this->activeAiAssertionsForPages($pageUids)
            ->select([
                'producer_assertions.model_id',
                'producer_assertions.model_label',
            ])
            ->distinct()
            ->orderBy('producer_assertions.model_id')
            ->orderBy('producer_assertions.model_label')
            ->cursor();

        foreach ($modelAssertions as $assertion) {
            if ($assertion->model_id === null) {
                if ($assertion->model_label !== null) {
                    $models[$assertion->model_label] = $assertion->model_label;
                }

                continue;
            }

            $label = $assertion->model_label;
            $models[$assertion->model_id] = $label === null || $label === $assertion->model_id
                ? $assertion->model_id
                : $label . ' — ' . $assertion->model_id;
        }
    }

    /**
     * @param list<string> $pageUids
     * @return Builder<ProducerAssertion>
     */
    private function activeAiAssertionsForPages(array $pageUids): Builder
    {
        return ProducerAssertion::query()
            ->join(
                'page_version_ingests',
                'page_version_ingests.uid',
                '=',
                'producer_assertions.page_version_ingest_uid',
            )
            ->whereIn('page_version_ingests.page_uid', $pageUids)
            ->where('producer_assertions.producer_kind', ProducerKind::Ai->value)
            ->whereDoesntHave('supersededBy');
    }
}
