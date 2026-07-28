<?php

declare(strict_types=1);

namespace App\Domain\Provenance;

enum ProducerKind: string
{
    case Ai = 'ai';
    case Human = 'human';
    case Software = 'software';
}
