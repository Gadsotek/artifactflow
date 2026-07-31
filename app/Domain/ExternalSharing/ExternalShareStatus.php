<?php

declare(strict_types=1);

namespace App\Domain\ExternalSharing;

enum ExternalShareStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Invalidated = 'invalidated';
    case Redeemed = 'redeemed';
    case Revoked = 'revoked';
}
