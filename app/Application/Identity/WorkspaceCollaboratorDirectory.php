<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Searches the installation's internal human directory. ArtifactFlow treats
 * registered human accounts as coworkers discoverable to other authenticated
 * humans; UIDs and profile labels are identifiers, not authorization secrets.
 * Service accounts stay out of human sharing controls.
 */
final readonly class WorkspaceCollaboratorDirectory
{
    public const int MAX_RESULTS = 10;

    /**
     * Candidates the actor may add to the target workspace: registered human
     * coworkers not already in that workspace whose name or email matches.
     *
     * @return list<array{uid: string, name: string, email: string}>
     */
    public function search(User $actor, string $targetWorkspaceUid, string $query): array
    {
        $alreadyInTarget = WorkspaceMembership::query()
            ->where('workspace_uid', $targetWorkspaceUid)
            ->pluck('user_uid')
            ->all();

        return $this->searchKnownUsers(
            actor: $actor,
            query: $query,
            excludedUserUids: array_values(array_filter($alreadyInTarget, 'is_string')),
        );
    }

    /**
     * Known users available to a page-access picker. Unlike the workspace add
     * flow, members of the page workspace remain valid results because an
     * explicit grant may override their inherited role on a restricted page.
     *
     * @param list<string> $excludedUserUids
     *
     * @return list<array{uid: string, name: string, email: string}>
     */
    public function searchKnownUsers(User $actor, string $query, array $excludedUserUids = []): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        // Escape LIKE wildcards so a query of "%" or "_" matches literally.
        $like = '%' . addcslashes($query, '%_\\') . '%';

        $usersQuery = User::query()
            ->where('uid', '!=', $actor->uid);

        if ($excludedUserUids !== []) {
            $usersQuery->whereNotIn('uid', $excludedUserUids);
        }

        /** @var Collection<int, User> $users */
        $users = $usersQuery
            ->where('is_service_account', false)
            ->where(static function (Builder $builder) use ($like): void {
                $builder->where('name', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like);
            })
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get(['uid', 'name', 'email']);

        return array_values($users->map(static fn (User $user): array => [
            'uid' => $user->uid,
            'name' => $user->name,
            'email' => $user->email,
        ])->all());
    }

    /**
     * The write-boundary check for a human coworker selected from the directory.
     * Knowing or submitting a UID never grants authority over the target
     * workspace/page; those object permissions are enforced independently.
     */
    public function isEligibleCoworker(User $actor, User $target): bool
    {
        return $target->uid !== $actor->uid && !$target->is_service_account;
    }
}
