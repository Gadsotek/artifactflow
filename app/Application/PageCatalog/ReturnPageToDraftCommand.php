<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class ReturnPageToDraftCommand
{
    public function __construct(public string $pageUid)
    {
    }
}
