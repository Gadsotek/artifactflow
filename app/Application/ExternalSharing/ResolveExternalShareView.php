<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Domain\ExternalSharing\ExternalShareSessionKind;
use App\Models\ExternalShare;
use App\Models\ExternalShareSession;
use App\Models\Page;
use Carbon\CarbonImmutable;

final readonly class ResolveExternalShareView
{
    public function __construct(
        private ExternalShareSessionCredential $credentials,
        private ExternalSharingPolicySettings $policySettings,
        private ExternalShareLiveState $liveState,
    ) {
    }

    public function fromCredential(
        string $externalShareUid,
        #[\SensitiveParameter]
        string $credential,
    ): ?ExternalShareViewContext {
        $credentialHash = $this->credentials->hashForLookup(
            ExternalShareSessionKind::View,
            $credential,
        );

        if ($credentialHash === null) {
            return null;
        }

        $session = ExternalShareSession::query()
            ->where('external_share_uid', $externalShareUid)
            ->where('credential_hash', $credentialHash)
            ->where('kind', ExternalShareSessionKind::View->value)
            ->first();

        return $session instanceof ExternalShareSession
            ? $this->fromSession($externalShareUid, $session)
            : null;
    }

    public function fromSessionUid(
        string $externalShareUid,
        string $sessionUid,
    ): ?ExternalShareViewContext {
        $session = ExternalShareSession::query()
            ->whereKey($sessionUid)
            ->where('external_share_uid', $externalShareUid)
            ->where('kind', ExternalShareSessionKind::View->value)
            ->first();

        return $session instanceof ExternalShareSession
            ? $this->fromSession($externalShareUid, $session)
            : null;
    }

    private function fromSession(
        string $externalShareUid,
        ExternalShareSession $session,
    ): ?ExternalShareViewContext {
        $share = ExternalShare::query()->find($externalShareUid);

        if (!$share instanceof ExternalShare) {
            return null;
        }

        $page = Page::query()->find($share->page_uid);

        if (!$page instanceof Page) {
            return null;
        }

        $policy = $this->policySettings->current();

        return $this->liveState->canUseViewSession(
            $policy,
            $share,
            $page,
            $session,
            CarbonImmutable::now(),
        )
            ? new ExternalShareViewContext($share, $page, $session)
            : null;
    }
}
