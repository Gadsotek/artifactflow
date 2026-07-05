<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Identity\ActorId;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class RevokePageAccess
{
    public function __construct(
        private PageAccess $access,
        private PageAccessGrantRevocationJournal $revocationJournal,
        private PageAccessRevision $revisions,
        private PagePresenceRevoker $presence,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, RevokePageAccessCommand $command): bool
    {
        $actorUid = ActorId::fromUser($actor);
        $page = PageFinder::requireByUid($command->pageUid);

        $revoked = DB::transaction(function () use ($actor, $actorUid, $command): bool {
            // Re-fetch under the page row lock and re-authorize against fresh, un-cached
            // authority: a manage-access right revoked while this request waited for the lock
            // must still block the revoke, and the lock serialises it against a concurrent
            // grant change so authority cannot slip between the check and the removal.
            $page = $this->access->lockAndReauthorize($command->pageUid, function (Page $lockedPage) use ($actor): void {
                $this->access->ensureCanManageAccess($actor, $lockedPage);
            });

            $grant = PageAccessGrant::query()
                ->where('page_uid', $page->uid)
                ->where('subject_type', $command->subjectType)
                ->where('subject_uid', $command->subjectUid)
                ->first();

            if (!$grant instanceof PageAccessGrant) {
                return false;
            }

            if ($grant->role === WorkspaceRole::Admin && !$this->access->canHardDelete($actor, $page)) {
                throw new AuthorizationException('Editors cannot revoke page Admin access.');
            }

            $grantUid = $grant->uid;
            $subjectType = $grant->subject_type;
            $subjectUid = $grant->subject_uid;
            $role = $grant->role;
            $grant->delete();

            $this->revocationJournal->record(
                pageUid: $page->uid,
                grantUid: $grantUid,
                subjectType: $subjectType,
                subjectUid: $subjectUid,
                role: $role,
                actorUid: $actorUid,
                summary: 'Page access grant revoked.',
            );
            $this->revisions->bump($page);

            return true;
        });

        if ($revoked) {
            $this->access->flushCache();
            $this->presence->kickUsersWhoLostView($page, $this->subjectUsers($command));
        }

        return $revoked;
    }

    /**
     * @return iterable<int, User>
     */
    private function subjectUsers(RevokePageAccessCommand $command): iterable
    {
        if ($command->subjectType === PageAccessSubjectType::User) {
            $user = User::query()->find($command->subjectUid);

            return $user instanceof User ? [$user] : [];
        }

        return User::query()
            ->whereIn('uid', WorkspaceMembership::query()
                ->select('user_uid')
                ->where('workspace_uid', $command->subjectUid))
            ->orderBy('uid')
            ->get();
    }
}
