<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\PageFinder;
use App\Models\ExternalShare;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final readonly class ListExternalShares
{
    public function __construct(
        private PageAccess $access,
        private ExternalShareStatusResolver $statuses,
        private ActiveExternalShares $activeShares,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function forPage(
        User $actor,
        string $pageUid,
        int $pageSize = 50,
        ?CarbonImmutable $beforeCreatedAt = null,
        ?string $beforeUid = null,
    ): ExternalShareInventoryPage {
        if ($pageSize < 1 || $pageSize > 100) {
            throw new InvalidArgumentException('External share inventory page size must be between 1 and 100.');
        }

        if (($beforeCreatedAt === null) !== ($beforeUid === null)) {
            throw new InvalidArgumentException('External share inventory cursor fields must be supplied together.');
        }

        $page = PageFinder::requireByUid($pageUid);
        $this->ensureHumanAccessManager($actor, $page);
        $now = CarbonImmutable::now();
        $items = [];
        $query = ExternalShare::query()
            ->with([
                'creator:uid,name',
                'revoker:uid,name',
            ])
            ->where('page_uid', $page->uid);

        if ($beforeCreatedAt instanceof CarbonImmutable && is_string($beforeUid)) {
            $query->where(function (Builder $cursor) use ($beforeCreatedAt, $beforeUid): void {
                $cursor
                    ->where('created_at', '<', $beforeCreatedAt)
                    ->orWhere(function (Builder $sameTimestamp) use ($beforeCreatedAt, $beforeUid): void {
                        $sameTimestamp
                            ->where('created_at', '=', $beforeCreatedAt)
                            ->where('uid', '<', $beforeUid);
                    });
            });
        }

        $shares = $query
            ->orderByDesc('created_at')
            ->orderByDesc('uid')
            ->limit($pageSize + 1)
            ->get();
        $hasMore = $shares->count() > $pageSize;

        foreach ($shares->take($pageSize) as $share) {
            $items[] = new ExternalShareInventoryItem(
                uid: $share->uid,
                mode: $share->mode,
                status: $this->statuses->resolve($share, $page, $now),
                expiresAt: $share->expires_at,
                createdByUserUid: $share->created_by_user_uid,
                createdByName: $share->creator->name,
                createdAt: $share->created_at,
                redeemedAt: $share->redeemed_at,
                revokedAt: $share->revoked_at,
                revokedByUserUid: $share->revoked_by_user_uid,
                revokedByName: $share->revoker?->name,
                firstViewedAt: $share->first_viewed_at,
                lastViewedAt: $share->last_viewed_at,
                viewSessionCount: $share->view_session_count,
            );
        }

        return new ExternalShareInventoryPage(
            items: $items,
            hasMore: $hasMore,
            activeCount: $this->activeShares->countForPage($page->uid, $now),
        );
    }

    private function ensureHumanAccessManager(User $actor, \App\Models\Page $page): void
    {
        if ($actor->is_service_account) {
            throw new AuthorizationException('Only human access managers can inspect external shares.');
        }

        $this->access->ensureCanManageAccess($actor, $page);
    }
}
