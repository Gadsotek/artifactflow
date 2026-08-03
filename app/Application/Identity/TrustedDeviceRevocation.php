<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\User;

final readonly class TrustedDeviceRevocation
{
    public function __construct(
        public int $trustedDevicesRevoked,
        public int $authRevision,
        public User $user,
    ) {
    }
}
