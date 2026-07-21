<?php

declare(strict_types=1);

namespace App\Application\Administration;

final class InstallationLimitCeilings
{
    // Keep configurable writes inside the production edge's 6 MB multipart cap
    // and PHP's larger request limits. Raising this requires coordinating every
    // request-body boundary and its browser-level regression first.
    public const int CONTENT_BYTES = 5 * 1024 * 1024;
    public const int ARTIFACT_READ_BYTES = 64 * 1024 * 1024;
    public const int WORKSPACE_STORAGE_BYTES = 10 * 1024 * 1024 * 1024;
    public const int PAGE_STORAGE_BYTES = 1024 * 1024 * 1024;
    public const int PAGE_VERSIONS = 1000000;
    public const int TAGS_PER_PAGE = 1000000;
}
