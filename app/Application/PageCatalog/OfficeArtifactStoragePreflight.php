<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersionDerivative;
use App\Models\Workspace;

/**
 * Rejects Office processor work that cannot possibly fit in storage.
 *
 * The exact derivative size is unknowable until the isolated processor returns,
 * so the locked persistence path remains the quota check of record. This
 * optimistic check uses only the input bytes and a one-byte lower bound for the
 * required derivative; it therefore rejects provably impossible work without
 * reserving the maximum response size or blocking a replacement that may shrink.
 */
final readonly class OfficeArtifactStoragePreflight
{
    private const int MINIMUM_DERIVATIVE_BYTES = 1;

    public function __construct(private WorkspaceStorageQuota $storageQuota)
    {
    }

    public function forNewPage(Workspace $workspace, PageType $type, string $content): void
    {
        $minimumBytes = $this->minimumNewVersionBytes($type, $content);

        if ($minimumBytes !== null) {
            $this->storageQuota->preflightNewPageStorage($workspace, $minimumBytes);
        }
    }

    public function forVersionAppend(Page $page, string $content): void
    {
        $minimumBytes = $this->minimumNewVersionBytes($page->type, $content);

        if ($minimumBytes !== null) {
            $this->storageQuota->preflightVersionAppendStorage($page, $minimumBytes);
        }
    }

    public function forDerivativeReplacement(Page $page, PageVersionDerivative $derivative): void
    {
        $this->storageQuota->preflightDerivativeReplacementStorage(
            $page,
            $derivative->byte_size,
            self::MINIMUM_DERIVATIVE_BYTES,
        );
    }

    private function minimumNewVersionBytes(PageType $type, string $content): ?int
    {
        if ($type !== PageType::Xlsx && $type !== PageType::Docx) {
            return null;
        }

        return strlen($content) + self::MINIMUM_DERIVATIVE_BYTES;
    }
}
