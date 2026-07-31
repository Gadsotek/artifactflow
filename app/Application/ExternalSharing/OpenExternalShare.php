<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\Events\DomainEventType;
use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\ExternalSharing\ExternalShareSessionKind;
use App\Models\ExternalShare;
use App\Models\ExternalShareSession;
use App\Models\Page;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class OpenExternalShare
{
    public function __construct(
        private ExternalShareSessionCredential $credentials,
        private ExternalShareOpenCsrf $csrf,
        private ExternalSharingPolicySettings $policySettings,
        private ExternalShareLiveState $liveState,
        private IssueExternalShareViewSession $viewSessions,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    public function handle(
        string $externalShareUid,
        #[\SensitiveParameter]
        string $pendingCredential,
        #[\SensitiveParameter]
        string $csrfToken,
    ): ?IssuedExternalShareSession {
        $credentialHash = $this->credentials->hashForLookup(
            ExternalShareSessionKind::PendingOpen,
            $pendingCredential,
        );

        if ($credentialHash === null) {
            return null;
        }

        $candidateSession = ExternalShareSession::query()
            ->where('external_share_uid', $externalShareUid)
            ->where('credential_hash', $credentialHash)
            ->where('kind', ExternalShareSessionKind::PendingOpen->value)
            ->first();

        if (
            !$candidateSession instanceof ExternalShareSession
            || !$this->csrf->matches($candidateSession, $csrfToken)
        ) {
            return null;
        }

        $candidateShare = ExternalShare::query()->find($externalShareUid);

        if (!$candidateShare instanceof ExternalShare) {
            return null;
        }

        return DB::transaction(function () use (
            $candidateSession,
            $candidateShare,
            $credentialHash,
            $csrfToken,
        ): ?IssuedExternalShareSession {
            $page = Page::query()
                ->whereKey($candidateShare->page_uid)
                ->lockForUpdate()
                ->first();

            if (!$page instanceof Page) {
                return null;
            }

            $share = ExternalShare::query()
                ->whereKey($candidateShare->uid)
                ->where('page_uid', $page->uid)
                ->lockForUpdate()
                ->first();
            $pending = ExternalShareSession::query()
                ->whereKey($candidateSession->uid)
                ->where('external_share_uid', $candidateShare->uid)
                ->where('credential_hash', $credentialHash)
                ->where('kind', ExternalShareSessionKind::PendingOpen->value)
                ->lockForUpdate()
                ->first();
            $now = CarbonImmutable::now();
            $policy = $this->policySettings->current(lockForUpdate: true);

            if (
                !$share instanceof ExternalShare
                || !$pending instanceof ExternalShareSession
                || $pending->consumed_at instanceof CarbonImmutable
                || !$pending->expires_at instanceof CarbonImmutable
                || $pending->expires_at->lessThanOrEqualTo($now)
                || !$this->csrf->matches($pending, $csrfToken)
                || !$this->liveState->canStartSession($policy, $share, $page, $now)
            ) {
                return null;
            }

            $pending->forceFill(['consumed_at' => $now])->save();

            if ($share->mode === ExternalShareMode::OneTime) {
                $share->forceFill(['redeemed_at' => $now])->save();
                $this->recordConsumption($share, $page, $now);
            }

            return $this->viewSessions->issue($share, $page, $now);
        });
    }

    private function recordConsumption(ExternalShare $share, Page $page, CarbonImmutable $now): void
    {
        $payload = [
            'external_share_uid' => $share->uid,
            'page_uid' => $page->uid,
            'mode' => $share->mode->value,
            'consumed_at' => $now->toISOString(),
        ];
        $event = $this->events->record(
            eventType: DomainEventType::PageExternalShareConsumed,
            aggregateType: 'page',
            aggregateUid: $page->uid,
            payload: $payload,
        );
        $this->audit->record(
            event: $event,
            actorUserUid: null,
            auditableType: 'external_share',
            auditableUid: $share->uid,
            action: DomainEventType::PageExternalShareConsumed,
            summary: 'One-time external share consumed.',
            metadata: $payload,
        );
    }
}
