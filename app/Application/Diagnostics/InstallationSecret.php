<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

use App\Infrastructure\Security\SecretStrength;

/**
 * Decides whether a deployment secret still needs generating during install:
 * it must satisfy the shared strength rule and remain distinct from every
 * boundary supplied by the caller.
 */
final readonly class InstallationSecret
{
    public static function isMissing(string $secret): bool
    {
        return self::needsReplacement($secret, []);
    }

    /**
     * @param list<string> $mustDifferFrom
     */
    public static function needsReplacement(string $secret, array $mustDifferFrom): bool
    {
        if (!SecretStrength::isProductionSafe($secret)) {
            return true;
        }

        $candidate = SecretStrength::normalized($secret);

        if ($candidate === null) {
            return true;
        }

        foreach ($mustDifferFrom as $otherSecret) {
            $other = SecretStrength::normalized($otherSecret);

            if ($other !== null && hash_equals($candidate, $other)) {
                return true;
            }
        }

        return false;
    }
}
