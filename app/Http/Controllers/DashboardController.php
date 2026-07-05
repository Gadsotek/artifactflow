<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
            'workspaceOwnershipCandidates' => $this->workspaceMembers->ownershipCandidatesForWorkspace(
                $user,
                $currentWorkspaceUid,
            ),
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
            status: null,
            categoryUid: null,
            tagUids: [],
            ownerUserUid: null,
            includeArchived: false,
            sort: PageSearchSort::RecentlyUpdated,
        ));
    }
}
