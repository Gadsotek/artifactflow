<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Installation\InstallationReadiness;
use App\Application\PassageRetrieval\PassageMaintenance;
use Illuminate\Console\Command;

final class ReconcilePassagesCommand extends Command
{
    protected $signature = 'artifactflow:reconcile-passages';
    protected $description = 'Queue bounded passage backfill and retired-generation cleanup.';

    public function handle(InstallationReadiness $readiness, PassageMaintenance $maintenance): int
    {
        if ($readiness->webSchemaIsReady()) {
            $maintenance->dispatchPending();
        }

        return self::SUCCESS;
    }
}
