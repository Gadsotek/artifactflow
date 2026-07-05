<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

enum PageSecurityScanStatus: string
{
    case Clean = 'clean';
    case Warnings = 'warnings';
}
