<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\User;
use Illuminate\Database\Query\Builder;

final class PageCatalogLiveAudience
{
    /**
     * Page creation always starts with inherited workspace access, so current
     * human workspace members are the complete browser audience at commit time.
     * The client still refetches through PageSearch before rendering anything.
     *
     * @return list<string>
     */
    public function forWorkspace(string $workspaceUid): array
    {
        $users = User::query()
            ->where('is_service_account', false)
            ->whereExists(static function (Builder $memberships) use ($workspaceUid): void {
                $memberships
                    ->selectRaw('1')
                    ->from('workspace_memberships')
                    ->whereColumn('workspace_memberships.user_uid', 'users.uid')
                    ->where('workspace_memberships.workspace_uid', $workspaceUid);
            })
            ->orderBy('uid')
            ->pluck('uid');

        /** @var list<string> $audience */
        $audience = $users->all();

        return $audience;
    }
}
