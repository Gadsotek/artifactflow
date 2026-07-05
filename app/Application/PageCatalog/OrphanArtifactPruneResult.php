<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class OrphanArtifactPruneResult
{
    /**
     * @param list<string> $sampleOrphanPaths
     */
    public function __construct(
        public int $filesScanned,
        public int $orphansFound,
        public int $orphansDeleted,
        public int $recentSkipped,
        public bool $deleteRequested,
        public array $sampleOrphanPaths,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'files_scanned' => $this->filesScanned,
            'orphans_found' => $this->orphansFound,
            'orphans_deleted' => $this->orphansDeleted,
            'recent_skipped' => $this->recentSkipped,
            'delete_requested' => $this->deleteRequested,
            'sample_orphan_paths' => $this->sampleOrphanPaths,
        ];
    }
}
