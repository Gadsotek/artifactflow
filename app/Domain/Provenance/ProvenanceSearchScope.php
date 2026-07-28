<?php

declare(strict_types=1);

namespace App\Domain\Provenance;

enum ProvenanceSearchScope: string
{
    case PageOrigin = 'page_origin';
    case CurrentVersion = 'current_version';
    case AnyVersion = 'any_version';
}
