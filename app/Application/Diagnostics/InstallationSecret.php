<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

use App\Infrastructure\Security\SecretStrength;

/**
 * Decides whether a deployment secret still needs generating during install:
 * exactly the inverse of the shared strength rule the boot gate enforces.
 */
final readonly class InstallationSecret
{
    public static function isMissing(string $secret): bool
    {
        return !SecretStrength::isStrong($secret);
    }
}
