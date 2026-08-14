<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\EffectiveWorkspaceSettingsResolver;
use App\Application\Identity\WorkspaceAccess;
use App\Application\Identity\WorkspaceContext;
use App\Application\Identity\WorkspaceInvitationOverview;
use App\Application\Identity\WorkspaceMemberOverview;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\PageHierarchyPresenter;
use App\Application\PageCatalog\PageSearch;
use App\Application\PageCatalog\PageSearchFilters;
use App\Application\PageCatalog\PageSearchResult;
use App\Application\PageCatalog\PageSearchSort;
use App\Application\PageCatalog\SummarizeDashboardDiscovery;
use App\Domain\Identity\WorkspaceRole;
use App\Models\Category;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DashboardController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function __construct(
        private readonly PageSearch $pageSearch,
        private readonly PageHierarchyPresenter $hierarchyPresenter,
        private readonly PageAccess $pageAccess,
        private readonly SummarizeDashboardDiscovery $summarizeDiscovery,
        private readonly WorkspaceInvitationOverview $workspaceInvitations,
        private readonly WorkspaceMemberOverview $workspaceMembers,
        private readonly WorkspaceContext $workspaceContext,
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly EffectiveWorkspaceSettingsResolver $workspaceSettings,
    ) {
    }

    public function __invoke(Request $request): View
    {
        $user = $this->authenticatedUser($request);

        $workspaceItems = $this->workspaceContext->itemsFor($user);
        $currentWorkspaceUid = $this->workspaceContext->resolveCurrentWorkspaceUid($request, $workspaceItems, false);
        $currentWorkspace = $currentWorkspaceUid === null
            ? null
            : Workspace::query()->find($currentWorkspaceUid);
        $canManageCurrentWorkspace = $this->workspaceMembers->canManageWorkspace(
            $user,
            $currentWorkspaceUid,
        );
        $workspaceParentOptions = $this->workspaceContext->parentItemsFor($user);
        $workspaceReparentOptions = $currentWorkspaceUid === null
            ? []
            : $this->workspaceContext->parentItemsFor($user, $currentWorkspaceUid);
        $canManageCurrentWorkspaceHierarchy = $canManageCurrentWorkspace
            && $this->canManageCurrentParent($user, $currentWorkspace);
        $pageResults = $this->pageResultsFor($user, $currentWorkspaceUid);
        $pages = array_map(
            static fn (PageSearchResult $result): Page => $result->page,
            $pageResults,
        );
        $workspaceMemberPage = $this->workspaceMembers->forWorkspace(
            $user,
            $currentWorkspaceUid,
            $request->integer('members_page', 1),
        );

        return view('dashboard', [
            'activeWorkspaceTab' => $this->activeWorkspaceTab($request),
            'user' => $user,
            'workspaces' => $workspaceItems,
            'currentWorkspaceUid' => $currentWorkspaceUid,
            'currentWorkspace' => $currentWorkspace,
            'canInviteToCurrentWorkspace' => $this->workspaceInvitations->canInviteToWorkspace(
                $user,
                $currentWorkspaceUid,
            ),
            'workspaceInvitationRoles' => $this->workspaceInvitations->allowedInvitationRoles(
                $user,
                $currentWorkspaceUid,
            ),
            'workspaceMembershipRoles' => WorkspaceRole::cases(),
            'canManageCurrentWorkspaceMembers' => $canManageCurrentWorkspace,
            'canManageCurrentWorkspaceSettings' => $canManageCurrentWorkspace,
            'canManageCurrentWorkspaceHierarchy' => $canManageCurrentWorkspaceHierarchy,
            'workspaceParentOptions' => $workspaceParentOptions,
            'workspaceReparentOptions' => $workspaceReparentOptions,
            'workspaceHierarchyPreview' => $this->workspaceHierarchyPreview($request, $currentWorkspaceUid),
            'effectiveWorkspaceSettings' => $currentWorkspace instanceof Workspace
                ? $this->workspaceSettings->resolve($currentWorkspace->uid)
                : null,
            'canCreateCategoriesInCurrentWorkspace' => $currentWorkspaceUid !== null
                && $this->pageAccess->canCreateInWorkspace($user, $currentWorkspaceUid),
            'canSeedDemoContent' => $pages === []
                && $currentWorkspace?->personal_owner_uid === $user->uid,
            'categories' => $this->categoriesFor($currentWorkspaceUid),
            'discoverySummary' => $this->summarizeDiscovery->handle($pages),
            'pendingInvitations' => $this->workspaceInvitations->pendingForUser($user),
            'workspaceInvitations' => $this->workspaceInvitations->pendingForWorkspaceAdmin(
                $user,
                $currentWorkspaceUid,
            ),
            'workspaceMemberPage' => $workspaceMemberPage,
            'workspaceMembers' => $workspaceMemberPage->items,
            'pages' => $pages,
            'pageHierarchyItems' => $this->hierarchyPresenter->arrange($user, $pageResults),
        ]);
    }

    private function activeWorkspaceTab(Request $request): string
    {
        $requestedTab = $request->string('tab')->toString();

        return in_array($requestedTab, ['overview', 'members', 'settings'], true)
            ? $requestedTab
            : 'overview';
    }

    /**
     * @return list<Category>
     */
    private function categoriesFor(?string $workspaceUid): array
    {
        if ($workspaceUid === null) {
            return [];
        }

        $categories = Category::query()
            ->where('workspace_uid', $workspaceUid)
            ->orderBy('name')
            ->get();
        $result = [];

        foreach ($categories as $category) {
            $result[] = $category;
        }

        return $result;
    }

    /**
     * @return list<PageSearchResult>
     */
    private function pageResultsFor(User $user, ?string $workspaceUid): array
    {
        if ($workspaceUid === null) {
            return [];
        }

        return $this->pageSearch->search($user, new PageSearchFilters(
            query: null,
            workspaceUid: $workspaceUid,
            type: null,
            statuses: PageSearchFilters::activeStatuses(),
            categoryUids: [],
            tagUids: [],
            ownerUserUid: null,
            sort: PageSearchSort::RecentlyUpdated,
        ));
    }

    private function canManageCurrentParent(User $user, ?Workspace $workspace): bool
    {
        if (!$workspace instanceof Workspace || $workspace->parent_workspace_uid === null) {
            return true;
        }

        return $this->workspaceAccess->role($user->uid, $workspace->parent_workspace_uid) === WorkspaceRole::Admin;
    }

    /**
     * @return array{
     *     preview_id: string,
     *     workspace_uid: string,
     *     new_parent_workspace_uid: string|null,
     *     moved_workspace_count: int,
     *     affected_page_count: int,
     *     gained_user_count: int,
     *     reduced_user_count: int,
     *     expires_at: int
     * }|null
     */
    private function workspaceHierarchyPreview(Request $request, ?string $workspaceUid): ?array
    {
        $preview = $request->session()->get('workspace_hierarchy_confirmation');

        if ($workspaceUid === null || !is_array($preview) || ($preview['workspace_uid'] ?? null) !== $workspaceUid) {
            return null;
        }

        $previewId = $preview['preview_id'] ?? null;
        $newParentWorkspaceUid = $preview['new_parent_workspace_uid'] ?? null;
        $movedWorkspaceCount = $preview['moved_workspace_count'] ?? null;
        $affectedPageCount = $preview['affected_page_count'] ?? null;
        $gainedUserCount = $preview['gained_user_count'] ?? null;
        $reducedUserCount = $preview['reduced_user_count'] ?? null;
        $expiresAt = $preview['expires_at'] ?? null;

        if (
            !is_string($previewId)
            || (!is_string($newParentWorkspaceUid) && $newParentWorkspaceUid !== null)
            || !is_int($movedWorkspaceCount)
            || !is_int($affectedPageCount)
            || !is_int($gainedUserCount)
            || !is_int($reducedUserCount)
            || !is_int($expiresAt)
            || $expiresAt < now()->getTimestamp()
        ) {
            $request->session()->forget('workspace_hierarchy_confirmation');

            return null;
        }

        return [
            'preview_id' => $previewId,
            'workspace_uid' => $workspaceUid,
            'new_parent_workspace_uid' => $newParentWorkspaceUid,
            'moved_workspace_count' => $movedWorkspaceCount,
            'affected_page_count' => $affectedPageCount,
            'gained_user_count' => $gainedUserCount,
            'reduced_user_count' => $reducedUserCount,
            'expires_at' => $expiresAt,
        ];
    }
}
