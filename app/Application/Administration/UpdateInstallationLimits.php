<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Models\InstallationSettings;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class UpdateInstallationLimits
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private InstallationLimitSettings $limits,
        private RealtimeConfiguration $realtime,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, InstallationLimitValues $values): InstallationSettings
    {
        if (!$actor->is_system_admin) {
            throw new AuthorizationException('Only system admins can update installation limits.');
        }

        if ($values->realtimeEnabled && !$this->realtime->configured()) {
            throw new DomainRuleViolation('Realtime can only be enabled when Reverb is configured.');
        }

        $settings = DB::transaction(function () use ($actor, $values): InstallationSettings {
            $settings = InstallationSettings::query()
                ->where('scope', InstallationSettings::SCOPE_INSTALLATION)
                ->lockForUpdate()
                ->orderBy('created_at')
                ->first();

            if (!$settings instanceof InstallationSettings) {
                $settings = new InstallationSettings();
            }

            $settings->forceFill($values->toPersistenceArray() + [
                'scope' => InstallationSettings::SCOPE_INSTALLATION,
                'updated_by_user_uid' => $actor->uid,
            ])->save();

            $payload = $values->toPersistenceArray() + [
                'updated_by_user_uid' => $actor->uid,
            ];
            $event = $this->events->record(
                eventType: DomainEventType::InstallationLimitsUpdated,
                aggregateType: 'installation_settings',
                aggregateUid: $settings->uid,
                payload: $payload,
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actor->uid,
                auditableType: 'installation_settings',
                auditableUid: $settings->uid,
                action: DomainEventType::InstallationLimitsUpdated,
                summary: 'Installation limits updated.',
                metadata: $payload,
            );

            return $settings;
        });

        $this->limits->forgetCachedValues();

        return $settings;
    }
}
