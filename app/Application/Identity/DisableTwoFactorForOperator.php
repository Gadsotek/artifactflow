<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Mcp\McpAccessTokenRevoker;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Models\InstallationSettings;
use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class DisableTwoFactorForOperator
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private InstallationLimitSettings $settings,
        private McpAccessTokenRevoker $mcpTokens,
    ) {
    }

    public function handle(
        string $email,
        string $reason,
        bool $clearEnforcement,
        bool $force,
        bool $invokedFromHttpLifecycle = false,
    ): User {
        $this->assertShellContext($invokedFromHttpLifecycle);

        if (!$force) {
            throw new DomainRuleViolation('Use --force to intentionally disable two-factor authentication.');
        }

        $normalizedEmail = strtolower(trim($email));
        $reason = trim($reason);
        if ($normalizedEmail === '') {
            throw new DomainRuleViolation('User email is required.');
        }

        if ($reason === '') {
            throw new DomainRuleViolation('Operator disable reason is required.');
        }

        $user = User::query()->where('email', $normalizedEmail)->first();
        if (!$user instanceof User) {
            throw new DomainRuleViolation('User does not exist.');
        }

        DB::transaction(function () use ($clearEnforcement, $reason, $user): void {
            $lockedUser = User::query()
                ->where('uid', $user->uid)
                ->lockForUpdate()
                ->sole();
            $trustedDevicesRevoked = DB::table('trusted_devices')->where('user_uid', $lockedUser->uid)->delete();
            $mcpTokensRevoked = $this->mcpTokens->revokeActiveForPrincipal(
                principal: $lockedUser,
                actorUserUid: null,
                channel: 'operator_break_glass',
                reason: 'two_factor_operator_disabled',
            );

            $lockedUser->forceFill([
                'two_factor_secret' => null,
                'two_factor_secret_created_at' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_last_used_timestep' => null,
                'two_factor_required' => false,
            ])->save();

            $event = $this->events->record(
                eventType: DomainEventType::UserTwoFactorOperatorDisabled,
                aggregateType: 'user',
                aggregateUid: $lockedUser->uid,
                payload: [
                    'user_uid' => $lockedUser->uid,
                    'reason' => $reason,
                    'operator_user' => $this->operatorUser(),
                    'operator_host' => $this->operatorHost(),
                    'trusted_devices_revoked' => $trustedDevicesRevoked,
                    'mcp_tokens_revoked' => $mcpTokensRevoked,
                    'enforcement_cleared' => $clearEnforcement,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: null,
                auditableType: 'user',
                auditableUid: $lockedUser->uid,
                action: DomainEventType::UserTwoFactorOperatorDisabled,
                summary: 'Two-factor authentication disabled by operator.',
                metadata: [
                    'reason' => $reason,
                    'operator_user' => $this->operatorUser(),
                    'operator_host' => $this->operatorHost(),
                    'trusted_devices_revoked' => $trustedDevicesRevoked,
                    'mcp_tokens_revoked' => $mcpTokensRevoked,
                    'enforcement_cleared' => $clearEnforcement,
                ],
            );

            if ($clearEnforcement) {
                $this->clearInstallationEnforcement();
            }
        });

        $this->settings->forgetCachedValues();

        return $user->refresh();
    }

    private function clearInstallationEnforcement(): void
    {
        $values = $this->settings->current();
        $settings = InstallationSettings::query()
            ->where('scope', InstallationSettings::SCOPE_INSTALLATION)
            ->lockForUpdate()
            ->first();

        if (!$settings instanceof InstallationSettings) {
            $settings = new InstallationSettings();
        }

        $settings->forceFill(array_replace($values->toPersistenceArray(), [
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'two_factor_required_for_system_admins' => false,
            'two_factor_required_for_all_users' => false,
            'updated_by_user_uid' => null,
        ]))->save();

        $event = $this->events->record(
            eventType: DomainEventType::InstallationTwoFactorEnforcementOperatorDisabled,
            aggregateType: 'installation_settings',
            aggregateUid: $settings->uid,
            payload: [
                'two_factor_required_for_system_admins' => false,
                'two_factor_required_for_all_users' => false,
                'reason' => 'operator_break_glass',
            ],
        );

        $this->audit->record(
            event: $event,
            actorUserUid: null,
            auditableType: 'installation_settings',
            auditableUid: $settings->uid,
            action: DomainEventType::InstallationTwoFactorEnforcementOperatorDisabled,
            summary: 'Two-factor enforcement disabled by operator.',
            metadata: [
                'two_factor_required_for_system_admins' => false,
                'two_factor_required_for_all_users' => false,
                'reason' => 'operator_break_glass',
            ],
        );
    }

    private function assertShellContext(bool $invokedFromHttpLifecycle): void
    {
        if ($invokedFromHttpLifecycle || !App::runningInConsole() || PHP_SAPI !== 'cli') {
            throw new RuntimeException('Two-factor break-glass recovery is available only from the real CLI.');
        }
    }

    private function operatorUser(): string
    {
        $user = get_current_user();

        return $user === '' ? 'unknown' : $user;
    }

    private function operatorHost(): string
    {
        $host = gethostname();

        return is_string($host) && $host !== '' ? $host : 'unknown';
    }
}
