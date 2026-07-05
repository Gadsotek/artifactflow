<?php

declare(strict_types=1);

namespace App\Policies;

use App\Application\PageCatalog\PageAccess;
use App\Models\Page;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final readonly class PagePolicy
{
    public function __construct(
        private PageAccess $access,
    ) {
    }

    public function view(User $user, Page $page): bool|Response
    {
        return $this->access->canView($user, $page) ?: Response::denyAsNotFound();
    }

    public function update(User $user, Page $page): bool|Response
    {
        return $this->hiddenUnlessViewable($user, $page)
            ?? $this->access->canEdit($user, $page);
    }

    public function manageAccess(User $user, Page $page): bool|Response
    {
        return $this->hiddenUnlessViewable($user, $page)
            ?? $this->access->canManageAccess($user, $page);
    }

    public function changeAccessMode(User $user, Page $page): bool|Response
    {
        return $this->hiddenUnlessViewable($user, $page)
            ?? $this->access->canChangeAccessMode($user, $page);
    }

    public function archive(User $user, Page $page): bool|Response
    {
        return $this->hiddenUnlessViewable($user, $page)
            ?? $this->access->canArchive($user, $page);
    }

    public function hardDelete(User $user, Page $page): bool|Response
    {
        return $this->hiddenUnlessViewable($user, $page)
            ?? $this->access->canHardDelete($user, $page);
    }

    public function move(User $user, Page $page): bool|Response
    {
        // Moving clears page-local grants and can relocate private content, so keep it at the hard-delete authority level.
        return $this->hiddenUnlessViewable($user, $page)
            ?? $this->access->canHardDelete($user, $page);
    }

    /**
     * A denied page must be indistinguishable from a missing one: anyone who
     * cannot view the page gets 404 on every ability, so a leaked ULID cannot
     * be used to confirm the page exists. Viewers who lack a higher ability
     * still get a plain 403.
     */
    private function hiddenUnlessViewable(User $user, Page $page): ?Response
    {
        return $this->access->canView($user, $page) ? null : Response::denyAsNotFound();
    }
}
