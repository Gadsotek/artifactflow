<?php

declare(strict_types=1);

namespace App\Domain\ExternalSharing;

enum ExternalShareMode: string
{
    case ExpiresAt = 'expires_at';
    case OneTime = 'one_time';
}
