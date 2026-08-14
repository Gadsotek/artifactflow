<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\Workspace;
use App\Models\WorkspaceAncestry;

/**
 * Resolves and locks the bounded workspace/page reach affected by an authority
 * mutation. It does not decide policy; mutation handlers keep that decision.
 */
final readonly class WorkspaceAuthorityImpact
{
    public function __construct(
        private WorkspaceHierarchyGraph $hierarchy,
        private EffectiveWorkspaceMembershipResolver $memberships,
    ) {
    }

    public function acquireHierarchyLock(): void
    {
        $this->hierarchy->acquireMutationLock();
    }

    /**
     * @return list<string>
     */
    public function descendantWorkspaceUids(string $workspaceUid): array
    {
        $workspaceUids = array_map(
            static fn (WorkspaceAncestry $row): string => $row->descendant_workspace_uid,
            $this->hierarchy->subtreeRows($workspaceUid),
        );
        sort($workspaceUids);

        return array_values(array_unique($workspaceUids));
    }

    /**
     * Pages stored in an affected workspace or granted to one of those
     * workspace subjects.
     *
     * @param list<string> $workspaceUids
     * @return list<string>
     */
    public function pageUids(array $workspaceUids): array
    {
        /** @var list<string> $workspacePageUids */
        $workspacePageUids = Page::query()
            ->whereIn('workspace_uid', $workspaceUids)
            ->orderBy('uid')
            ->pluck('uid')
            ->all();
        /** @var list<string> $grantedPageUids */
        $grantedPageUids = PageAccessGrant::query()
            ->where('subject_type', PageAccessSubjectType::Workspace)
            ->whereIn('subject_uid', $workspaceUids)
            ->orderBy('page_uid')
            ->pluck('page_uid')
            ->all();
        $pageUids = array_values(array_unique([...$workspacePageUids, ...$grantedPageUids]));
        sort($pageUids);

        return $pageUids;
    }

    /**
     * @param list<string> $pageUids
     */
    public function lockPages(array $pageUids): void
    {
        foreach ($pageUids as $pageUid) {
            Page::query()->whereKey($pageUid)->lockForUpdate()->first();
        }
    }

    /**
     * @param list<string> $workspaceUids
     */
    public function lockWorkspaces(array $workspaceUids): void
    {
        sort($workspaceUids);

        foreach ($workspaceUids as $workspaceUid) {
            Workspace::query()->whereKey($workspaceUid)->lockForUpdate()->first();
        }
    }

    /**
     * @param list<string> $workspaceUids
     * @return array<string, WorkspaceRole|null>
     */
    public function rolesForUser(string $userUid, array $workspaceUids): array
    {
        $roles = [];

        foreach ($this->memberships->resolveMany($userUid, $workspaceUids) as $workspaceUid => $membership) {
            $roles[$workspaceUid] = $membership->role;
        }

        return $roles;
    }
}
