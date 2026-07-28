<?php

declare(strict_types=1);

namespace App\Domain\Provenance;

enum ExternalReferenceKind: string
{
    case Artifact = 'artifact';
    case Conversation = 'conversation';
    case Session = 'session';
    case Source = 'source';
}
