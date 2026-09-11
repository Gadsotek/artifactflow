<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Application\PageCatalog\PageSearchFilters;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\Provenance\ProvenanceSearchScope;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class PageFilterChips extends Component
{
    /** @var list<ActivePageFilter> */
    public array $chips = [];

    public function __construct(PageSearchFilters $filters, UrlGenerator $url)
    {
        // Rebuild from normalized filters, keeping exact scope and sort while
        // resetting pagination. Never copy arbitrary query parameters into links.
        $query = $this->query($filters);
        $labels = [
            'q' => 'Search: ' . ($filters->query ?? ''),
            'type' => $filters->type?->label() ?? '',
            'statuses' => 'Status: ' . implode(', ', array_map(static fn (PageStatus $status): string => ucfirst($status->value), $filters->statuses)),
            'category_uids' => count($filters->categoryUids) . ' selected categories',
            'tag_uids' => count($filters->tagUids) . ' selected tags',
            'owner_user_uid' => 'Selected owner',
            'ai_providers' => 'AI provider: ' . implode(', ', $filters->aiProviders),
            'ai_model_ids' => count($filters->aiModelIds) . ' selected AI models',
            'ai_model_query' => 'AI model: ' . ($filters->aiModelQuery ?? ''),
            'provenance_scope' => match ($filters->provenanceScope) {
                ProvenanceSearchScope::AnyVersion => '',
                ProvenanceSearchScope::CurrentVersion => 'Current version provenance',
                ProvenanceSearchScope::PageOrigin => 'Page origin provenance',
            },
        ];

        foreach ($labels as $key => $label) {
            if (!array_key_exists($key, $query)) {
                continue;
            }

            $remaining = $query;
            unset($remaining[$key]);
            $this->chips[] = new ActivePageFilter($key, $label, $url->route('pages.index', $remaining));
        }
    }

    /** @return array<string, string|list<string>> */
    private function query(PageSearchFilters $filters): array
    {
        $query = [
            'workspace_uid' => $filters->workspaceUid ?? PageSearchFilters::ALL_WORKSPACES,
            'sort' => $filters->sort->value,
        ];

        foreach ([
            'q' => $filters->query, 'type' => $filters->type?->value,
            'owner_user_uid' => $filters->ownerUserUid, 'ai_model_query' => $filters->aiModelQuery,
        ] as $key => $value) {
            if ($value !== null && $value !== '') {
                $query[$key] = $value;
            }
        }

        foreach ([
            'category_uids' => $filters->categoryUids, 'tag_uids' => $filters->tagUids,
            'ai_providers' => $filters->aiProviders, 'ai_model_ids' => $filters->aiModelIds,
        ] as $key => $values) {
            if ($values !== []) {
                $query[$key] = $values;
            }
        }

        if ($filters->statuses !== PageSearchFilters::activeStatuses()) {
            $query['statuses'] = array_map(static fn (PageStatus $status): string => $status->value, $filters->statuses);
        }

        if ($filters->provenanceScope !== ProvenanceSearchScope::AnyVersion) {
            $query['provenance_scope'] = $filters->provenanceScope->value;
        }

        return $query;
    }

    public function render(): View
    {
        return view('components.page-filter-chips');
    }
}
