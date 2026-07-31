<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\ExternalSharing\ExternalShareSessionKind;
use App\Models\ExternalShare;
use App\Models\ExternalShareSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class PruneExternalShares
{
    public const int DEFAULT_DELETE_CHUNK_SIZE = 500;

    public function handle(
        int $sessionRetentionHours = 24,
        int $shareRetentionDays = 90,
        bool $dryRun = false,
        int $chunkSize = self::DEFAULT_DELETE_CHUNK_SIZE,
    ): PruneExternalShareResult {
        $sessionCutoff = CarbonImmutable::now()->subHours(max(0, $sessionRetentionHours));
        $shareCutoff = CarbonImmutable::now()->subDays(max(0, $shareRetentionDays));
        $chunkSize = max(1, $chunkSize);

        if ($dryRun) {
            return new PruneExternalShareResult(
                sessionCount: $this->prunableSessions($sessionCutoff)->count(),
                shareCount: $this->prunableShares($shareCutoff)->count(),
            );
        }

        return new PruneExternalShareResult(
            sessionCount: $this->deleteInChunks(
                $this->prunableSessions(...),
                $sessionCutoff,
                $chunkSize,
            ),
            shareCount: $this->deleteInChunks(
                $this->prunableShares(...),
                $shareCutoff,
                $chunkSize,
            ),
        );
    }

    /**
     * @template TModel of Model
     *
     * @param callable(CarbonImmutable): Builder<TModel> $query
     */
    private function deleteInChunks(
        callable $query,
        CarbonImmutable $cutoff,
        int $chunkSize,
    ): int {
        $count = 0;

        do {
            $deleted = $query($cutoff)
                ->limit($chunkSize)
                ->toBase()
                ->delete();
            $count += $deleted;
        } while ($deleted === $chunkSize);

        return $count;
    }

    /**
     * @return Builder<ExternalShareSession>
     */
    private function prunableSessions(CarbonImmutable $cutoff): Builder
    {
        return ExternalShareSession::query()
            ->where('kind', ExternalShareSessionKind::PendingOpen->value)
            ->where('expires_at', '<', $cutoff);
    }

    /**
     * @return Builder<ExternalShare>
     */
    private function prunableShares(CarbonImmutable $cutoff): Builder
    {
        return ExternalShare::query()->where(function (Builder $query) use ($cutoff): void {
            $query
                ->where('revoked_at', '<', $cutoff)
                ->orWhere(function (Builder $query) use ($cutoff): void {
                    $query
                        ->where('mode', ExternalShareMode::OneTime->value)
                        ->where('redeemed_at', '<', $cutoff)
                        ->whereDoesntHave('sessions', function (Builder $query): void {
                            $query->where('kind', ExternalShareSessionKind::View->value);
                        });
                })
                ->orWhere(function (Builder $query) use ($cutoff): void {
                    $query
                        ->where('mode', ExternalShareMode::ExpiresAt->value)
                        ->where('expires_at', '<', $cutoff);
                });
        });
    }
}
