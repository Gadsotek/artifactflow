<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

enum PageAccessMode: string
{
    case Inherited = 'inherited';
    case Restricted = 'restricted';
}
