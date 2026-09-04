<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

enum DocumentOriginalPurpose: string
{
    case Current = 'current_download';
    case History = 'history_download';
}
