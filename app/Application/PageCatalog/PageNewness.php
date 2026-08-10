<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\Page;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class PageNewness
{
    public function isNew(Page $page, CarbonImmutable $now): bool
    {
        $createdAt = $page->created_at;

        return $createdAt instanceof CarbonInterface
            && !$createdAt->isAfter($now)
            && !$createdAt->isBefore($now->subHour());
    }
}
