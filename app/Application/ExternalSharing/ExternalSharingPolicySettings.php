<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Models\InstallationSettings;

final class ExternalSharingPolicySettings
{
    public const int DEFAULT_MAX_EXPIRY_HOURS = 168;

    public function current(bool $lockForUpdate = false): ExternalSharingPolicy
    {
        $query = InstallationSettings::query()
            ->where('scope', InstallationSettings::SCOPE_INSTALLATION)
            ->orderBy('created_at');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $settings = $query->first();

        if (!$settings instanceof InstallationSettings) {
            return new ExternalSharingPolicy(
                enabled: false,
                acknowledgementRequired: true,
                maxExpiryHours: self::DEFAULT_MAX_EXPIRY_HOURS,
            );
        }

        return new ExternalSharingPolicy(
            enabled: $settings->external_sharing_enabled,
            acknowledgementRequired: $settings->external_share_acknowledgement_required,
            maxExpiryHours: $settings->external_share_max_expiry_hours,
        );
    }
}
