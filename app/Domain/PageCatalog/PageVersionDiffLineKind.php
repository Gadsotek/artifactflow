<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

enum PageVersionDiffLineKind: string
{
    case Equal = 'equal';
    case Added = 'added';
    case Removed = 'removed';
    case Omitted = 'omitted';
}
