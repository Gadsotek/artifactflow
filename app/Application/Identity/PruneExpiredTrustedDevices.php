<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\TrustedDevice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Reaps trusted-device rows once they have been expired past the retention
 * window. An expired "remember this device" cookie can no longer skip the TOTP
 * challenge (TrustedDeviceManager filters on expires_at), so the row is dead
 * weight; deleting it also shrinks the pool of SHA-256 token hashes at rest.
 */
final readonly class PruneExpiredTrustedDevices
{
    public const int DEFAULT_DELETE_CHUNK_SIZE = 500;

    public function handle(
        int $retentionDays,
        bool $dryRun = false,
        int $chunkSize = self::DEFAULT_DELETE_CHUNK_SIZE,
    ): int {
        $retentionDays = max(0, $retentionDays);
        $chunkSize = max(1, $chunkSize);
        $cutoff = Carbon::now()->subDays($retentionDays);

        if ($dryRun) {
            return $this->prunable($cutoff)->count();
        }

        $prunedCount = 0;

        do {
            // Chunked deletes keep each statement short so the reaper never holds a
            // long lock against the table while sessions keep issuing new devices.
            $deletedCount = $this->prunable($cutoff)
                ->limit($chunkSize)
                ->toBase()
                ->delete();
            $prunedCount += $deletedCount;
        } while ($deletedCount === $chunkSize);

        return $prunedCount;
    }

    /**
     * @return Builder<TrustedDevice>
     */
    private function prunable(Carbon $cutoff): Builder
    {
        return TrustedDevice::query()->where('expires_at', '<', $cutoff);
    }
}
