<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Domain\ExternalSharing\ExternalShareSessionKind;
use App\Models\ExternalShare;
use App\Models\ExternalShareSession;
use App\Models\Page;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

final readonly class ResolveExternalShareView
{
    public function __construct(
        private ExternalShareSessionCredential $credentials,
        private ExternalSharingPolicySettings $policySettings,
        private ExternalShareLiveState $liveState,
    ) {
    }

    /**
     * @template TResult
     *
     * @param Closure(ExternalShareViewContext): TResult $use
     *
     * @return TResult|null
     */
    public function withCredential(
        string $externalShareUid,
        string $sessionUid,
        #[\SensitiveParameter]
        string $credential,
        Closure $use,
    ): mixed {
        $credentialHash = $this->credentials->hashForLookup(
            ExternalShareSessionKind::View,
            $credential,
        );

        if ($credentialHash === null) {
            return null;
        }

        return $this->withSession(
            $externalShareUid,
            credentialHash: $credentialHash,
            sessionUid: $sessionUid,
            use: $use,
        );
    }

    /**
     * @template TResult
     *
     * @param Closure(ExternalShareViewContext): TResult $use
     *
     * @return TResult|null
     */
    public function withSessionUid(
        string $externalShareUid,
        string $sessionUid,
        Closure $use,
    ): mixed {
        return $this->withSession(
            $externalShareUid,
            credentialHash: null,
            sessionUid: $sessionUid,
            use: $use,
        );
    }

    /**
     * @template TResult
     *
     * @param Closure(ExternalShareViewContext): TResult $use
     *
     * @return TResult|null
     */
    private function withSession(
        string $externalShareUid,
        ?string $credentialHash,
        ?string $sessionUid,
        Closure $use,
    ): mixed {
        $pageUid = ExternalShare::query()
            ->whereKey($externalShareUid)
            ->value('page_uid');

        if (!is_string($pageUid)) {
            return null;
        }

        return DB::transaction(function () use (
            $externalShareUid,
            $pageUid,
            $credentialHash,
            $sessionUid,
            $use,
        ): mixed {
            $page = Page::query()
                ->whereKey($pageUid)
                ->lockForUpdate()
                ->first();
            $share = ExternalShare::query()
                ->whereKey($externalShareUid)
                ->where('page_uid', $pageUid)
                ->lockForUpdate()
                ->first();

            if (!$page instanceof Page || !$share instanceof ExternalShare) {
                return null;
            }

            $sessionQuery = ExternalShareSession::query()
                ->where('external_share_uid', $externalShareUid)
                ->where('kind', ExternalShareSessionKind::View->value);

            if ($credentialHash !== null) {
                $sessionQuery->where('credential_hash', $credentialHash);
            }

            if ($sessionUid !== null) {
                $sessionQuery->whereKey($sessionUid);
            }

            if ($credentialHash === null && $sessionUid === null) {
                return null;
            }

            $session = $sessionQuery->lockForUpdate()->first();
            $policy = $this->policySettings->current();

            if (
                !$session instanceof ExternalShareSession
                || !$this->liveState->canUseViewSession(
                    $policy,
                    $share,
                    $page,
                    $session,
                    CarbonImmutable::now(),
                )
            ) {
                return null;
            }

            return $use(new ExternalShareViewContext($share, $page, $session));
        });
    }
}
