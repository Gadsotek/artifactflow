<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\Events\DomainEventType;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

final readonly class ConfirmTwoFactorEnrollment
{
    public function __construct(
        private Google2FA $google2fa,
        private TwoFactorRecoveryCodeGenerator $recoveryCodes,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function handle(User $actor, string $code): array
    {
        try {
            return DB::transaction(function () use ($actor, $code): array {
                $user = User::query()
                    ->where('uid', $actor->uid)
                    ->lockForUpdate()
                    ->sole();

                $secret = $user->two_factor_secret;
                if (!is_string($secret) || $secret === '') {
                    throw ValidationException::withMessages([
                        'code' => 'Start two-factor enrollment before confirming a code.',
                    ]);
                }

                $timestamp = $this->google2fa->verifyKeyNewer(
                    $secret,
                    preg_replace('/\s+/', '', $code) ?? $code,
                    -1,
                    $this->driftWindow(),
                );

                if (!is_int($timestamp)) {
                    throw ValidationException::withMessages([
                        'code' => 'The provided authentication code is invalid.',
                    ]);
                }

                $plainRecoveryCodes = $this->recoveryCodes->generatePlainCodes();
                $user->forceFill([
                    'two_factor_confirmed_at' => now(),
                    'two_factor_recovery_codes' => $this->recoveryCodes->hashCodes($plainRecoveryCodes),
                    'two_factor_last_used_timestep' => $timestamp,
                ])->save();

                $event = $this->events->record(
                    eventType: DomainEventType::UserTwoFactorEnabled,
                    aggregateType: 'user',
                    aggregateUid: $user->uid,
                    payload: [
                        'user_uid' => $user->uid,
                        'recovery_codes_remaining' => count($plainRecoveryCodes),
                    ],
                );

                $this->audit->record(
                    event: $event,
                    actorUserUid: $actor->uid,
                    auditableType: 'user',
                    auditableUid: $user->uid,
                    action: DomainEventType::UserTwoFactorEnabled,
                    summary: 'Two-factor authentication enabled.',
                    metadata: [
                        'recovery_codes_remaining' => count($plainRecoveryCodes),
                    ],
                );

                return $plainRecoveryCodes;
            });
        } catch (DecryptException) {
            throw ValidationException::withMessages([
                'code' => 'The two-factor secret is unreadable. Use a recovery code or contact an operator.',
            ]);
        }
    }

    private function driftWindow(): int
    {
        $value = config('auth.two_factor_drift_window', 1);
        $window = is_int($value) || is_string($value) ? (int) $value : 1;

        return max(0, $window);
    }
}
