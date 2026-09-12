<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\Page;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class PersonalPageState
{
    public const int RECENT_LIMIT = 100;
    public const int FAVORITE_LIMIT = 200;

    public function __construct(private PageAccess $access, private PageFinder $pages)
    {
    }

    public function recordVisit(User $actor, Page $page): void
    {
        if ($actor->is_service_account) {
            return;
        }

        $this->update($actor, $page, null);
    }

    public function favorite(User $actor, Page $page, bool $favorite): void
    {
        abort_if($actor->is_service_account, 403);
        $this->update($actor, $page, $favorite);
    }

    public function isFavorite(User $actor, Page $page): bool
    {
        return $this->states($actor)->where('page_uid', $page->uid)->whereNotNull('favorited_at')->exists();
    }

    public function clearRecent(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $this->lockActor($actor);
            $this->states($actor)->update(['last_opened_at' => null]);
            $this->removeEmpty($actor);
        }, 3);
    }

    private function update(User $actor, Page $page, ?bool $favorite): void
    {
        DB::transaction(function () use ($actor, $page, $favorite): void {
            if ($favorite === null) {
                // Visits change only private navigation state. Recheck current
                // authority without serializing readers on the shared page row.
                $current = $this->pages->requireByUid($page->uid);
                $this->access->flushCache();
                abort_unless($this->access->canView($actor, $current), 404);
            } else {
                $this->access->lockAndReauthorize($page->uid, function (Page $locked) use ($actor): void {
                    abort_unless($this->access->canView($actor, $locked), 404);
                });
            }
            $this->lockActor($actor);
            $state = $this->states($actor)->where('page_uid', $page->uid);

            if ($favorite === true && $this->states($actor)->whereNotNull('favorited_at')->count() >= self::FAVORITE_LIMIT) {
                $this->releaseInaccessibleFavorites($actor);
            }

            if ($favorite === true && !$this->isFavorite($actor, $page)
                && $this->states($actor)->whereNotNull('favorited_at')->count() >= self::FAVORITE_LIMIT) {
                throw ValidationException::withMessages([
                    'favorite' => 'You can keep up to 200 favorites. Remove one before adding another.',
                ]);
            }

            if (!$state->exists()) {
                $state->insert([
                    'uid' => (string) Str::ulid(),
                    'user_uid' => $actor->uid,
                    'page_uid' => $page->uid,
                ]);
            }

            if ($favorite === null) {
                $state->update(['last_opened_at' => now()]);
                $expired = $this->states($actor)->whereNotNull('last_opened_at')
                    ->orderByDesc('last_opened_at')->orderByDesc('uid')
                    ->offset(self::RECENT_LIMIT)->pluck('uid');
                $this->states($actor)->whereIn('uid', $expired)->update(['last_opened_at' => null]);
            } elseif ($favorite) {
                $state->whereNull('favorited_at')->update(['favorited_at' => now()]);
            } else {
                $state->update(['favorited_at' => null]);
            }

            $this->removeEmpty($actor);
        }, 3);
    }

    private function lockActor(User $actor): void
    {
        // Serialize this user's private bounds without locking other users or
        // changing the application-wide page-before-workspace row-lock order.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['personal-navigation:' . $actor->uid]);
    }

    private function releaseInaccessibleFavorites(User $actor): void
    {
        $visibleUids = [];
        $pages = Page::query()->with('accessGrants')->whereIn(
            'uid',
            $this->states($actor)->whereNotNull('favorited_at')->select('page_uid'),
        )->get();

        foreach ($pages as $page) {
            if ($this->access->canView($actor, $page)) {
                $visibleUids[] = $page->uid;
            }
        }

        // Lost access must not trap the user at a cap they cannot manage in UI.
        $this->states($actor)->whereNotNull('favorited_at')->whereNotIn('page_uid', $visibleUids)
            ->update(['favorited_at' => null]);
    }

    private function states(User $actor): Builder
    {
        return DB::table('personal_page_states')->where('user_uid', $actor->uid);
    }

    private function removeEmpty(User $actor): void
    {
        $this->states($actor)->whereNull('last_opened_at')->whereNull('favorited_at')->delete();
    }
}
