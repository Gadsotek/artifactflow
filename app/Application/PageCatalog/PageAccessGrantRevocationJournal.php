<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;

final readonly class PageAccessGrantRevocationJournal
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    public function record(
        string $pageUid,
        string $grantUid,
        PageAccessSubjectType $subjectType,
        string $subjectUid,
        WorkspaceRole $role,
        string $actorUid,
        string $summary,
        ?string $reason = null,
    ): void {
        $eventPayload = [
            'page_uid' => $pageUid,
            'page_access_grant_uid' => $grantUid,
            'subject_type' => $subjectType->value,
            'subject_uid' => $subjectUid,
            'role' => $role->value,
            'revoked_by_user_uid' => $actorUid,
        ];

        if ($reason !== null) {
            $eventPayload['reason'] = $reason;
        }

        $auditMetadata = $eventPayload;
        unset($auditMetadata['page_access_grant_uid'], $auditMetadata['revoked_by_user_uid']);

        $event = $this->events->record(
            eventType: DomainEventType::PageAccessGrantRevoked,
            aggregateType: 'page',
            aggregateUid: $pageUid,
            payload: $eventPayload,
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUid,
            auditableType: 'page_access_grant',
            auditableUid: $grantUid,
            action: DomainEventType::PageAccessGrantRevoked,
            summary: $summary,
            metadata: $auditMetadata,
        );
    }
}
