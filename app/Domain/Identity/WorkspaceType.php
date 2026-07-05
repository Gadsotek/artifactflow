<?php

declare(strict_types=1);

namespace App\Domain\Identity;

enum WorkspaceType: string
{
    case Personal = 'personal';
    case Shared = 'shared';
}
