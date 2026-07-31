<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

final class ExternalShareUrl
{
    public function forIssuedShare(IssuedExternalShare $issued): string
    {
        return route('external-shares.bootstrap', [
            'externalShareUid' => $issued->share->uid,
        ]) . '#secret=' . rawurlencode($issued->secret());
    }
}
