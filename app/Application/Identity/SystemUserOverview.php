<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

final class SystemUserOverview
{
    /**
     * @return list<SystemUserItem>
     *
     * @throws AuthorizationException
     */
    public function forSystemAdmin(User $actor): array
    {
        if (!$actor->is_system_admin) {
            throw new AuthorizationException('Only system admins can manage users.');
        }

        $users = User::query()
            ->orderBy('name')
            ->orderBy('email')
            ->get();
        $items = [];

        foreach ($users as $user) {
            $createdAt = $user->created_at;

            if (!$createdAt instanceof Carbon) {
                continue;
            }

            $items[] = new SystemUserItem(
                uid: $user->uid,
                name: $user->name,
                email: $user->email,
                isSystemAdmin: $user->is_system_admin,
                createdAt: $createdAt,
            );
        }

        return $items;
    }
}
