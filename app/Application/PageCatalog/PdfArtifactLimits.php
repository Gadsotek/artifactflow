<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Administration\InstallationLimitCeilings;
use App\Application\Administration\InstallationLimitSettings;

final readonly class PdfArtifactLimits
{
    public function __construct(
        private InstallationLimitSettings $installationLimits,
    ) {
    }

    public function maxUploadBytes(): int
    {
        return min(
            $this->installationLimits->integer('pages.artifact_max_bytes'),
            InstallationLimitCeilings::ARTIFACT_READ_BYTES,
            PdfProcessorConfiguration::MAX_INPUT_BYTES,
        );
    }
}
