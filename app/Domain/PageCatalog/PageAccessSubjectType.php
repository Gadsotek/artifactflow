<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

enum PageAccessSubjectType: string
{
    case User = 'user';
    case Workspace = 'workspace';
}
