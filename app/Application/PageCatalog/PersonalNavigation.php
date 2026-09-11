<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Mcp\McpEffectiveAuthority;
use App\Domain\PageCatalog\PageStatus;
use App\Models\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

final readonly class PersonalNavigation
{
    public function __construct(
        private PageAccess $access,
        private PageVisibilityQuery $visibility,
        private McpEffectiveAuthority $authority,
    ) {
    }

    /** @return list<NavigationPage> */
    public function pages(User $actor, string $collection, string $search = '', ?string $workspaceUid = null): array
    {
        $query = Page::query()->select('pages.*', 'personal.favorited_at as navigation_favorited_at')
            ->with(['workspace', 'accessGrants'])
            ->leftJoin('personal_page_states as personal', function (JoinClause $join) use ($actor): void {
                $join->on('personal.page_uid', '=', 'pages.uid')->where('personal.user_uid', $actor->uid);
            });
        $scope = $this->visibility->apply($query, $actor);

        if ($workspaceUid !== null && $workspaceUid !== PageSearchFilters::ALL_WORKSPACES) {
            $query->where('pages.workspace_uid', $workspaceUid);
        }

        if ($collection === 'favorites') {
            $query->whereNotNull('personal.favorited_at')->orderByDesc('personal.favorited_at');
        } else {
            $query->where('pages.status', '!=', PageStatus::Archived);

            if ($search !== '') {
                $query->whereRaw('strpos(lower(pages.title), lower(?)) > 0', [$search]);
                $query->orderBy('pages.title');
            } else {
                $query->where(function (Builder $query) use ($collection): void {
                    $query->whereNotNull('personal.last_opened_at');

                    if ($collection === 'quick') {
                        $query->orWhereNotNull('personal.favorited_at');
                    }
                })->orderByRaw('personal.last_opened_at DESC NULLS LAST');
            }
        }

        $limit = $collection === 'quick' ? 20 : ($collection === 'favorites' ? 200 : 100);
        $results = [];

        // Apply exact authority before counting a result toward the limit.
        foreach ($query->orderBy('pages.uid')->lazy(100) as $page) {
            if (!$this->access->canView($actor, $page)) {
                continue;
            }

            $results[] = new NavigationPage(
                uid: $page->uid,
                title: $page->title,
                url: route('pages.show', $page),
                type: $page->type->label(),
                status: $page->status->value,
                workspace: $this->authority->canExposeWorkspaceName($page->workspace_uid, $scope->membershipWorkspaceUids)
                    ? $page->workspace->name : null,
                favorite: $page->getAttribute('navigation_favorited_at') !== null,
            );

            if (count($results) === $limit) {
                break;
            }
        }

        return $results;
    }
}
