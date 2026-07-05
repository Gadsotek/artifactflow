<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

enum PageVersionSource: string
{
    case Editor = 'editor';
    case Upload = 'upload';
    case Mcp = 'mcp';
    case Restore = 'restore';
}
