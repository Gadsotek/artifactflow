<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Mcp\McpEffectiveAuthority;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Models\Page;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\Builder;

/**
 * Coarse SQL visibility shared by page search and taxonomy discovery. Exact
 * PageAccess::canView checks must still post-filter the result because grant
 * lowering and membership-removal rules cannot be represented safely here.
 */
final readonly class PageVisibilityQuery
{
    public function __construct(
        private McpEffectiveAuthority $mcpAuthority,
    ) {
    }

    /**
     * @param Builder<Page> $query
     */
    public function apply(Builder $query, User $actor): PageVisibilityScope
    {
        [$workspaceUids, $adminWorkspaceUids] = $this->workspaceUidsFor($actor);
        $adminWorkspaceUids = $this->mcpAuthority->adminWorkspaceUids($adminWorkspaceUids);
        $scopeUids = $this->mcpAuthority->workspaceScopeUids();

        if ($scopeUids !== null) {
            $query->whereIn('workspace_uid', $scopeUids);
        }

        $query->where(function (Builder $query) use ($actor, $workspaceUids, $adminWorkspaceUids): void {
            $query->where('owner_user_uid', $actor->uid);

            if ($workspaceUids !== []) {
                $query->orWhere(function (Builder $query) use ($workspaceUids): void {
                    $query->where('access_mode', PageAccessMode::Inherited)
                        ->whereIn('workspace_uid', $workspaceUids);
                });
            }

            if ($adminWorkspaceUids !== []) {
                $query->orWhereIn('workspace_uid', $adminWorkspaceUids);
            }

            $query->orWhereHas('accessGrants', function (Builder $query) use ($actor, $workspaceUids): void {
                $query->where(function (Builder $query) use ($actor, $workspaceUids): void {
                    $query->where(function (Builder $query) use ($actor): void {
                        $query->where('subject_type', PageAccessSubjectType::User)
                            ->where('subject_uid', $actor->uid);
                    });

                    if ($workspaceUids !== []) {
                        $query->orWhere(function (Builder $query) use ($workspaceUids): void {
                            $query->where('subject_type', PageAccessSubjectType::Workspace)
                                ->whereIn('subject_uid', $workspaceUids);
                        });
                    }
                });
            });
        });

        return new PageVisibilityScope($workspaceUids);
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private function workspaceUidsFor(User $actor): array
    {
        $memberships = WorkspaceMembership::query()
            ->where('user_uid', $actor->uid)
            ->get();
        $workspaceUids = [];
        $adminWorkspaceUids = [];

        foreach ($memberships as $membership) {
            $workspaceUids[] = $membership->workspace_uid;

            if ($membership->role === WorkspaceRole::Admin) {
                $adminWorkspaceUids[] = $membership->workspace_uid;
            }
        }

        return [
            $this->mcpAuthority->filterWorkspaceUids(array_values(array_unique($workspaceUids))),
            $this->mcpAuthority->filterWorkspaceUids(array_values(array_unique($adminWorkspaceUids))),
        ];
    }
}
