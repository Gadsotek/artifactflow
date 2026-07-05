<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\InvalidPageStatusTransition;
use App\Domain\PageCatalog\PageStatus;
use App\Models\Page;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class RestoreDeprecatedPage
{
    public function __construct(
        private PageAccess $access,
        private PageStatusChanger $statusChanger,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, RestoreDeprecatedPageCommand $command): Page
    {
        $page = PageFinder::requireByUid($command->pageUid);

        $authorize = function (Page $page) use ($actor): void {
            if (!$this->access->canEdit($actor, $page)) {
                throw new AuthorizationException('You cannot edit this page.');
            }
        };

        $ensureTransitionAllowed = static function (Page $page): void {
            if ($page->status !== PageStatus::Deprecated) {
                throw new InvalidPageStatusTransition('Only deprecated pages can be restored to draft.');
            }
        };

        $authorize($page);

        if ($page->status === PageStatus::Draft) {
            return $page->refresh();
        }

        $ensureTransitionAllowed($page);

        return $this->statusChanger->change(
            actor: $actor,
            page: $page,
            newStatus: PageStatus::Draft,
            eventType: DomainEventType::PageDeprecationRestored,
            summary: 'Deprecated page restored to draft.',
            authorize: $authorize,
            ensureTransitionAllowed: $ensureTransitionAllowed,
        );
    }
}
