<?php

declare(strict_types=1);

namespace App\Application\Provenance;

final readonly class VersionProvenanceInput
{
    /**
     * @param list<ProducerAssertionInput> $producers
     */
    public function __construct(public array $producers)
    {
    }

    public function wasSupplied(): bool
    {
        return $this->producers !== [];
    }
}
