<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class ArtifactDraftPreviewCapability
{
    public function __construct(
        public string $token,
        public int $expiresAt,
    ) {
    }
}
