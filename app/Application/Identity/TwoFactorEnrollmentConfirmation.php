<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\User;

final readonly class TwoFactorEnrollmentConfirmation
{
    /**
     * @param list<string> $recoveryCodes
     */
    public function __construct(
        public array $recoveryCodes,
        public int $authRevision,
        public User $user,
    ) {
    }
}
