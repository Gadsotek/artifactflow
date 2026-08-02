<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Models\ExternalShare;
use Carbon\CarbonImmutable;

final readonly class RecordExternalShareViewActivity
{
    private const int WRITE_INTERVAL_MINUTES = 5;

    public function record(ExternalShare $share, CarbonImmutable $now): void
    {
        if (
            $share->last_viewed_at instanceof CarbonImmutable
            && $share->last_viewed_at->greaterThan($now->subMinutes(self::WRITE_INTERVAL_MINUTES))
        ) {
            return;
        }

        $share->forceFill(['last_viewed_at' => $now])->save();
    }
}
