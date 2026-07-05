<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

final readonly class StartTwoFactorEnrollment
{
    public function __construct(
        private Google2FA $google2fa,
    ) {
    }

    public function handle(User $user): string
    {
        return DB::transaction(function () use ($user): string {
            $lockedUser = User::query()
                ->where('uid', $user->uid)
                ->lockForUpdate()
                ->sole();

            if ($lockedUser->hasEnabledTwoFactor() && is_string($lockedUser->two_factor_secret)) {
                return $lockedUser->two_factor_secret;
            }

            $secret = $this->google2fa->generateSecretKey(32);

            $lockedUser->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_last_used_timestep' => null,
            ])->save();

            return $secret;
        });
    }
}
