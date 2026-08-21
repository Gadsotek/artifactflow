<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class StagedArtifactContent
{
    private bool $consumed = false;

    public function __construct(
        public readonly string $storagePath,
        public readonly string $contentHash,
        public readonly int $byteSize,
    ) {
    }

    public function promoteTo(string $storagePath, string $failureMessage): void
    {
        if ($this->consumed || !Storage::disk('artifacts')->move($this->storagePath, $storagePath)) {
            throw new RuntimeException($failureMessage);
        }

        $this->consumed = true;
    }

    public function discard(): void
    {
        if ($this->consumed) {
            return;
        }

        try {
            $deleted = Storage::disk('artifacts')->delete($this->storagePath);
        } catch (Throwable) {
            $deleted = false;
        }

        $this->consumed = true;

        if (!$deleted) {
            Log::warning('page.content.staging_delete_failed', [
                'storage_path_hash' => hash('sha256', $this->storagePath),
            ]);
        }
    }
}
