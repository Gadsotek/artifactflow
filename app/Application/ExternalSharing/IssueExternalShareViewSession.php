<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\Events\DomainEventType;
use App\Domain\ExternalSharing\ExternalShareSessionKind;
use App\Models\ExternalShare;
use App\Models\ExternalShareSession;
use App\Models\Page;
use Carbon\CarbonImmutable;

final readonly class IssueExternalShareViewSession
{
    public function __construct(
        private ExternalShareSessionCredential $credentials,
        private ExternalShareLimits $limits,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    public function issue(
        ExternalShare $share,
        Page $page,
        CarbonImmutable $now,
    ): IssuedExternalShareSession {
        $this->makeRoomForViewSession($share);
        $generated = $this->credentials->generate(ExternalShareSessionKind::View);
        $session = ExternalShareSession::query()->forceCreate([
            'external_share_uid' => $share->uid,
            'credential_hash' => $generated->hash,
            'kind' => ExternalShareSessionKind::View->value,
            'expires_at' => null,
        ]);
        $firstSession = $share->view_session_count === 0;
        $share->forceFill([
            'first_viewed_at' => $share->first_viewed_at ?? $now,
            'last_viewed_at' => $now,
            'view_session_count' => $share->view_session_count + 1,
        ])->save();

        if ($firstSession) {
            $payload = [
                'external_share_uid' => $share->uid,
                'page_uid' => $page->uid,
                'mode' => $share->mode->value,
                'opened_at' => $now->toISOString(),
                'view_session_count' => $share->view_session_count,
            ];
            $event = $this->events->record(
                eventType: DomainEventType::PageExternalShareOpened,
                aggregateType: 'page',
                aggregateUid: $page->uid,
                payload: $payload,
            );
            $this->audit->record(
                event: $event,
                actorUserUid: null,
                auditableType: 'external_share',
                auditableUid: $share->uid,
                action: DomainEventType::PageExternalShareOpened,
                summary: 'External share opened.',
                metadata: $payload,
            );
        }

        return new IssuedExternalShareSession($session, $generated->reveal());
    }

    private function makeRoomForViewSession(ExternalShare $share): void
    {
        $sessions = ExternalShareSession::query()
            ->where('external_share_uid', $share->uid)
            ->where('kind', ExternalShareSessionKind::View->value);
        $excessAfterInsert = $sessions->count() - $this->limits->maxViewSessionsPerShare() + 1;

        if ($excessAfterInsert <= 0) {
            return;
        }

        $sessions
            ->orderBy('created_at')
            ->orderBy('uid')
            ->limit($excessAfterInsert)
            ->delete();
    }
}
