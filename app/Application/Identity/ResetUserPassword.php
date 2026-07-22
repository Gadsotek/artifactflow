<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final readonly class ResetUserPassword
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    public function handle(User $user, string $newPassword, ?string $actorUserUid = null): void
    {
        $this->ensurePasswordIsLongEnough($newPassword);

        DB::transaction(function () use ($actorUserUid, $newPassword, $user): void {
            $lockedUser = User::query()
                ->whereKey($user->uid)
                ->lockForUpdate()
                ->sole();

            $lockedUser->forceFill([
                'password' => Hash::make($newPassword),
                'remember_token' => Str::random(60),
                'auth_revision' => $lockedUser->auth_revision + 1,
                'password_reset_notice_pending_at' => now(),
            ])->save();

            $invalidatedSessions = $this->invalidateDatabaseSessions($lockedUser);
            $trustedDevicesRevoked = DB::table('trusted_devices')->where('user_uid', $lockedUser->uid)->delete();
            $event = $this->events->record(
                eventType: DomainEventType::UserPasswordReset,
                aggregateType: 'user',
                aggregateUid: $lockedUser->uid,
                payload: [
                    'user_uid' => $lockedUser->uid,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUserUid,
                auditableType: 'user',
                auditableUid: $lockedUser->uid,
                action: DomainEventType::UserPasswordReset,
                summary: 'User password reset.',
                metadata: [
                    'sessions_invalidated' => $invalidatedSessions,
                    'trusted_devices_revoked' => $trustedDevicesRevoked,
                ],
            );
        });
    }

    private function ensurePasswordIsLongEnough(string $password): void
    {
        if (mb_strlen($password) < 12) {
            throw new DomainRuleViolation('User password must be at least 12 characters.');
        }
    }

    private function invalidateDatabaseSessions(User $user): int
    {
        if (!Schema::hasTable('sessions') || !Schema::hasColumn('sessions', 'user_id')) {
            return 0;
        }

        return DB::table('sessions')
            ->where('user_id', $user->uid)
            ->delete();
    }
}
