<?php

declare(strict_types=1);

namespace App\Application\Identity;

use Carbon\CarbonInterface;

final readonly class TwoFactorEnrollmentFreshness
{
    public function __construct(
        private PasswordConfirmationFreshness $passwordConfirmationFreshness,
    ) {
    }

    public function isCurrent(?CarbonInterface $secretCreatedAt, mixed $passwordConfirmedAt): bool
    {
        $confirmedAt = $this->passwordConfirmationFreshness->confirmedAtTimestamp($passwordConfirmedAt);

        return $confirmedAt !== null
            && $secretCreatedAt !== null
            && $secretCreatedAt->getTimestamp() <= now()->getTimestamp()
            && $secretCreatedAt->getTimestamp() >= $confirmedAt
            && $this->passwordConfirmationFreshness->isFreshForTwoFactorEnrollment($confirmedAt);
    }
}
