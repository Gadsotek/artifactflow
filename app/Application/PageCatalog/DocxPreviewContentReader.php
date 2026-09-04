<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\ArtifactDerivativeKind;
use App\Models\DocxVersionFact;
use App\Models\PageVersion;
use App\Models\PageVersionDerivative;

final readonly class DocxPreviewContentReader
{
    public function __construct(private ArtifactContentReader $contentReader)
    {
    }

    public function read(PageVersion $version): ?string
    {
        $facts = DocxVersionFact::query()->whereKey($version->uid)->first();
        if (!$facts instanceof DocxVersionFact) {
            return null;
        }
        $derivative = PageVersionDerivative::query()->find($facts->preview_derivative_uid);
        if (
            !$derivative instanceof PageVersionDerivative
            || $derivative->page_version_uid !== $version->uid
            || $derivative->kind !== ArtifactDerivativeKind::DocxPreviewPdf
        ) {
            return null;
        }

        $bytes = $this->contentReader->read($derivative->storage_path, $derivative->byte_size);
        if (
            $bytes === null
            || strlen($bytes) !== $derivative->byte_size
            || !hash_equals($derivative->content_hash, hash('sha256', $bytes))
            || !str_starts_with($bytes, '%PDF-')
            || preg_match('/%%EOF\s*\z/D', $bytes) !== 1
        ) {
            return null;
        }

        return $bytes;
    }
}
