<?php

declare(strict_types=1);

namespace App\Domain\Provenance;

enum ProducerIdentityPrecision: string
{
    case ExactModel = 'exact_model';
    case ModelIdOnly = 'model_id_only';
    case ModelLabel = 'model_label';
    case ProviderOnly = 'provider_only';
    case Named = 'named';
    case Unspecified = 'unspecified';
}
