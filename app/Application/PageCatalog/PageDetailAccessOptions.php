<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Identity\EffectiveWorkspaceMembershipResolver;
use App\Domain\Identity\WorkspaceType;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\User;
use App\Models\Workspace;

final readonly class PageDetailAccessOptions
{
    public function __construct(
        private EffectiveWorkspaceMembershipResolver $memberships,
    ) {
    }

    /**
     * @return list<PageAccessGrantItem>
     */
    public function grantsFor(Page $page): array
    {
        $grants = PageAccessGrant::query()
            ->where('page_uid', $page->uid)
            ->orderBy('subject_type')
            ->orderBy('subject_uid')
            ->get();

        // Batch the subject lookups: one User query and one Workspace query for
        // the whole grant list instead of a find() per grant.
        $userUids = [];
        $workspaceUids = [];

        foreach ($grants as $grant) {
            if ($grant->subject_type === PageAccessSubjectType::User) {
                $userUids[] = $grant->subject_uid;
            } else {
                $workspaceUids[] = $grant->subject_uid;
            }
        }

        $users = User::query()->whereIn('uid', $userUids)->get()->keyBy('uid')->all();
        $workspaces = Workspace::query()->whereIn('uid', $workspaceUids)->get()->keyBy('uid')->all();
        $result = [];

        foreach ($grants as $grant) {
            $result[] = new PageAccessGrantItem(
                grantUid: $grant->uid,
                subjectType: $grant->subject_type,
                subjectLabel: $this->subjectLabel($grant, $users, $workspaces),
                role: $grant->role,
            );
        }

        return $result;
    }

    /**
     * @return list<PageAccessWorkspaceTargetItem>
     */
    public function workspaceTargetsFor(User $actor): array
    {
        $workspaceUids = $this->memberships->workspaceUidsFor($actor->uid);
        $workspaces = Workspace::query()
            ->whereIn('uid', $workspaceUids)
            ->where('type', WorkspaceType::Shared)
            ->orderBy('name')
            ->get();
        $items = [];

        foreach ($workspaces as $workspace) {
            $items[] = new PageAccessWorkspaceTargetItem(
                uid: $workspace->uid,
                name: $workspace->name,
            );
        }

        return $items;
    }

    /**
     * @param array<array-key, User> $users
     * @param array<array-key, Workspace> $workspaces
     */
    private function subjectLabel(PageAccessGrant $grant, array $users, array $workspaces): string
    {
        if ($grant->subject_type === PageAccessSubjectType::User) {
            $user = $users[$grant->subject_uid] ?? null;

            return $user instanceof User
                ? sprintf('%s (%s)', $user->name, $user->email)
                : 'Deleted user';
        }

        $workspace = $workspaces[$grant->subject_uid] ?? null;

        return $workspace instanceof Workspace ? $workspace->name : 'Deleted workspace';
    }
}
