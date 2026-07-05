<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Identity\WorkspaceNavigationItem;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

final readonly class PageLibraryWorkspaceOptions
{
    public function __construct(
        private PageAccess $access,
    ) {
    }

    /**
     * A library workspace is a browsing scope, not necessarily a membership.
     * Users additionally see source workspaces containing at least one page they
     * reach through a live page grant. PageSearch still applies page-by-page
     * authorization inside the selected scope, so this never turns page access
     * into workspace access. System administration deliberately grants no
     * content visibility of its own.
     *
     * @param list<WorkspaceNavigationItem> $membershipItems
     *
     * @return list<WorkspaceNavigationItem>
     */
    public function forUser(User $actor, array $membershipItems): array
    {
        $membershipUids = array_map(
            static fn (WorkspaceNavigationItem $item): string => $item->uid,
            $membershipItems,
        );
        $additionalWorkspaceUids = $this->pageGrantedWorkspaceUids($actor, $membershipUids);

        if ($additionalWorkspaceUids === []) {
            return $membershipItems;
        }

        $additionalWorkspaces = Workspace::query()
            ->whereIn('uid', $additionalWorkspaceUids)
            ->orderBy('name')
            ->get();

        foreach ($additionalWorkspaces as $workspace) {
            $membershipItems[] = new WorkspaceNavigationItem(
                uid: $workspace->uid,
                name: $workspace->name,
                type: $workspace->type,
                role: WorkspaceRole::Reader,
                isMembership: false,
                accessLabel: 'Page access',
            );
        }

        return $membershipItems;
    }

    /**
     * @param list<string> $membershipUids
     *
     * @return list<string>
     */
    private function pageGrantedWorkspaceUids(User $actor, array $membershipUids): array
    {
        $candidates = Page::query()
            ->select(['uid', 'workspace_uid', 'owner_user_uid', 'access_mode'])
            ->with('accessGrants')
            ->whereNotIn('workspace_uid', $membershipUids)
            ->whereHas('accessGrants', function (Builder $query) use ($actor, $membershipUids): void {
                $query->where(function (Builder $query) use ($actor, $membershipUids): void {
                    $query->where(function (Builder $query) use ($actor): void {
                        $query->where('subject_type', PageAccessSubjectType::User)
                            ->where('subject_uid', $actor->uid);
                    });

                    if ($membershipUids !== []) {
                        $query->orWhere(function (Builder $query) use ($membershipUids): void {
                            $query->where('subject_type', PageAccessSubjectType::Workspace)
                                ->whereIn('subject_uid', $membershipUids);
                        });
                    }
                });
            })
            ->get();
        $workspaceUids = [];

        foreach ($candidates as $page) {
            if (isset($workspaceUids[$page->workspace_uid]) || !$this->access->canView($actor, $page)) {
                continue;
            }

            $workspaceUids[$page->workspace_uid] = true;
        }

        return array_keys($workspaceUids);
    }
}
