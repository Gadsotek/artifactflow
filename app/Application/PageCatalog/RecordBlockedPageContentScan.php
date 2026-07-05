<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class RecordBlockedPageContentScan
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @param list<string> $findingCodes
     */
    public function forPageCreation(
        User $actor,
        Workspace $workspace,
        PageType $pageType,
        array $findingCodes,
        string $operation = 'create_page',
    ): void {
        $this->record(
            actorUid: $this->userUid($actor),
            aggregateType: 'workspace',
            aggregateUid: $workspace->uid,
            auditableType: 'workspace',
            auditableUid: $workspace->uid,
            workspaceUid: $workspace->uid,
            pageUid: null,
            pageType: $pageType,
            operation: $operation,
            findingCodes: $findingCodes,
        );
    }

    /**
     * @param list<string> $findingCodes
     */
    public function forPageVersion(User $actor, Page $page, array $findingCodes): void
    {
        $this->record(
            actorUid: $this->userUid($actor),
            aggregateType: 'page',
            aggregateUid: $page->uid,
            auditableType: 'page',
            auditableUid: $page->uid,
            workspaceUid: $page->workspace_uid,
            pageUid: $page->uid,
            pageType: $page->type,
            operation: 'create_version',
            findingCodes: $findingCodes,
        );
    }

    /**
     * @param list<string> $findingCodes
     */
    public function forPageMetadata(User $actor, Page $page, array $findingCodes): void
    {
        $this->record(
            actorUid: $this->userUid($actor),
            aggregateType: 'page',
            aggregateUid: $page->uid,
            auditableType: 'page',
            auditableUid: $page->uid,
            workspaceUid: $page->workspace_uid,
            pageUid: $page->uid,
            pageType: $page->type,
            operation: 'update_metadata_description',
            findingCodes: $findingCodes,
        );
    }

    /**
     * @param list<string> $findingCodes
     */
    private function record(
        string $actorUid,
        string $aggregateType,
        string $aggregateUid,
        string $auditableType,
        string $auditableUid,
        string $workspaceUid,
        ?string $pageUid,
        PageType $pageType,
        string $operation,
        array $findingCodes,
    ): void {
        $codes = implode(',', array_values(array_unique($findingCodes)));

        DB::transaction(function () use (
            $actorUid,
            $aggregateType,
            $aggregateUid,
            $auditableType,
            $auditableUid,
            $codes,
            $operation,
            $pageType,
            $pageUid,
            $workspaceUid,
        ): void {
            $event = $this->events->record(
                eventType: DomainEventType::PageSecretScanBlocked,
                aggregateType: $aggregateType,
                aggregateUid: $aggregateUid,
                payload: [
                    'workspace_uid' => $workspaceUid,
                    'page_uid' => $pageUid,
                    'actor_user_uid' => $actorUid,
                    'page_type' => $pageType->value,
                    'operation' => $operation,
                    'finding_codes' => $codes,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: $auditableType,
                auditableUid: $auditableUid,
                action: DomainEventType::PageSecretScanBlocked,
                summary: 'Page content blocked by secret scan.',
                metadata: [
                    'workspace_uid' => $workspaceUid,
                    'page_uid' => $pageUid,
                    'page_type' => $pageType->value,
                    'operation' => $operation,
                    'finding_codes' => $codes,
                ],
            );
        });
    }

    private function userUid(User $user): string
    {
        $userUid = $user->getKey();

        if (!is_string($userUid) || $userUid === '') {
            throw new LogicException('Cannot record a blocked scan for an unsaved user.');
        }

        return $userUid;
    }
}
