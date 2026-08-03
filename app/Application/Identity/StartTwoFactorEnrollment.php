<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

final readonly class StartTwoFactorEnrollment
{
    public function __construct(
        private Google2FA $google2fa,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public function handle(User $user, int $expectedAuthRevision): string
    {
        return DB::transaction(function () use ($expectedAuthRevision, $user): string {
            $lockedUser = User::query()
                ->where('uid', $user->uid)
                ->lockForUpdate()
                ->sole();

            if ($lockedUser->auth_revision !== $expectedAuthRevision) {
                throw ValidationException::withMessages([
                    'code' => 'Your authentication state changed. Sign in again before starting two-factor enrollment.',
                ]);
            }

            if ($lockedUser->hasEnabledTwoFactor() && is_string($lockedUser->two_factor_secret)) {
                return $lockedUser->two_factor_secret;
            }

            $secret = $this->google2fa->generateSecretKey(32);

            $lockedUser->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_secret_created_at' => now(),
                'two_factor_confirmed_at' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_last_used_timestep' => null,
            ])->save();

            return $secret;
        });
    }
}
