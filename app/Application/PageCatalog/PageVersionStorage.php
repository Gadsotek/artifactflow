<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final readonly class PageVersionStorage
{
    /**
     * @return list<string>
     */
    public function paths(PageVersion $version): array
    {
        $paths = [$version->content_storage_path];

        foreach ($this->derivatives($version) as $derivative) {
            $paths[] = $derivative->storage_path;
        }

        return $paths;
    }

    public function bytes(PageVersion $version): int
    {
        $bytes = $version->byte_size;

        foreach ($this->derivatives($version) as $derivative) {
            $bytes += $derivative->byte_size;
        }

        return $bytes;
    }

    /**
     * @return EloquentCollection<int, PageVersionDerivative>
     */
    private function derivatives(PageVersion $version): EloquentCollection
    {
        if ($version->relationLoaded('derivatives')) {
            $loaded = $version->getRelation('derivatives');

            if ($loaded instanceof EloquentCollection) {
                return $loaded;
            }
        }

        return $version->derivatives()->get();
    }
}
