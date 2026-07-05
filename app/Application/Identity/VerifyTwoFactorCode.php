<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

final readonly class VerifyTwoFactorCode
{
    public function __construct(
        private Google2FA $google2fa,
        private TwoFactorRecoveryCodeGenerator $recoveryCodes,
    ) {
    }

    public function verifyTotpAndAdvance(User $user, string $code): bool
    {
        if (preg_match('/^\s*\d{6}\s*$/', $code) !== 1) {
            return false;
        }

        try {
            return DB::transaction(function () use ($code, $user): bool {
                $lockedUser = User::query()
                    ->where('uid', $user->uid)
                    ->lockForUpdate()
                    ->sole();

                $secret = $lockedUser->two_factor_secret;
                if (!is_string($secret) || $secret === '') {
                    return false;
                }

                $timestamp = $this->google2fa->verifyKeyNewer(
                    $secret,
                    preg_replace('/\s+/', '', $code) ?? $code,
                    $lockedUser->two_factor_last_used_timestep ?? -1,
                    $this->driftWindow(),
                );

                if (!is_int($timestamp)) {
                    return false;
                }

                $lockedUser->forceFill([
                    'two_factor_last_used_timestep' => $timestamp,
                ])->save();

                return true;
            });
        } catch (DecryptException) {
            return false;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalizedCode = $this->recoveryCodes->normalize($code);

        return DB::transaction(function () use ($normalizedCode, $user): bool {
            $lockedUser = User::query()
                ->where('uid', $user->uid)
                ->lockForUpdate()
                ->sole();

            $hashes = $lockedUser->two_factor_recovery_codes;
            if ($hashes === null || $hashes === []) {
                return false;
            }

            $matchedIndex = null;
            foreach ($hashes as $index => $hash) {
                $matches = Hash::check($normalizedCode, $hash);
                if ($matches && $matchedIndex === null) {
                    $matchedIndex = $index;
                }
            }

            if ($matchedIndex === null) {
                return false;
            }

            $remainingHashes = [];
            foreach ($hashes as $index => $hash) {
                if ($index !== $matchedIndex) {
                    $remainingHashes[] = $hash;
                }
            }

            $lockedUser->forceFill([
                'two_factor_recovery_codes' => $remainingHashes,
            ])->save();

            return true;
        });
    }

    private function driftWindow(): int
    {
        $value = config('auth.two_factor_drift_window', 1);
        $window = is_int($value) || is_string($value) ? (int) $value : 1;

        return max(0, $window);
    }
}
