<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\PageStatus;
use App\Models\Page;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ArchivePage
{
    public function __construct(
        private PageAccess $access,
        private PageStatusChanger $statusChanger,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, ArchivePageCommand $command): Page
    {
        $page = PageFinder::requireByUid($command->pageUid);

        $authorize = function (Page $page) use ($actor): void {
            if (!$this->access->canArchive($actor, $page)) {
                throw new AuthorizationException('You cannot archive this page.');
            }
        };

        $authorize($page);

        if (!$command->confirmed) {
            throw new DomainRuleViolation('Confirm that archiving is reversible.');
        }

        return $this->statusChanger->change(
            actor: $actor,
            page: $page,
            newStatus: PageStatus::Archived,
            eventType: DomainEventType::PageArchived,
            summary: 'Page archived.',
            authorize: $authorize,
        );
    }
}
