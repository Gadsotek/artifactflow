<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles workspaces.used_storage_bytes against the authoritative sum of
 * retained originals and their derivatives. The counter is maintained
 * transactionally by the version write/delete/move handlers; this recount
 * exists as an operational safety net for drift introduced outside them.
 */
final readonly class RecountWorkspaceStorage
{
    private const int BATCH_SIZE = 100;

    public function handle(): RecountWorkspaceStorageResult
    {
        $workspacesChecked = 0;
        $workspacesCorrected = 0;

        Workspace::query()
            ->orderBy('uid')
            ->chunkById(
                self::BATCH_SIZE,
                /**
                 * @param EloquentCollection<int, Workspace> $workspaces
                 */
                function (EloquentCollection $workspaces) use (&$workspacesChecked, &$workspacesCorrected): void {
                    foreach ($workspaces as $workspace) {
                        $workspacesChecked++;

                        if ($this->recountWorkspace($workspace->uid)) {
                            $workspacesCorrected++;
                        }
                    }
                },
                'uid',
            );

        return new RecountWorkspaceStorageResult(
            workspacesChecked: $workspacesChecked,
            workspacesCorrected: $workspacesCorrected,
        );
    }

    private function recountWorkspace(string $workspaceUid): bool
    {
        return DB::transaction(function () use ($workspaceUid): bool {
            $workspace = Workspace::query()
                ->whereKey($workspaceUid)
                ->lockForUpdate()
                ->first();

            if (!$workspace instanceof Workspace) {
                return false;
            }

            $originalBytes = (int) PageVersion::query()
                ->join('pages', 'page_versions.page_uid', '=', 'pages.uid')
                ->where('pages.workspace_uid', $workspace->uid)
                ->sum('page_versions.byte_size');
            $derivativeBytes = (int) PageVersionDerivative::query()
                ->join('page_versions', 'page_version_derivatives.page_version_uid', '=', 'page_versions.uid')
                ->join('pages', 'page_versions.page_uid', '=', 'pages.uid')
                ->where('pages.workspace_uid', $workspace->uid)
                ->sum('page_version_derivatives.byte_size');
            $actualBytes = $originalBytes + $derivativeBytes;

            if ($workspace->used_storage_bytes === $actualBytes) {
                return false;
            }

            $workspace->forceFill(['used_storage_bytes' => $actualBytes])->save();

            return true;
        });
    }
}
