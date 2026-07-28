<?php

declare(strict_types=1);

namespace App\Domain\Provenance;

enum VersionOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Restore = 'restore';
}
