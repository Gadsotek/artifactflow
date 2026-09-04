<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\ArtifactDerivativeKind;
use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use App\Models\XlsxVersionFact;

final readonly class XlsxManifestContentReader
{
    public function __construct(
        private ArtifactContentReader $contentReader,
        private XlsxManifestValidator $validator,
    ) {
    }

    public function read(PageVersion $version): ?string
    {
        $facts = XlsxVersionFact::query()->whereKey($version->uid)->first();

        if (!$facts instanceof XlsxVersionFact) {
            return null;
        }

        $derivative = PageVersionDerivative::query()->find($facts->manifest_derivative_uid);

        if (
            !$derivative instanceof PageVersionDerivative
            || $derivative->page_version_uid !== $version->uid
            || $derivative->kind !== ArtifactDerivativeKind::XlsxManifest
        ) {
            return null;
        }

        $manifest = $this->contentReader->read($derivative->storage_path, $derivative->byte_size);

        if (
            $manifest === null
            || strlen($manifest) !== $derivative->byte_size
            || !hash_equals($derivative->content_hash, hash('sha256', $manifest))
        ) {
            return null;
        }

        try {
            $decoded = json_decode($manifest, true, 32, JSON_THROW_ON_ERROR);

            if (!is_array($decoded) || array_is_list($decoded)) {
                return null;
            }

            /** @var array<string, mixed> $manifestObject */
            $manifestObject = [];

            foreach ($decoded as $key => $value) {
                if (!is_string($key)) {
                    return null;
                }

                $manifestObject[$key] = $value;
            }

            $canonical = $this->validator->validate($manifestObject)->manifestJson;
        } catch (\Throwable) {
            return null;
        }

        return hash_equals($canonical, $manifest) ? $canonical : null;
    }
}
