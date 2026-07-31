<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\PageCatalog\PageStatus;
use App\Models\ExternalShare;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class ActiveExternalShares
{
    public function countForPage(string $pageUid, CarbonImmutable $now): int
    {
        return $this->query($now)->where('external_shares.page_uid', $pageUid)->count();
    }

    public function countForInstallation(CarbonImmutable $now): int
    {
        return $this->query($now)->count();
    }

    /**
     * @return Builder<ExternalShare>
     */
    private function query(CarbonImmutable $now): Builder
    {
        return ExternalShare::query()
            ->join('pages', 'pages.uid', '=', 'external_shares.page_uid')
            ->whereNull('external_shares.revoked_at')
            ->where('pages.status', '!=', PageStatus::Archived)
            ->whereColumn('pages.workspace_uid', 'external_shares.page_workspace_uid')
            ->whereColumn('pages.preview_access_revision', 'external_shares.page_access_revision')
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->where(function (Builder $oneTime): void {
                        $oneTime
                            ->where('external_shares.mode', ExternalShareMode::OneTime)
                            ->whereNull('external_shares.redeemed_at');
                    })
                    ->orWhere(function (Builder $expiring) use ($now): void {
                        $expiring
                            ->where('external_shares.mode', ExternalShareMode::ExpiresAt)
                            ->where('external_shares.expires_at', '>', $now);
                    });
            });
    }
}
