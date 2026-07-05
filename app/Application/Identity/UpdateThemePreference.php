<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\ThemePreference;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class UpdateThemePreference
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    public function handle(User $user, ThemePreference|string $themePreference): User
    {
        $newThemePreference = $this->resolveThemePreference($themePreference);

        return DB::transaction(function () use ($user, $newThemePreference): User {
            $userUid = ActorId::fromUser($user);
            $previousThemePreference = $user->theme_preference ?? ThemePreference::System;

            if ($previousThemePreference === $newThemePreference) {
                return $user;
            }

            $user->forceFill([
                'theme_preference' => $newThemePreference,
            ])->save();

            $event = $this->events->record(
                eventType: DomainEventType::UserThemePreferenceChanged,
                aggregateType: 'user',
                aggregateUid: $userUid,
                payload: [
                    'user_uid' => $userUid,
                    'previous_theme' => $previousThemePreference->value,
                    'new_theme' => $newThemePreference->value,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $userUid,
                auditableType: 'user',
                auditableUid: $userUid,
                action: DomainEventType::UserThemePreferenceChanged,
                summary: 'Theme preference changed.',
                metadata: [
                    'previous_theme' => $previousThemePreference->value,
                    'new_theme' => $newThemePreference->value,
                ],
            );

            return $user->refresh();
        });
    }

    private function resolveThemePreference(ThemePreference|string $themePreference): ThemePreference
    {
        if ($themePreference instanceof ThemePreference) {
            return $themePreference;
        }

        $resolvedThemePreference = ThemePreference::tryFrom($themePreference);

        if (!$resolvedThemePreference instanceof ThemePreference) {
            throw new DomainRuleViolation('Theme preference must be light, dark, or system.');
        }

        return $resolvedThemePreference;
    }
}
