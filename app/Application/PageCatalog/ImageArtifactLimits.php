<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Administration\InstallationLimitCeilings;
use App\Application\Administration\InstallationLimitSettings;
use LogicException;

final readonly class ImageArtifactLimits
{
    public const int MAX_UPLOAD_BYTES = InstallationLimitCeilings::CONTENT_BYTES;

    public const int MAX_UPLOAD_PIXELS = 16 * 1024 * 1024;

    public const int MAX_PNG_ANCILLARY_BYTES = 1024 * 1024;

    public const int MAX_PNG_CHUNKS = 1024;

    /**
     * Stored versions are immutable and must remain readable when an operator
     * lowers a write-side image limit. These ceilings are the parser's permanent
     * raster envelope; configurable limits may narrow new uploads but never
     * retroactively narrow retained versions.
     */
    public const int STORED_MAX_PIXELS = 40 * 1024 * 1024;

    public const int STORED_MAX_DIMENSION = 16384;

    public function __construct(
        private InstallationLimitSettings $installationLimits,
    ) {
    }

    public function maxUploadBytes(): int
    {
        return min(
            $this->positiveInt('pages.max_image_bytes'),
            $this->maxStoredBytes(),
            self::MAX_UPLOAD_BYTES,
        );
    }

    public function maxStoredBytes(): int
    {
        return min(
            $this->installationLimits->integer('pages.artifact_max_bytes'),
            InstallationLimitCeilings::ARTIFACT_READ_BYTES,
        );
    }

    public function maxUploadPixels(): int
    {
        return min($this->positiveInt('pages.max_image_pixels'), self::MAX_UPLOAD_PIXELS);
    }

    public function maxUploadDimension(): int
    {
        return min($this->positiveInt('pages.max_image_dimension'), self::STORED_MAX_DIMENSION);
    }

    private function positiveInt(string $key): int
    {
        $configured = config($key);

        if (!PositiveIntegerConfiguration::isIntegerLike($configured)) {
            throw new LogicException(sprintf('Configured image limit [%s] must be an integer.', $key));
        }

        $limit = PositiveIntegerConfiguration::tryFrom($configured);

        if ($limit === null) {
            throw new LogicException(sprintf('Configured image limit [%s] must be positive.', $key));
        }

        return $limit;
    }
}
