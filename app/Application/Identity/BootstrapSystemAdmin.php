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

final readonly class BootstrapSystemAdmin
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private CreatePersonalWorkspaceForUser $personalWorkspaces,
    ) {
    }

    public function handle(string $name, string $email, string $password): User
    {
        $normalizedEmail = $this->normalizeEmail($email);

        return DB::transaction(function () use ($name, $normalizedEmail, $password): User {
            $existingUser = User::query()
                ->where('email', $normalizedEmail)
                ->first();

            if ($existingUser instanceof User) {
                return $this->promoteExistingUserIfNeeded($existingUser);
            }

            $this->ensurePasswordIsLongEnough($password);

            $user = User::query()->create([
                'name' => $this->normalizeName($name),
                'email' => $normalizedEmail,
                'password' => Hash::make($password),
            ]);
            $user->forceFill([
                'email_verified_at' => now(),
                'is_system_admin' => true,
            ])->save();

            $event = $this->events->record(
                eventType: DomainEventType::UserSystemAdminBootstrapped,
                aggregateType: 'user',
                aggregateUid: $user->uid,
                payload: [
                    'user_uid' => $user->uid,
                    'email' => $user->email,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: null,
                auditableType: 'user',
                auditableUid: $user->uid,
                action: DomainEventType::UserSystemAdminBootstrapped,
                summary: 'System admin bootstrapped.',
                metadata: [
                    'email' => $user->email,
                ],
            );

            $this->personalWorkspaces->handle($user);

            return $user;
        });
    }

    private function promoteExistingUserIfNeeded(User $user): User
    {
        if ($user->is_system_admin) {
            $this->personalWorkspaces->handle($user);

            return $user;
        }

        $user->forceFill([
            'is_system_admin' => true,
        ])->save();

        $event = $this->events->record(
            eventType: DomainEventType::UserSystemAdminPromoted,
            aggregateType: 'user',
            aggregateUid: $user->uid,
            payload: [
                'user_uid' => $user->uid,
                'email' => $user->email,
            ],
        );

        $this->audit->record(
            event: $event,
            actorUserUid: null,
            auditableType: 'user',
            auditableUid: $user->uid,
            action: DomainEventType::UserSystemAdminPromoted,
            summary: 'System admin promoted.',
            metadata: [
                'email' => $user->email,
            ],
        );

        $this->personalWorkspaces->handle($user);

        return $user->refresh();
    }

    private function normalizeEmail(string $email): string
    {
        $normalizedEmail = strtolower(trim($email));

        if (filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainRuleViolation('System admin email must be a valid email address.');
        }

        return $normalizedEmail;
    }

    private function normalizeName(string $name): string
    {
        $normalizedName = trim($name);

        if ($normalizedName === '') {
            throw new DomainRuleViolation('System admin name must not be blank.');
        }

        return $normalizedName;
    }

    private function ensurePasswordIsLongEnough(string $password): void
    {
        if (mb_strlen($password) < 12) {
            throw new DomainRuleViolation('System admin password must be at least 12 characters.');
        }
    }
}
