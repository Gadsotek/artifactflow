<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

enum ArtifactPreviewPurpose: string
{
    case Current = 'current';
    case History = 'history';
}
