<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

class ArtifactContentDeleter
{
    /**
     * @param list<string> $storagePaths
     */
    public function deleteMany(array $storagePaths): bool
    {
        if ($storagePaths === []) {
            return true;
        }

        try {
            return Storage::disk('artifacts')->delete($storagePaths) !== false;
        } catch (FilesystemException) {
            return false;
        }
    }
}
