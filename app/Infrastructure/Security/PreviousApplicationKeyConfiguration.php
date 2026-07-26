<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

final readonly class PreviousApplicationKeyConfiguration
{
    /**
     * Parse configured retired application keys to their raw secret bytes.
     * Null means the key ring is malformed and must fail closed.
     *
     * @return list<string>|null
     */
    public static function normalizedKeys(mixed $configured): ?array
    {
        if (!is_array($configured)) {
            return null;
        }

        $keys = [];

        foreach ($configured as $key) {
            if (!is_string($key)) {
                return null;
            }

            $key = trim($key);

            if ($key === '') {
                continue;
            }

            if (!SecretStrength::isProductionSafe($key)) {
                return null;
            }

            $normalized = SecretStrength::normalized($key);

            if ($normalized === null) {
                return null;
            }

            $keys[] = $normalized;
        }

        return $keys;
    }
}
