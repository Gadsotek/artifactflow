<?php

declare(strict_types=1);

namespace App\Domain\Provenance;

enum ProvenanceEvidenceType: string
{
    case SelfReported = 'self_reported';
    case ClientAsserted = 'client_asserted';
    case BridgeAsserted = 'bridge_asserted';
    case ProviderAttested = 'provider_attested';

    public function strength(): int
    {
        return match ($this) {
            self::SelfReported => 1,
            self::ClientAsserted => 2,
            self::BridgeAsserted => 3,
            self::ProviderAttested => 4,
        };
    }
}
