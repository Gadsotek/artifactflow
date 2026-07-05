<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\InvalidPageStatusTransition;
use App\Domain\PageCatalog\PageStatus;
use App\Models\Page;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class DeprecatePage
{
    public function __construct(
        private PageAccess $access,
        private PageStatusChanger $statusChanger,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, DeprecatePageCommand $command): Page
    {
        $page = PageFinder::requireByUid($command->pageUid);

        $authorize = function (Page $page) use ($actor): void {
            if (!$this->access->canEdit($actor, $page)) {
                throw new AuthorizationException('You cannot edit this page.');
            }
        };

        $ensureTransitionAllowed = static function (Page $page): void {
            if ($page->status !== PageStatus::Approved) {
                throw new InvalidPageStatusTransition('Only approved pages can be deprecated.');
            }
        };

        $authorize($page);

        if ($page->status === PageStatus::Deprecated) {
            return $page->refresh();
        }

        $ensureTransitionAllowed($page);

        return $this->statusChanger->change(
            actor: $actor,
            page: $page,
            newStatus: PageStatus::Deprecated,
            eventType: DomainEventType::PageDeprecated,
            summary: 'Page deprecated.',
            authorize: $authorize,
            ensureTransitionAllowed: $ensureTransitionAllowed,
        );
    }
}
