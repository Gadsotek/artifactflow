<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Mcp\McpEffectiveAuthority;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipRemoval;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final class PageAccess
{
    /**
     * @var array<string, WorkspaceRole|null>
     */
    private array $workspaceRoleCache = [];

    /**
     * @var array<string, list<string>>
     */
    private array $workspaceUidCache = [];

    /**
     * @var array<string, WorkspaceRole|null>
     */
    private array $pageGrantRoleCache = [];

    /**
     * @var array<string, CarbonImmutable|null>
     */
    private array $membershipRemovalCache = [];

    /**
     * @var array<string, bool>
     */
    private array $editorPageSharingCache = [];

    public function __construct(
        private readonly McpEffectiveAuthority $mcpAuthority,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function ensureCanCreateInWorkspace(User $user, string $workspaceUid): void
    {
        if (!$this->canCreateInWorkspace($user, $workspaceUid)) {
            throw new AuthorizationException('You cannot create pages in this workspace.');
        }
    }

    public function canCreateInWorkspace(User $user, string $workspaceUid): bool
    {
        $role = $this->workspaceRole($user, $workspaceUid);

        return $role?->canWritePages() ?? false;
    }

    /**
     * @throws AuthorizationException
     */
    public function ensureCanView(User $user, Page $page): void
    {
        if (!$this->canView($user, $page)) {
            throw new AuthorizationException('You cannot view this page.');
        }
    }

    public function canView(User $user, Page $page): bool
    {
        if (!$this->mcpAuthority->workspaceAllowed($page->workspace_uid)) {
            return false;
        }

        $workspaceRole = $this->workspaceRole($user, $page->workspace_uid);

        if ($workspaceRole === WorkspaceRole::Admin) {
            return true;
        }

        if ($this->ownerWorkspaceRole($user, $page) instanceof WorkspaceRole) {
            return true;
        }

        if ($page->access_mode === PageAccessMode::Inherited && $workspaceRole instanceof WorkspaceRole) {
            return true;
        }

        return $this->bestPageGrantRole($user, $page) instanceof WorkspaceRole;
    }

    public function canEdit(User $user, Page $page): bool
    {
        if (!$this->mcpAuthority->workspaceAllowed($page->workspace_uid)) {
            return false;
        }

        $workspaceRole = $this->workspaceRole($user, $page->workspace_uid);

        if ($workspaceRole === WorkspaceRole::Admin) {
            return true;
        }

        if ($this->ownerCanWritePages($user, $page)) {
            return true;
        }

        if ($page->access_mode === PageAccessMode::Inherited && $workspaceRole === WorkspaceRole::Editor) {
            return true;
        }

        $grantRole = $this->bestPageGrantRole($user, $page);

        return $grantRole === WorkspaceRole::Admin || $grantRole === WorkspaceRole::Editor;
    }

    public function canHardDelete(User $user, Page $page): bool
    {
        if (!$this->mcpAuthority->adminClassCapabilitiesAllowed()) {
            return false;
        }

        if ($this->workspaceRole($user, $page->workspace_uid) === WorkspaceRole::Admin) {
            return true;
        }

        return $this->bestPageGrantRole($user, $page) === WorkspaceRole::Admin;
    }

    public function canArchive(User $user, Page $page): bool
    {
        return $this->canAdministerPage($user, $page);
    }

    /**
     * @throws AuthorizationException
     */
    public function ensureCanManageAccess(User $user, Page $page): void
    {
        if (!$this->canManageAccess($user, $page)) {
            throw new AuthorizationException('You cannot manage access to this page.');
        }
    }

    public function canManageAccess(User $user, Page $page): bool
    {
        if (!$this->mcpAuthority->adminClassCapabilitiesAllowed()) {
            return false;
        }

        $workspaceRole = $this->workspaceRole($user, $page->workspace_uid);

        if ($workspaceRole === WorkspaceRole::Admin) {
            return true;
        }

        $grantRole = $this->bestPageGrantRole($user, $page);

        if ($grantRole === WorkspaceRole::Admin) {
            return true;
        }

        if (!$this->workspaceAllowsEditorPageSharing($page->workspace_uid)) {
            return false;
        }

        return $this->ownerCanWritePages($user, $page);
    }

    /**
     * @throws AuthorizationException
     */
    public function ensureCanChangeAccessMode(User $user, Page $page): void
    {
        if (!$this->canChangeAccessMode($user, $page)) {
            throw new AuthorizationException('You cannot change access mode for this page.');
        }
    }

    public function canChangeAccessMode(User $user, Page $page): bool
    {
        return $this->canAdministerPage($user, $page);
    }

    /**
     * @throws AuthorizationException
     */
    public function ensureCanTransferOwnership(User $user, Page $page): void
    {
        if (!$this->canTransferOwnership($user, $page)) {
            throw new AuthorizationException('You cannot transfer page ownership.');
        }
    }

    public function canTransferOwnership(User $user, Page $page): bool
    {
        return $this->canAdministerPage($user, $page);
    }

    public function workspaceRole(User $user, string $workspaceUid): ?WorkspaceRole
    {
        $cacheKey = $this->authorityCachePrefix() . $user->uid . ':' . $workspaceUid;

        if (array_key_exists($cacheKey, $this->workspaceRoleCache)) {
            return $this->workspaceRoleCache[$cacheKey];
        }

        if (!$this->mcpAuthority->workspaceAllowed($workspaceUid)) {
            $this->workspaceRoleCache[$cacheKey] = null;

            return null;
        }

        $membership = WorkspaceMembership::query()
            ->where('workspace_uid', $workspaceUid)
            ->where('user_uid', $user->uid)
            ->first();

        $role = $this->mcpAuthority->workspaceRole($membership instanceof WorkspaceMembership ? $membership->role : null);
        $this->workspaceRoleCache[$cacheKey] = $role;

        return $role;
    }

    public function flushCache(): void
    {
        $this->workspaceRoleCache = [];
        $this->workspaceUidCache = [];
        $this->pageGrantRoleCache = [];
        $this->membershipRemovalCache = [];
        $this->editorPageSharingCache = [];
    }

    /**
     * The single authorization of record for a page mutation. Re-fetch the page under a row
     * lock, discard this request's cached authority, and re-run $authorize against fresh
     * state. A pre-transaction authorization is only a fast fail served from the scoped
     * cache; a membership, grant, or role revoked while the request waited for the lock must
     * still block the write. Every mutation runs this first inside its transaction and then
     * mutates the returned locked page, so authority can never be decided against a snapshot
     * taken before the lock was held.
     *
     * @param Closure(Page): void $authorize throws when the actor may no longer perform the mutation
     *
     * @throws AuthorizationException
     */
    public function lockAndReauthorize(string $pageUid, Closure $authorize): Page
    {
        $page = PageFinder::requireLockedByUid($pageUid);
        $this->flushCache();
        $authorize($page);

        return $page;
    }

    private function canAdministerPage(User $user, Page $page): bool
    {
        if (!$this->mcpAuthority->adminClassCapabilitiesAllowed()) {
            return false;
        }

        $workspaceRole = $this->workspaceRole($user, $page->workspace_uid);

        if ($this->ownerCanWritePages($user, $page)) {
            return true;
        }

        if ($workspaceRole === WorkspaceRole::Admin) {
            return true;
        }

        return $this->bestPageGrantRole($user, $page) === WorkspaceRole::Admin;
    }

    private function ownerCanWritePages(User $user, Page $page): bool
    {
        return $this->ownerWorkspaceRole($user, $page)?->canWritePages() === true;
    }

    private function ownerWorkspaceRole(User $user, Page $page): ?WorkspaceRole
    {
        if ($page->owner_user_uid !== $user->uid) {
            return null;
        }

        return $this->workspaceRole($user, $page->workspace_uid);
    }

    private function bestPageGrantRole(User $user, Page $page): ?WorkspaceRole
    {
        $cacheKey = $this->authorityCachePrefix() . $user->uid . ':' . $page->uid;

        if (array_key_exists($cacheKey, $this->pageGrantRoleCache)) {
            return $this->pageGrantRoleCache[$cacheKey];
        }

        $workspaceRole = $this->workspaceRole($user, $page->workspace_uid);
        $userWorkspaceUids = $this->workspaceUidsFor($user);
        $bestRole = null;
        $bestRank = 0;

        foreach ($this->applicableGrants($user, $page, $userWorkspaceUids) as $grant) {
            $role = $this->effectiveGrantRole(
                user: $user,
                grant: $grant,
                page: $page,
                pageWorkspaceRole: $workspaceRole,
            );

            if (!$role instanceof WorkspaceRole) {
                continue;
            }

            $rank = $role->rank();

            if ($rank > $bestRank) {
                $bestRole = $role;
                $bestRank = $rank;
            }
        }

        $this->pageGrantRoleCache[$cacheKey] = $bestRole;

        return $bestRole;
    }

    /**
     * Grants on the page that could apply to this user. Uses the eager-loaded
     * accessGrants relation when present so a search over many pages does not
     * issue one grant query per row; otherwise falls back to a scoped query.
     *
     * @param list<string> $userWorkspaceUids
     *
     * @return iterable<PageAccessGrant>
     */
    private function applicableGrants(User $user, Page $page, array $userWorkspaceUids): iterable
    {
        if ($page->relationLoaded('accessGrants')) {
            return $page->accessGrants->filter(
                fn (PageAccessGrant $grant): bool => $this->grantSubjectMatchesUser($grant, $user, $userWorkspaceUids),
            );
        }

        return PageAccessGrant::query()
            ->where('page_uid', $page->uid)
            ->where(function (Builder $query) use ($user, $userWorkspaceUids): void {
                $query->where(function (Builder $query) use ($user): void {
                    $query->where('subject_type', PageAccessSubjectType::User)
                        ->where('subject_uid', $user->uid);
                })->orWhere(function (Builder $query) use ($userWorkspaceUids): void {
                    $query->where('subject_type', PageAccessSubjectType::Workspace)
                        ->whereIn('subject_uid', $userWorkspaceUids);
                });
            })
            ->get();
    }

    /**
     * @param list<string> $userWorkspaceUids
     */
    private function grantSubjectMatchesUser(PageAccessGrant $grant, User $user, array $userWorkspaceUids): bool
    {
        if ($grant->subject_type === PageAccessSubjectType::User) {
            return $grant->subject_uid === $user->uid;
        }

        return in_array($grant->subject_uid, $userWorkspaceUids, true);
    }

    private function effectiveGrantRole(
        User $user,
        PageAccessGrant $grant,
        Page $page,
        ?WorkspaceRole $pageWorkspaceRole,
    ): ?WorkspaceRole {
        if (!$this->grantAppliesToUser($grant, $page, $pageWorkspaceRole)) {
            return null;
        }

        $role = $grant->role;

        if ($grant->subject_type === PageAccessSubjectType::Workspace) {
            $grantedWorkspaceRole = $this->workspaceRole($user, $grant->subject_uid);

            if (!$grantedWorkspaceRole instanceof WorkspaceRole) {
                return null;
            }

            $role = $this->lowerRole($role, $grantedWorkspaceRole);
        }

        return $this->mcpAuthority->pageGrantRole($role);
    }

    private function grantAppliesToUser(
        PageAccessGrant $grant,
        Page $page,
        ?WorkspaceRole $pageWorkspaceRole,
    ): bool {
        if ($grant->subject_type !== PageAccessSubjectType::User) {
            return true;
        }

        if ($pageWorkspaceRole instanceof WorkspaceRole) {
            return true;
        }

        if ($this->grantPredatesWorkspaceMembershipRemoval($grant, $page)) {
            return false;
        }

        return true;
    }

    private function lowerRole(WorkspaceRole $first, WorkspaceRole $second): WorkspaceRole
    {
        return $first->rank() <= $second->rank() ? $first : $second;
    }

    private function grantPredatesWorkspaceMembershipRemoval(PageAccessGrant $grant, Page $page): bool
    {
        $removedAt = $this->membershipRemovalTime($page->workspace_uid, $grant->subject_uid);

        return $removedAt instanceof CarbonImmutable
            && $removedAt->greaterThanOrEqualTo($grant->created_at);
    }

    /**
     * When the user was last removed from the workspace, memoized so the
     * post-LIMIT canView() re-filter in PageSearch does not repeat the lookup
     * for every grant on every candidate page. (workspace_uid, user_uid) is
     * unique on workspace_membership_removals, so one row is authoritative.
     */
    private function membershipRemovalTime(string $workspaceUid, string $userUid): ?CarbonImmutable
    {
        $cacheKey = $workspaceUid . ':' . $userUid;

        if (array_key_exists($cacheKey, $this->membershipRemovalCache)) {
            return $this->membershipRemovalCache[$cacheKey];
        }

        $removal = WorkspaceMembershipRemoval::query()
            ->where('workspace_uid', $workspaceUid)
            ->where('user_uid', $userUid)
            ->first();

        $removedAt = $removal instanceof WorkspaceMembershipRemoval ? $removal->removed_at : null;
        $this->membershipRemovalCache[$cacheKey] = $removedAt;

        return $removedAt;
    }

    /**
     * Whether the workspace allows editors to share their own pages, memoized
     * per workspace because canManageAccess() may run once per page in a
     * result set. A missing workspace never allows sharing.
     */
    private function workspaceAllowsEditorPageSharing(string $workspaceUid): bool
    {
        if (array_key_exists($workspaceUid, $this->editorPageSharingCache)) {
            return $this->editorPageSharingCache[$workspaceUid];
        }

        $workspace = Workspace::query()->find($workspaceUid);
        $allowsSharing = $workspace instanceof Workspace && $workspace->allow_editor_page_sharing;
        $this->editorPageSharingCache[$workspaceUid] = $allowsSharing;

        return $allowsSharing;
    }

    /**
     * @return list<string>
     */
    private function workspaceUidsFor(User $user): array
    {
        $cacheKey = $this->authorityCachePrefix() . $user->uid;

        if (array_key_exists($cacheKey, $this->workspaceUidCache)) {
            return $this->workspaceUidCache[$cacheKey];
        }

        $workspaceUids = WorkspaceMembership::query()
            ->where('user_uid', $user->uid)
            ->get(['workspace_uid'])
            ->map(static fn (WorkspaceMembership $membership): string => $membership->workspace_uid)
            ->values()
            ->all();

        $workspaceUids = $this->mcpAuthority->filterWorkspaceUids(array_values($workspaceUids));
        $this->workspaceUidCache[$cacheKey] = $workspaceUids;

        return $workspaceUids;
    }

    private function authorityCachePrefix(): string
    {
        return $this->mcpAuthority->cacheKeyPrefix();
    }
}
