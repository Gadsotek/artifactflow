<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Models\InstallationSettings;
use Illuminate\Support\Facades\DB;

final class InstallationSettingsWriter
{
    public function locked(): ?InstallationSettings
    {
        // A row lock alone cannot serialize the first two administrative writes.
        DB::select("SELECT pg_advisory_xact_lock(hashtextextended('artifactflow:installation-settings', 0))");

        return InstallationSettings::query()->where('scope', InstallationSettings::SCOPE_INSTALLATION)
            ->lockForUpdate()->first();
    }
}
