<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\Events\DomainEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class RecordSuccessfulLogin
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    public function handle(User $user): void
    {
        $userUid = $user->getKey();

        if (!is_string($userUid) || $userUid === '') {
            throw new LogicException('Cannot record a login for an unsaved user.');
        }

        DB::transaction(function () use ($userUid): void {
            $event = $this->events->record(
                eventType: DomainEventType::UserLoggedIn,
                aggregateType: 'user',
                aggregateUid: $userUid,
                payload: [
                    'user_uid' => $userUid,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $userUid,
                auditableType: 'user',
                auditableUid: $userUid,
                action: DomainEventType::UserLoggedIn,
                summary: 'User logged in.',
            );
        });
    }
}
