<?php

declare(strict_types=1);

namespace App\Application\Installation;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;

final class InstallationReadiness
{
    public function __construct(
        private readonly Migrator $migrator,
    ) {
    }

    public function webSchemaIsReady(): bool
    {
        $availableMigrations = array_keys($this->migrator->getMigrationFiles(database_path('migrations')));

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

        return true;
    }
}
