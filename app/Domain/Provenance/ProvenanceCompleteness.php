<?php

declare(strict_types=1);

namespace App\Domain\Provenance;

enum ProvenanceCompleteness: string
{
    case None = 'none';
    case Partial = 'partial';
    case Complete = 'complete';
}
