<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\PageStatus;
use App\Models\Page;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

final readonly class PageStatusChanger
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private PageSearchVectorUpdater $searchVectors,
        private PageAccess $access,
    ) {
    }

    /**
     * @param (Closure(Page): void)|null $authorize re-checked under the page row lock inside the
     *        transaction as the authorization of record. External lifecycle callers pass their
     *        authority here so a right revoked while the request waited for the lock still blocks
     *        the transition; PageVersionAppender's automatic content-driven transition passes null
     *        because its caller already reauthorized the edit under this same lock.
     * @param (Closure(Page): void)|null $ensureTransitionAllowed re-checked under the same lock: the
     *        source-status precondition (for example "only draft pages can be approved"). Callers
     *        also run it pre-lock as a fast fail, but the locked re-check is authoritative so a
     *        concurrent transition that committed while the request waited for the lock cannot be
     *        silently overwritten (for example archived -> approved).
     */
    public function change(
        User $actor,
        Page $page,
        PageStatus $newStatus,
        DomainEventType $eventType,
        string $summary,
        ?Closure $authorize = null,
        ?Closure $ensureTransitionAllowed = null,
    ): Page {
        if ($page->status === $newStatus) {
            return $page->refresh();
        }

        $actorUid = ActorId::fromUser($actor);

        return DB::transaction(function () use ($actorUid, $authorize, $ensureTransitionAllowed, $eventType, $newStatus, $page, $summary): Page {
            // Re-fetch under the row lock: the status mutation needs the lock so it cannot lose
            // an update to a concurrent transition, and external callers additionally pass
            // $authorize to re-check authority against fresh, un-cached state under that lock --
            // a pre-transaction check is only a fast fail served from the scoped cache.
            $lockedPage = $authorize === null
                ? PageFinder::requireLockedByUid($page->uid)
                : $this->access->lockAndReauthorize($page->uid, $authorize);

            if ($lockedPage->status === $newStatus) {
                return $lockedPage;
            }

            // Authoritative source-status re-check under the lock: a concurrent transition
            // (for example an archive) that committed while this request waited for the lock
            // must block the change here rather than be silently overwritten.
            if ($ensureTransitionAllowed !== null) {
                $ensureTransitionAllowed($lockedPage);
            }

            $previousStatus = $lockedPage->status;

            $lockedPage->forceFill(['status' => $newStatus])->save();
            $this->searchVectors->refreshPage($lockedPage->uid);
            $event = $this->events->record(
                eventType: $eventType,
                aggregateType: 'page',
                aggregateUid: $lockedPage->uid,
                payload: [
                    'page_uid' => $lockedPage->uid,
                    'workspace_uid' => $lockedPage->workspace_uid,
                    'previous_status' => $previousStatus->value,
                    'new_status' => $newStatus->value,
                    'changed_by_user_uid' => $actorUid,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'page',
                auditableUid: $lockedPage->uid,
                action: $eventType,
                summary: $summary,
                metadata: [
                    'workspace_uid' => $lockedPage->workspace_uid,
                    'previous_status' => $previousStatus->value,
                    'new_status' => $newStatus->value,
                ],
            );

            return $lockedPage->refresh();
        });
    }
}
