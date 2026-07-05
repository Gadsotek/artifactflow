<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\Events\DomainEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class RegenerateTwoFactorRecoveryCodes
{
    public function __construct(
        private TwoFactorRecoveryCodeGenerator $recoveryCodes,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @return list<string>
     */
    public function handle(User $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            $user = User::query()
                ->where('uid', $actor->uid)
                ->lockForUpdate()
                ->sole();

            if (!$user->hasEnabledTwoFactor()) {
                return [];
            }

            $plainCodes = $this->recoveryCodes->generatePlainCodes();
            $trustedDevicesRevoked = DB::table('trusted_devices')->where('user_uid', $user->uid)->delete();
            $user->forceFill([
                'two_factor_recovery_codes' => $this->recoveryCodes->hashCodes($plainCodes),
            ])->save();

            $event = $this->events->record(
                eventType: DomainEventType::UserTwoFactorRecoveryCodesRegenerated,
                aggregateType: 'user',
                aggregateUid: $user->uid,
                payload: [
                    'user_uid' => $user->uid,
                    'recovery_codes_remaining' => count($plainCodes),
                    'trusted_devices_revoked' => $trustedDevicesRevoked,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actor->uid,
                auditableType: 'user',
                auditableUid: $user->uid,
                action: DomainEventType::UserTwoFactorRecoveryCodesRegenerated,
                summary: 'Two-factor recovery codes regenerated.',
                metadata: [
                    'recovery_codes_remaining' => count($plainCodes),
                    'trusted_devices_revoked' => $trustedDevicesRevoked,
                ],
            );

            return $plainCodes;
        });
    }
}
