<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

enum PageSearchSort: string
{
    case Relevance = 'relevance';
    case RecentlyUpdated = 'recently_updated';
    case Title = 'title';
}
