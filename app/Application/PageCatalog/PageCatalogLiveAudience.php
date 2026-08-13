<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Identity\EffectiveWorkspaceMembershipResolver;
use App\Models\User;

final readonly class PageCatalogLiveAudience
{
    public function __construct(
        private EffectiveWorkspaceMembershipResolver $memberships,
    ) {
    }

    /**
     * Page creation always starts with inherited workspace access, so current
     * human workspace members are the complete browser audience at commit time.
     * The client still refetches through PageSearch before rendering anything.
     *
     * @return list<string>
     */
    public function forWorkspace(string $workspaceUid): array
    {
        $effectiveUserUids = $this->memberships->userUidsForAny([$workspaceUid]);

        $users = User::query()
            ->whereIn('uid', $effectiveUserUids)
            ->where('is_service_account', false)
            ->orderBy('uid')
            ->pluck('uid');

        /** @var list<string> $audience */
        $audience = $users->all();

        return $audience;
    }
}
