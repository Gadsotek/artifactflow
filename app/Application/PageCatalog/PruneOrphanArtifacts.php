<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\PageVersion;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Reaper for orphaned artifact blobs: files on the `artifacts` disk that no
 * page_versions row references. Orphans arise from the best-effort cleanup in
 * HardDeletePage (the row/quota mutation commits inside the transaction, but the
 * subsequent Storage delete runs outside it and can fail -- see the
 * PageArtifactDeleteFailed audit path) and from writes interrupted after the
 * blob is put but before the version row commits.
 *
 * Safety window: PageVersionWriter writes the blob *then* inserts the row, so a
 * blob that is legitimately mid-append momentarily has no referencing row. Files
 * younger than the min-age window are therefore never pruned, so this reaper
 * cannot race an in-flight version append. Deletion is opt-in ($delete); the
 * default pass only reports what it would remove.
 *
 * The set of referenced paths is held in memory (bounded by the version count);
 * this is an operator-run maintenance command, not a request-path hot loop.
 */
final readonly class PruneOrphanArtifacts
{
    private const int KNOWN_PATH_BATCH_SIZE = 1000;
    private const int MAX_SAMPLE_PATHS = 20;

    public function handle(bool $delete, int $minAgeSeconds): OrphanArtifactPruneResult
    {
        $disk = Storage::disk('artifacts');
        $referencedPaths = $this->referencedStoragePaths();
        $cutoff = Carbon::now()->getTimestamp() - max(0, $minAgeSeconds);

        $filesScanned = 0;
        $orphansFound = 0;
        $orphansDeleted = 0;
        $recentSkipped = 0;
        $sampleOrphanPaths = [];

        foreach ($disk->allFiles() as $path) {
            if (!is_string($path)) {
                continue;
            }

            $filesScanned++;

            if (isset($referencedPaths[$path])) {
                continue;
            }

            if ((int) $disk->lastModified($path) > $cutoff) {
                // Too new to be sure it is not a blob whose version row is still
                // mid-commit; leave it for a later pass once it ages out.
                $recentSkipped++;

                continue;
            }

            $orphansFound++;

            if (count($sampleOrphanPaths) < self::MAX_SAMPLE_PATHS) {
                $sampleOrphanPaths[] = $path;
            }

            if ($delete && $disk->delete($path)) {
                $orphansDeleted++;
            }
        }

        return new OrphanArtifactPruneResult(
            filesScanned: $filesScanned,
            orphansFound: $orphansFound,
            orphansDeleted: $orphansDeleted,
            recentSkipped: $recentSkipped,
            deleteRequested: $delete,
            sampleOrphanPaths: $sampleOrphanPaths,
        );
    }

    /**
     * @return array<string, true>
     */
    private function referencedStoragePaths(): array
    {
        $paths = [];

        PageVersion::query()
            ->select(['uid', 'content_storage_path'])
            ->orderBy('uid')
            ->chunkById(
                self::KNOWN_PATH_BATCH_SIZE,
                /**
                 * @param EloquentCollection<int, PageVersion> $versions
                 */
                function (EloquentCollection $versions) use (&$paths): void {
                    foreach ($versions as $version) {
                        $paths[$version->content_storage_path] = true;
                    }
                },
                'uid',
            );

        return $paths;
    }
}
