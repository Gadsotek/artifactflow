<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\PageVersion;

final readonly class PdfArtifactContentReader
{
    public function __construct(
        private ArtifactContentReader $contentReader,
    ) {
    }

    public function read(PageVersion $version): ?string
    {
        $content = $this->contentReader->read(
            $version->content_storage_path,
            $version->byte_size,
        );

        if (
            $content === null
            || strlen($content) !== $version->byte_size
            || !hash_equals($version->content_hash, hash('sha256', $content))
        ) {
            return null;
        }

        return $content;
    }
}
