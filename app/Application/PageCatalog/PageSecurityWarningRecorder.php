<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\Events\DomainEventType;
use App\Models\Page;
use App\Models\PageVersion;

final readonly class PageSecurityWarningRecorder
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    public function record(Page $page, PageVersion $version, string $actorUid, ContentSecurityScan $scan): void
    {
        $warningCodes = implode(',', $scan->warningCodes());

        $event = $this->events->record(
            eventType: DomainEventType::PageSecurityWarningsRecorded,
            aggregateType: 'page',
            aggregateUid: $page->uid,
            payload: [
                'page_uid' => $page->uid,
                'page_version_uid' => $version->uid,
                'created_by_user_uid' => $actorUid,
                'warning_codes' => $warningCodes,
            ],
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUid,
            auditableType: 'page_version',
            auditableUid: $version->uid,
            action: DomainEventType::PageSecurityWarningsRecorded,
            summary: 'Page security warnings recorded.',
            metadata: [
                'page_uid' => $page->uid,
                'warning_codes' => $warningCodes,
            ],
        );
    }
}
