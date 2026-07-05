<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\User;
use LogicException;

final class ActorId
{
    public static function fromUser(
        User $user,
        string $unsavedUserMessage = 'Cannot resolve actor UID for an unsaved user.',
        string $nonStringUserUidMessage = 'Cannot resolve actor UID for a user with a non-string UID.',
    ): string {
        $userUid = $user->getKey();

        if (is_string($userUid) && $userUid !== '') {
            return $userUid;
        }

        if ($userUid === null) {
            throw new LogicException($unsavedUserMessage);
        }

        throw new LogicException($nonStringUserUidMessage);
    }
}
