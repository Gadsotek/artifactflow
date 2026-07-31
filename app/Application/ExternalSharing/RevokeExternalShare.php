<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Application\PageCatalog\PageAccess;
use App\Domain\Events\DomainEventType;
use App\Models\ExternalShare;
use App\Models\Page;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class RevokeExternalShare
{
    public function __construct(
        private PageAccess $access,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, RevokeExternalShareCommand $command): bool
    {
        $actorUid = ActorId::fromUser($actor);

        return DB::transaction(function () use ($actorUid, $command): bool {
            $page = $this->access->lockAndReauthorize(
                $command->pageUid,
                function (Page $lockedPage) use ($actorUid): void {
                    $freshActor = User::query()->find($actorUid);

                    if (!$freshActor instanceof User || $freshActor->is_service_account) {
                        throw new AuthorizationException('Only human access managers can revoke external shares.');
                    }

                    $this->access->ensureCanManageAccess($freshActor, $lockedPage);
                },
            );

            $share = ExternalShare::query()
                ->where('uid', $command->externalShareUid)
                ->where('page_uid', $page->uid)
                ->lockForUpdate()
                ->first();

            if (
                !$share instanceof ExternalShare
                || $share->revoked_at instanceof CarbonImmutable
            ) {
                return false;
            }

            $revokedAt = CarbonImmutable::now();
            $share->forceFill([
                'revoked_at' => $revokedAt,
                'revoked_by_user_uid' => $actorUid,
            ])->save();
            $share->sessions()->delete();

            $payload = [
                'external_share_uid' => $share->uid,
                'page_uid' => $page->uid,
                'mode' => $share->mode->value,
                'expires_at' => $share->expires_at?->toISOString(),
                'revoked_at' => $revokedAt->toISOString(),
                'revoked_by_user_uid' => $actorUid,
            ];
            $event = $this->events->record(
                eventType: DomainEventType::PageExternalShareRevoked,
                aggregateType: 'page',
                aggregateUid: $page->uid,
                payload: $payload,
            );
            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'external_share',
                auditableUid: $share->uid,
                action: DomainEventType::PageExternalShareRevoked,
                summary: 'External share revoked.',
                metadata: $payload,
            );

            return true;
        });
    }
}
