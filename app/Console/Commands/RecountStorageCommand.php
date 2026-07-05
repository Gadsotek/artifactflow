<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\PageCatalog\RecountWorkspaceStorage;
use Illuminate\Console\Command;

final class RecountStorageCommand extends Command
{
    protected $signature = 'artifactflow:recount-storage';

    protected $description = 'Recompute per-workspace used storage counters from page versions and correct any drift.';

    public function handle(RecountWorkspaceStorage $recountWorkspaceStorage): int
    {
        $result = $recountWorkspaceStorage->handle();

        $this->info(sprintf(
            'Workspace storage recount complete: workspaces=%d, corrected=%d.',
            $result->workspacesChecked,
            $result->workspacesCorrected,
        ));

        return 0;
    }
}
