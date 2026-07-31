<?php

declare(strict_types=1);

namespace App\Domain\ExternalSharing;

enum ExternalShareSessionKind: string
{
    case PendingOpen = 'pending_open';
    case View = 'view';
}
