<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

final class WorkspaceMemberOverview
{
    public function forWorkspace(
        User $actor,
        ?string $workspaceUid,
        int $requestedPage = 1,
        int $perPage = 20,
    ): WorkspaceMemberPage {
        if ($workspaceUid === null || !$this->isWorkspaceMember($actor, $workspaceUid)) {
            return new WorkspaceMemberPage([], 1, 1, $perPage, 0);
        }

        $membershipQuery = WorkspaceMembership::query()
            ->where('workspace_uid', $workspaceUid);
        $total = $membershipQuery->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min(max(1, $requestedPage), $lastPage);
        $memberships = $membershipQuery
            ->orderBy('created_at')
            ->orderBy('uid')
            ->offset(($currentPage - 1) * $perPage)
            ->limit($perPage)
            ->get();
        $items = [];

        // Batch the per-member lookups so a page of members costs a fixed number
        // of queries instead of one User SELECT and one page COUNT per member.
        $userUids = $memberships->pluck('user_uid')->all();
        $users = User::query()->whereIn('uid', $userUids)->get()->keyBy('uid');
        $ownedPageCounts = $this->ownedPageCounts($workspaceUid, $userUids);

        foreach ($memberships as $membership) {
            $user = $users->get($membership->user_uid);

            if (!$user instanceof User) {
                continue;
            }

            $items[] = new WorkspaceMemberItem(
                membershipUid: $membership->uid,
                userUid: $user->uid,
                name: $user->name,
                email: $user->email,
                role: $membership->role,
                isCurrentUser: $user->uid === $actor->uid,
                ownedPageCount: $ownedPageCounts[$user->uid] ?? 0,
            );
        }

        return new WorkspaceMemberPage(
            items: $items,
            currentPage: $currentPage,
            lastPage: $lastPage,
            perPage: $perPage,
            total: $total,
        );
    }

    /**
     * @return list<WorkspaceOwnershipCandidate>
     */
    public function ownershipCandidatesForWorkspace(User $actor, ?string $workspaceUid): array
    {
        if ($workspaceUid === null || !$this->isWorkspaceMember($actor, $workspaceUid)) {
            return [];
        }

        $memberships = WorkspaceMembership::query()
            ->where('workspace_uid', $workspaceUid)
            ->whereIn('role', [WorkspaceRole::Editor->value, WorkspaceRole::Admin->value])
            ->orderBy('created_at')
            ->orderBy('uid')
            ->get();
        $candidates = [];

        // Batch the candidate users into one query rather than a find() per
        // editor/admin membership (this list is unpaginated).
        $users = User::query()
            ->whereIn('uid', $memberships->pluck('user_uid')->all())
            ->get()
            ->keyBy('uid');

        foreach ($memberships as $membership) {
            $user = $users->get($membership->user_uid);

            if (!$user instanceof User) {
                continue;
            }

            $candidates[] = new WorkspaceOwnershipCandidate(
                userUid: $user->uid,
                name: $user->name,
            );
        }

        return $candidates;
    }

    /**
     * Owned-page counts for the given users in one grouped query, keyed by user
     * uid. Members with no owned pages are simply absent (callers default to 0).
     *
     * @param array<mixed> $userUids
     * @return array<string, int>
     */
    private function ownedPageCounts(string $workspaceUid, array $userUids): array
    {
        if ($userUids === []) {
            return [];
        }

        $counts = [];
        $rows = Page::query()
            ->where('workspace_uid', $workspaceUid)
            ->whereIn('owner_user_uid', $userUids)
            ->groupBy('owner_user_uid')
            ->selectRaw('owner_user_uid, count(*) as owned_count')
            ->get();

        foreach ($rows as $row) {
            $ownerUid = $row->getAttribute('owner_user_uid');
            $ownedCount = $row->getAttribute('owned_count');

            if (is_string($ownerUid) && (is_int($ownedCount) || is_string($ownedCount))) {
                $counts[$ownerUid] = (int) $ownedCount;
            }
        }

        return $counts;
    }

    public function canManageWorkspace(User $actor, ?string $workspaceUid): bool
    {
        if ($workspaceUid === null) {
            return false;
        }

        $workspace = Workspace::query()->find($workspaceUid);

        if (!$workspace instanceof Workspace || $workspace->type !== WorkspaceType::Shared) {
            return false;
        }

        return WorkspaceMembership::query()
            ->where('workspace_uid', $workspaceUid)
            ->where('user_uid', $actor->uid)
            ->where('role', WorkspaceRole::Admin)
            ->exists();
    }

    private function isWorkspaceMember(User $actor, string $workspaceUid): bool
    {
        return WorkspaceMembership::query()
            ->where('workspace_uid', $workspaceUid)
            ->where('user_uid', $actor->uid)
            ->exists();
    }
}
