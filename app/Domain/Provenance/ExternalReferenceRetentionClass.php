<?php

declare(strict_types=1);

namespace App\Domain\Provenance;

enum ExternalReferenceRetentionClass: string
{
    case Sensitive = 'sensitive';
}
