<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Events\PagePresenceAccessRevoked;
use App\Models\Page;
use App\Models\User;

/**
 * Presence-channel authorization only runs at subscribe time, so access
 * changes must actively kick affected subscribers. Callers must invoke this
 * after the access-changing transaction has committed and the PageAccess
 * cache has been flushed, so the view checks observe the final state.
 *
 * Known residual (low; realtime is opt-in and off by default): the kick is a
 * client-cooperative broadcast, not a forced disconnect -- Reverb exposes no
 * server-initiated per-connection disconnect to application code. A revoked
 * member who ignores PagePresenceAccessRevoked keeps their already-open
 * subscription until the socket drops and can observe presence *identity
 * metadata* (uid + name) for that window. They cannot re-subscribe (auth now
 * fails) and cannot read page content: the presence payload is identity-only
 * (locked by ChannelAuthorizationConventionTest) and content is always fetched
 * over the authorized HTTP path. See THREAT-MODEL.md (Realtime presence).
 */
final readonly class PagePresenceRevoker
{
    public function __construct(
        private PageAccess $access,
    ) {
    }

    /**
     * @param iterable<User> $users
     */
    public function kickUsersWhoLostView(Page $page, iterable $users): void
    {
        foreach ($users as $user) {
            if (!$this->access->canView($user, $page)) {
                broadcast(new PagePresenceAccessRevoked(
                    pageUid: $page->uid,
                    userUid: $user->uid,
                ));
            }
        }
    }

    /**
     * @param iterable<Page> $pages
     */
    public function kickUserFromPagesWhereViewLost(User $user, iterable $pages): void
    {
        foreach ($pages as $page) {
            $this->kickUsersWhoLostView($page, [$user]);
        }
    }
}
