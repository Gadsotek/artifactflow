<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Application\Identity\WorkspaceCollaboratorDirectory;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class GrantPageAccess
{
    public function __construct(
        private PageAccess $access,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private PageAccessRevision $revisions,
        private WorkspaceCollaboratorDirectory $collaborators,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, GrantPageAccessCommand $command): PageAccessGrant
    {
        $actorUid = ActorId::fromUser($actor);

        $grant = DB::transaction(function () use ($actor, $actorUid, $command): PageAccessGrant {
            // Lock the page row so concurrent grants for the same subject serialize here
            // instead of racing the (page_uid, subject_type, subject_uid) unique index and
            // turning the loser into a 23505 -> 500. Reauthorize through the single
            // authorization of record (requireLockedByUid + a flushed authority cache): the
            // route/pre-transaction check is only a fast fail served from the request-scoped
            // cache, so an admin role or page grant revoked while this request waited for the
            // lock must still block the grant rather than pass on the stale cached decision.
            $page = $this->access->lockAndReauthorize($command->pageUid, function (Page $lockedPage) use ($actor, $command): void {
                $this->ensureCanGrant($actor, $lockedPage);
                $this->ensureRoleCanBeGranted($actor, $lockedPage, $command->role);
                $this->ensureSubjectExists($actor, $lockedPage, $command);
            });

            $existingGrant = PageAccessGrant::query()
                ->where('page_uid', $page->uid)
                ->where('subject_type', $command->subjectType)
                ->where('subject_uid', $command->subjectUid)
                ->first();

            if ($existingGrant instanceof PageAccessGrant) {
                if ($existingGrant->role === $command->role) {
                    return $existingGrant;
                }

                $previousRole = $existingGrant->role;
                $existingGrant->forceFill([
                    'role' => $command->role,
                    'granted_by_user_uid' => $actorUid,
                ])->save();

                $this->recordGrantEvent(
                    eventType: DomainEventType::PageAccessGrantUpdated,
                    action: DomainEventType::PageAccessGrantUpdated,
                    summary: 'Page access grant updated.',
                    page: $page,
                    grant: $existingGrant->refresh(),
                    actorUid: $actorUid,
                    previousRole: $previousRole,
                );
                $this->revisions->bump($page);

                return $existingGrant;
            }

            $grant = PageAccessGrant::query()->forceCreate([
                'page_uid' => $page->uid,
                'subject_type' => $command->subjectType,
                'subject_uid' => $command->subjectUid,
                'role' => $command->role,
                'granted_by_user_uid' => $actorUid,
            ]);

            $this->recordGrantEvent(
                eventType: DomainEventType::PageAccessGrantCreated,
                action: DomainEventType::PageAccessGrantCreated,
                summary: 'Page access grant created.',
                page: $page,
                grant: $grant,
                actorUid: $actorUid,
                previousRole: null,
            );
            $this->revisions->bump($page);

            return $grant;
        });

        $this->access->flushCache();

        return $grant;
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureCanGrant(User $actor, Page $page): void
    {
        try {
            $this->access->ensureCanManageAccess($actor, $page);
        } catch (AuthorizationException) {
            throw new AuthorizationException('You cannot grant access to this page.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureRoleCanBeGranted(User $actor, Page $page, WorkspaceRole $role): void
    {
        if ($role !== WorkspaceRole::Admin) {
            return;
        }

        if ($this->access->canHardDelete($actor, $page)) {
            return;
        }

        throw new AuthorizationException('Editors cannot grant page Admin access.');
    }

    private function ensureSubjectExists(User $actor, Page $page, GrantPageAccessCommand $command): void
    {
        if ($command->subjectType === PageAccessSubjectType::User) {
            $target = User::query()->where('uid', $command->subjectUid)->first();

            if (!$target instanceof User) {
                throw new DomainRuleViolation('User access grant subject does not exist.');
            }

            // Per-page user grants are internal-only: any registered human
            // coworker is discoverable and eligible for Reader or Editor access,
            // while service accounts, self-grants, and external/unknown identities
            // remain unavailable. Admin stays membership-scoped because it includes
            // access management and destructive page operations.
            // The submitted UID/email identifies the subject; page authority is
            // independently reauthorized under the page row lock above.
            if (!$this->collaborators->isEligibleCoworker($actor, $target)) {
                throw new PageAccessGrantTargetUnavailable(
                    'Page access grants are limited to registered human coworkers.',
                );
            }

            if ($command->role === WorkspaceRole::Admin && !$this->userBelongsToWorkspace(
                userUid: $command->subjectUid,
                workspaceUid: $page->workspace_uid,
            )) {
                throw new PageAccessGrantTargetUnavailable(
                    'Page Admin grants require the target user to belong to the page workspace.',
                );
            }

            return;
        }

        $workspace = Workspace::query()->find($command->subjectUid);

        if (!$workspace instanceof Workspace) {
            throw new DomainRuleViolation('Workspace access grant subject does not exist.');
        }

        if ($workspace->type !== WorkspaceType::Shared) {
            throw new DomainRuleViolation('Workspace access grant target must be a shared workspace.');
        }

        if ($workspace->uid === $page->workspace_uid) {
            throw new DomainRuleViolation(
                'Use workspace inheritance instead of granting the page workspace to itself.',
            );
        }

        if ($command->role !== WorkspaceRole::Reader) {
            throw new DomainRuleViolation('Workspace access grants are limited to Reader access.');
        }

        if (!$this->userBelongsToWorkspace($actor->uid, $workspace->uid)) {
            throw new DomainRuleViolation(
                'Workspace access grant target must be a workspace you belong to.',
            );
        }
    }

    private function userBelongsToWorkspace(string $userUid, string $workspaceUid): bool
    {
        return WorkspaceMembership::query()
            ->where('workspace_uid', $workspaceUid)
            ->where('user_uid', $userUid)
            ->exists();
    }

    private function recordGrantEvent(
        DomainEventType $eventType,
        DomainEventType $action,
        string $summary,
        Page $page,
        PageAccessGrant $grant,
        string $actorUid,
        ?WorkspaceRole $previousRole,
    ): void {
        $event = $this->events->record(
            eventType: $eventType,
            aggregateType: 'page',
            aggregateUid: $page->uid,
            payload: [
                'page_uid' => $page->uid,
                'page_access_grant_uid' => $grant->uid,
                'subject_type' => $grant->subject_type->value,
                'subject_uid' => $grant->subject_uid,
                'role' => $grant->role->value,
                'previous_role' => $previousRole?->value,
                'granted_by_user_uid' => $actorUid,
            ],
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUid,
            auditableType: 'page_access_grant',
            auditableUid: $grant->uid,
            action: $action,
            summary: $summary,
            metadata: [
                'page_uid' => $page->uid,
                'subject_type' => $grant->subject_type->value,
                'subject_uid' => $grant->subject_uid,
                'role' => $grant->role->value,
                'previous_role' => $previousRole?->value,
            ],
        );
    }
}
