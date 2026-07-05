<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\Events\DomainEventType;
use App\Models\Page;

/**
 * Single place that journals a page ownership transfer, so every handler that
 * reassigns a page owner (metadata update, workspace move, member removal)
 * produces an identical event and audit shape, differing only in reason.
 */
final readonly class PageOwnershipTransferRecorder
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    public function record(
        Page $page,
        string $previousOwnerUserUid,
        string $newOwnerUserUid,
        string $actorUid,
        string $reason,
        string $summary = 'Page ownership transferred.',
    ): void {
        $event = $this->events->record(
            eventType: DomainEventType::PageOwnershipTransferred,
            aggregateType: 'page',
            aggregateUid: $page->uid,
            payload: [
                'page_uid' => $page->uid,
                'workspace_uid' => $page->workspace_uid,
                'previous_owner_user_uid' => $previousOwnerUserUid,
                'new_owner_user_uid' => $newOwnerUserUid,
                'transferred_by_user_uid' => $actorUid,
                'reason' => $reason,
            ],
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUid,
            auditableType: 'page',
            auditableUid: $page->uid,
            action: DomainEventType::PageOwnershipTransferred,
            summary: $summary,
            metadata: [
                'workspace_uid' => $page->workspace_uid,
                'previous_owner_user_uid' => $previousOwnerUserUid,
                'new_owner_user_uid' => $newOwnerUserUid,
                'reason' => $reason,
            ],
        );
    }
}
