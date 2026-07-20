<?php

declare(strict_types=1);

namespace App\Application\Installation;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;

final class InstallationReadiness
{
    private ?string $confirmedMigrationManifestHash = null;

    public function __construct(
        private readonly Migrator $migrator,
    ) {
    }

    public function webSchemaIsReady(): bool
    {
        $availableMigrations = array_keys($this->migrator->getMigrationFiles(database_path('migrations')));
        $migrationManifestHash = hash('sha256', implode("\0", $availableMigrations));

        // Cache success only for the exact migration manifest that was checked.
        // Immutable-image deployments naturally start a new process, while a
        // live-mounted or otherwise hot-updated process sees a new filename hash
        // and immediately rechecks the migration repository. Failed checks remain
        // uncached so the first request after `migrate` can proceed.
        if ($migrationManifestHash === $this->confirmedMigrationManifestHash) {
            return true;
        }

        if (!$this->migrator->repositoryExists()) {
            return false;
        }

        $completedMigrations = $this->migrator->getRepository()->getRan();

        if (array_diff($availableMigrations, $completedMigrations) !== []) {
            return false;
        }

        // Do not trust migration bookkeeping alone. These two tables are needed
        // before Laravel can start a database-backed browser session and resolve
        // an authenticated user, so corruption or a partial initial migration
        // must fail closed before StartSession executes.
        if (!Schema::hasTable('sessions') || !Schema::hasTable('users')) {
            return false;
        }

        $this->confirmedMigrationManifestHash = $migrationManifestHash;

        return true;
    }
}
