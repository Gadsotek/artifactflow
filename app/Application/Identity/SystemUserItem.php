<?php

declare(strict_types=1);

namespace App\Application\Identity;

use Illuminate\Support\Carbon;

final readonly class SystemUserItem
{
    public function __construct(
        public string $uid,
        public string $name,
        public string $email,
        public bool $isSystemAdmin,
        public Carbon $createdAt,
    ) {
    }
}
