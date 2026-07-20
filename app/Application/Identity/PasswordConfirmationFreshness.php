<?php

declare(strict_types=1);

namespace App\Application\Identity;

use Illuminate\Contracts\Config\Repository;

final readonly class PasswordConfirmationFreshness
{
    public function __construct(
        private Repository $config,
    ) {
    }

    public function isFresh(mixed $confirmedAt): bool
    {
        $expiresAt = $this->expiresAt($confirmedAt, $this->timeoutSeconds('auth.password_timeout', 900));

        return $expiresAt !== null && $expiresAt > now()->getTimestamp();
    }

    public function isFreshForTwoFactorEnrollment(mixed $confirmedAt): bool
    {
        $expiresAt = $this->expiresAtForTwoFactorEnrollment($confirmedAt);

        return $expiresAt !== null && $expiresAt > now()->getTimestamp();
    }

    public function expiresAtForTwoFactorEnrollment(mixed $confirmedAt): ?int
    {
        return $this->expiresAt(
            $confirmedAt,
            $this->timeoutSeconds('auth.two_factor_enrollment_password_timeout', 180),
        );
    }

    public function confirmedAtTimestamp(mixed $confirmedAt): ?int
    {
        if (!is_int($confirmedAt) && !(is_string($confirmedAt) && ctype_digit($confirmedAt))) {
            return null;
        }

        $confirmedTimestamp = (int) $confirmedAt;

        return $confirmedTimestamp <= now()->getTimestamp() ? $confirmedTimestamp : null;
    }

    private function expiresAt(mixed $confirmedAt, int $timeoutSeconds): ?int
    {
        $confirmedTimestamp = $this->confirmedAtTimestamp($confirmedAt);
        if ($confirmedTimestamp === null) {
            return null;
        }

        return $confirmedTimestamp + $timeoutSeconds;
    }

    private function timeoutSeconds(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);
        $timeout = is_int($value) || is_string($value) ? (int) $value : $default;

        return max(60, $timeout);
    }
}
