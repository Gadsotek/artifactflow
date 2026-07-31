<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\ExternalSharing\ExternalShareSessionKind;
use App\Models\ExternalShare;
use App\Models\ExternalShareSession;
use App\Models\Page;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ExchangeExternalShare
{
    private const string DUMMY_SECRET_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private ExternalShareSecret $shareSecrets,
        private ExternalShareSessionCredential $sessionCredentials,
        private ExternalShareOpenCsrf $csrf,
        private ExternalSharingPolicySettings $policySettings,
        private ExternalShareLiveState $liveState,
        private IssueExternalShareViewSession $viewSessions,
    ) {
    }

    public function handle(
        string $externalShareUid,
        #[\SensitiveParameter]
        string $secret,
    ): ?ExternalShareExchangeResult {
        $candidate = ExternalShare::query()->find($externalShareUid);
        $expectedHash = $candidate instanceof ExternalShare
            ? $candidate->secret_hash
            : self::DUMMY_SECRET_HASH;

        if (!$this->shareSecrets->matches($secret, $expectedHash) || !$candidate instanceof ExternalShare) {
            return null;
        }

        return DB::transaction(function () use ($candidate, $externalShareUid, $secret): ?ExternalShareExchangeResult {
            $page = Page::query()
                ->whereKey($candidate->page_uid)
                ->lockForUpdate()
                ->first();

            if (!$page instanceof Page) {
                return null;
            }

            $share = ExternalShare::query()
                ->whereKey($externalShareUid)
                ->where('page_uid', $page->uid)
                ->lockForUpdate()
                ->first();

            if (
                !$share instanceof ExternalShare
                || !$this->shareSecrets->matches($secret, $share->secret_hash)
            ) {
                return null;
            }

            $policy = $this->policySettings->current(lockForUpdate: true);
            $now = CarbonImmutable::now();

            if (!$this->liveState->canStartSession($policy, $share, $page, $now)) {
                return null;
            }

            if (!$policy->acknowledgementRequired && $share->mode === ExternalShareMode::ExpiresAt) {
                return new ExternalShareExchangeResult(
                    share: $share,
                    page: $page,
                    issuedSession: $this->viewSessions->issue($share, $page, $now),
                    acknowledgementRequired: false,
                    csrfToken: null,
                );
            }

            ExternalShareSession::query()
                ->where('external_share_uid', $share->uid)
                ->where('kind', ExternalShareSessionKind::PendingOpen->value)
                ->where(function ($query) use ($now): void {
                    $query
                        ->whereNotNull('consumed_at')
                        ->orWhere('expires_at', '<=', $now);
                })
                ->delete();
            $generated = $this->sessionCredentials->generate(ExternalShareSessionKind::PendingOpen);
            $session = ExternalShareSession::query()->forceCreate([
                'external_share_uid' => $share->uid,
                'credential_hash' => $generated->hash,
                'kind' => ExternalShareSessionKind::PendingOpen->value,
                'expires_at' => $now->addSeconds(ExternalShareSessionLifetime::PENDING_OPEN_SECONDS),
            ]);

            return new ExternalShareExchangeResult(
                share: $share,
                page: $page,
                issuedSession: new IssuedExternalShareSession($session, $generated->reveal()),
                acknowledgementRequired: $policy->acknowledgementRequired,
                csrfToken: $this->csrf->issue($session),
            );
        });
    }
}
