<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\PageContentEncoding;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final readonly class CreateUser
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private CreatePersonalWorkspaceForUser $personalWorkspaces,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(string $name, string $email, string $password, ?User $actor = null): User
    {
        if ($actor instanceof User && !$actor->is_system_admin) {
            throw new AuthorizationException('Only system admins can create users.');
        }

        $normalizedName = $this->normalizeName($name);
        $normalizedEmail = $this->normalizeEmail($email);
        $this->ensurePasswordIsLongEnough($password);
        $actorUid = $actor?->uid;

        return DB::transaction(function () use ($actorUid, $normalizedEmail, $normalizedName, $password): User {
            $existingUser = User::query()
                ->where('email', $normalizedEmail)
                ->first();

            if ($existingUser instanceof User) {
                throw new DomainRuleViolation('A user with this email already exists.');
            }

            try {
                $user = User::query()->create([
                    'name' => $normalizedName,
                    'email' => $normalizedEmail,
                    'password' => Hash::make($password),
                ]);
            } catch (QueryException $exception) {
                if (!$this->isEmailUniqueViolation($exception)) {
                    throw $exception;
                }

                throw new DomainRuleViolation('A user with this email already exists.');
            }
            $user->forceFill(['email_verified_at' => now()])->save();

            $event = $this->events->record(
                eventType: DomainEventType::UserCreated,
                aggregateType: 'user',
                aggregateUid: $user->uid,
                payload: [
                    'user_uid' => $user->uid,
                    'email' => $user->email,
                    'is_system_admin' => false,
                    'created_by_user_uid' => $actorUid,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'user',
                auditableUid: $user->uid,
                action: DomainEventType::UserCreated,
                summary: 'User created.',
                metadata: [
                    'email' => $user->email,
                    'is_system_admin' => false,
                    'created_by_user_uid' => $actorUid,
                ],
            );

            $this->personalWorkspaces->handle($user);

            return $user;
        });
    }

    private function normalizeName(string $name): string
    {
        $normalizedName = trim($name);

        if ($normalizedName === '') {
            throw new DomainRuleViolation('User name must not be blank.');
        }

        // A NUL byte or malformed UTF-8 survives trim() and would otherwise fail as a
        // PostgreSQL 500 when written to the text column; reject it as a clean rule
        // violation at the boundary instead.
        if (!PageContentEncoding::isStorable($normalizedName)) {
            throw new DomainRuleViolation('User name must not contain control characters or invalid text.');
        }

        return $normalizedName;
    }

    private function normalizeEmail(string $email): string
    {
        $normalizedEmail = strtolower(trim($email));

        if (filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainRuleViolation('User email must be a valid email address.');
        }

        return $normalizedEmail;
    }

    private function ensurePasswordIsLongEnough(string $password): void
    {
        if (mb_strlen($password) < 12) {
            throw new DomainRuleViolation('User password must be at least 12 characters.');
        }
    }

    private function isEmailUniqueViolation(QueryException $exception): bool
    {
        if ((string) $exception->getCode() !== '23505') {
            return false;
        }

        return str_contains($exception->getMessage(), 'users_email_unique')
            || str_contains($exception->getMessage(), 'users_email_lower_unique');
    }
}
