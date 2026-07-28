<?php

declare(strict_types=1);

namespace App\Application\Provenance;

use App\Domain\Provenance\ExternalReferenceKind;

final readonly class ExternalOriginReferenceView
{
    public function __construct(
        public ExternalReferenceKind $kind,
        public ?string $externalRef,
        public ?string $url,
    ) {
    }
}
