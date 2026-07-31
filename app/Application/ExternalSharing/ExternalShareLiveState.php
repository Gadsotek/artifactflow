<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\ExternalSharing\ExternalShareSessionKind;
use App\Domain\PageCatalog\PageStatus;
use App\Models\ExternalShare;
use App\Models\ExternalShareSession;
use App\Models\Page;
use Carbon\CarbonImmutable;

final class ExternalShareLiveState
{
    public function canStartSession(
        ExternalSharingPolicy $policy,
        ExternalShare $share,
        Page $page,
        CarbonImmutable $now,
    ): bool {
        if (!$policy->enabled || !$this->pageMatches($share, $page)) {
            return false;
        }

        if ($share->revoked_at instanceof CarbonImmutable || $share->redeemed_at instanceof CarbonImmutable) {
            return false;
        }

        return $share->mode === ExternalShareMode::OneTime
            || (
                $share->expires_at instanceof CarbonImmutable
                && $share->expires_at->greaterThan($now)
            );
    }

    public function canUseViewSession(
        ExternalSharingPolicy $policy,
        ExternalShare $share,
        Page $page,
        ExternalShareSession $session,
        CarbonImmutable $now,
    ): bool {
        if (
            !$policy->enabled
            || !$this->pageMatches($share, $page)
            || $share->revoked_at instanceof CarbonImmutable
            || $session->kind !== ExternalShareSessionKind::View->value
            || $session->consumed_at instanceof CarbonImmutable
            || $session->expires_at instanceof CarbonImmutable
        ) {
            return false;
        }

        if ($share->mode === ExternalShareMode::OneTime) {
            return $share->redeemed_at instanceof CarbonImmutable;
        }

        return $share->expires_at instanceof CarbonImmutable
            && $share->expires_at->greaterThan($now);
    }

    private function pageMatches(ExternalShare $share, Page $page): bool
    {
        return $page->status !== PageStatus::Archived
            && $page->current_version_uid !== null
            && $page->workspace_uid === $share->page_workspace_uid
            && $page->preview_access_revision === $share->page_access_revision;
    }
}
