<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Mcp\McpAccessTokenRevoker;
use App\Domain\Events\DomainEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class DisableTwoFactor
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private McpAccessTokenRevoker $mcpTokens,
    ) {
    }

    public function handle(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $user = User::query()
                ->where('uid', $actor->uid)
                ->lockForUpdate()
                ->sole();
            $trustedDevicesRevoked = DB::table('trusted_devices')->where('user_uid', $user->uid)->delete();
            $mcpTokensRevoked = $this->mcpTokens->revokeActiveForPrincipal(
                principal: $user,
                actorUserUid: $actor->uid,
                channel: 'two_factor_settings',
                reason: 'two_factor_disabled',
            );

            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_secret_created_at' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_last_used_timestep' => null,
            ])->save();

            $event = $this->events->record(
                eventType: DomainEventType::UserTwoFactorDisabled,
                aggregateType: 'user',
                aggregateUid: $user->uid,
                payload: [
                    'user_uid' => $user->uid,
                    'trusted_devices_revoked' => $trustedDevicesRevoked,
                    'mcp_tokens_revoked' => $mcpTokensRevoked,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actor->uid,
                auditableType: 'user',
                auditableUid: $user->uid,
                action: DomainEventType::UserTwoFactorDisabled,
                summary: 'Two-factor authentication disabled.',
                metadata: [
                    'trusted_devices_revoked' => $trustedDevicesRevoked,
                    'mcp_tokens_revoked' => $mcpTokensRevoked,
                ],
            );
        });
    }
}
