<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\ExternalSharing\ExternalShareStatus;
use App\Domain\PageCatalog\PageStatus;
use App\Models\ExternalShare;
use App\Models\Page;
use Carbon\CarbonImmutable;

final class ExternalShareStatusResolver
{
    public function resolve(
        ExternalShare $share,
        Page $page,
        ?CarbonImmutable $now = null,
    ): ExternalShareStatus {
        if ($share->revoked_at instanceof CarbonImmutable) {
            return ExternalShareStatus::Revoked;
        }

        if ($share->redeemed_at instanceof CarbonImmutable) {
            return ExternalShareStatus::Redeemed;
        }

        $now ??= CarbonImmutable::now();

        if (
            $share->mode === ExternalShareMode::ExpiresAt
            && $share->expires_at instanceof CarbonImmutable
            && $share->expires_at->lessThanOrEqualTo($now)
        ) {
            return ExternalShareStatus::Expired;
        }

        if (
            $page->status === PageStatus::Archived
            || $page->workspace_uid !== $share->page_workspace_uid
            || $page->preview_access_revision !== $share->page_access_revision
        ) {
            return ExternalShareStatus::Invalidated;
        }

        return ExternalShareStatus::Active;
    }
}
