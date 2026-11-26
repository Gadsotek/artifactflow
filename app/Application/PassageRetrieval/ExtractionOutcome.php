<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

enum ExtractionOutcome: string
{
    case Complete = 'complete';
    case Limited = 'limited';
    case Empty = 'empty';
}
