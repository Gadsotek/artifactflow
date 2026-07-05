<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\PageAccessMode;
use App\Models\Page;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class UpdatePageAccessMode
{
    public function __construct(
        private PageAccess $access,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private PageAccessRevision $revisions,
        private PagePresenceRevoker $presence,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, UpdatePageAccessModeCommand $command): Page
    {
        $actorUid = ActorId::fromUser($actor);

        /** @var array{page: Page, changed: bool} $result */
        $result = DB::transaction(function () use ($actor, $actorUid, $command): array {
            // Reauthorize under the page row lock through the single authorization of record:
            // the route/pre-transaction check is only a fast fail served from the
            // request-scoped cache, so an admin role revoked while this request waited for the
            // lock must still block the access-mode change rather than pass on a stale decision.
            $page = $this->access->lockAndReauthorize($command->pageUid, function (Page $lockedPage) use ($actor): void {
                $this->access->ensureCanChangeAccessMode($actor, $lockedPage);
            });

            if ($page->access_mode === $command->accessMode) {
                return ['page' => $page, 'changed' => false];
            }

            $previousAccessMode = $page->access_mode;
            $page->forceFill([
                'access_mode' => $command->accessMode,
            ])->save();
            $this->revisions->bump($page);

            $event = $this->events->record(
                eventType: DomainEventType::PageAccessModeUpdated,
                aggregateType: 'page',
                aggregateUid: $page->uid,
                payload: [
                    'page_uid' => $page->uid,
                    'previous_access_mode' => $previousAccessMode->value,
                    'new_access_mode' => $command->accessMode->value,
                    'updated_by_user_uid' => $actorUid,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'page',
                auditableUid: $page->uid,
                action: DomainEventType::PageAccessModeUpdated,
                summary: 'Page access mode updated.',
                metadata: [
                    'previous_access_mode' => $previousAccessMode->value,
                    'new_access_mode' => $command->accessMode->value,
                ],
            );

            return ['page' => $page->refresh(), 'changed' => true];
        });

        $this->access->flushCache();

        $updatedPage = $result['page'];

        if ($result['changed'] && $command->accessMode === PageAccessMode::Restricted) {
            $this->presence->kickUsersWhoLostView($updatedPage, $this->workspaceMembers($updatedPage->workspace_uid));
        }

        return $updatedPage;
    }

    /**
     * @return iterable<int, User>
     */
    private function workspaceMembers(string $workspaceUid): iterable
    {
        return User::query()
            ->whereIn('uid', WorkspaceMembership::query()
                ->select('user_uid')
                ->where('workspace_uid', $workspaceUid))
            ->orderBy('uid')
            ->get();
    }
}
