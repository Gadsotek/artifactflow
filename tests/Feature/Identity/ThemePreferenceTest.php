<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\UpdateThemePreference;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\ThemePreference;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_default_to_system_theme_preference(): void
    {
        $user = $this->createUser('Theme User', 'theme@example.test');

        $this->assertSame(ThemePreference::System, $user->theme_preference);
    }

    public function test_user_can_update_theme_preference_with_traceability(): void
    {
        $user = $this->createUser('Theme User', 'theme@example.test');

        $updatedUser = app(UpdateThemePreference::class)->handle($user, ThemePreference::Dark);

        $this->assertSame(ThemePreference::Dark, $updatedUser->theme_preference);

        $event = DomainEvent::query()
            ->where('event_type', 'user.theme_preference.changed')
            ->sole();

        $this->assertSame('user', $event->aggregate_type);
        $this->assertSame($user->uid, $event->aggregate_uid);
        $this->assertSame($user->uid, $event->payload['user_uid']);
        $this->assertSame('system', $event->payload['previous_theme']);
        $this->assertSame('dark', $event->payload['new_theme']);

        $auditEntry = AuditEntry::query()
            ->where('action', 'user.theme_preference.changed')
            ->sole();

        $this->assertSame($event->uid, $auditEntry->event_uid);
        $this->assertSame($user->uid, $auditEntry->actor_user_uid);
        $this->assertSame('user', $auditEntry->auditable_type);
        $this->assertSame($user->uid, $auditEntry->auditable_uid);
        $this->assertSame('Theme preference changed.', $auditEntry->summary);
    }

    public function test_updating_to_the_existing_theme_preference_is_idempotent(): void
    {
        $user = $this->createUser('Theme User', 'theme@example.test');

        app(UpdateThemePreference::class)->handle($user, ThemePreference::System);

        $this->assertSame(0, DomainEvent::query()->where('event_type', 'user.theme_preference.changed')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'user.theme_preference.changed')->count());
    }

    public function test_theme_preference_route_requires_authentication(): void
    {
        $this->post('/settings/theme', ['theme' => 'dark'])
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_can_update_theme_preference_from_the_dashboard(): void
    {
        $user = $this->createUser('Theme User', 'theme@example.test');

        $this->actingAs($user)
            ->post('/settings/theme', ['theme' => 'light'])
            ->assertRedirect('/dashboard');

        $this->assertSame(ThemePreference::Light, $user->refresh()->theme_preference);
    }

    public function test_authenticated_layout_bootstraps_explicit_and_system_theme_preferences(): void
    {
        $user = $this->createUser('Theme User', 'theme@example.test');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('data-csp-nonce="', false)
            ->assertSee('data-theme="system"', false)
            ->assertSee('data-theme-bootstrap', false)
            ->assertSee("matchMedia('(prefers-color-scheme: dark)')", false);

        $user->forceFill(['theme_preference' => ThemePreference::Dark])->save();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('<html class="dark"', false)
            ->assertSee('data-theme="dark"', false);

        $user->forceFill(['theme_preference' => ThemePreference::Light])->save();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('<html class=""', false)
            ->assertSee('data-theme="light"', false);
    }

    public function test_invalid_theme_preference_values_are_rejected(): void
    {
        $user = $this->createUser('Theme User', 'theme@example.test');

        try {
            app(UpdateThemePreference::class)->handle($user, 'purple');
            $this->fail('Expected an invalid theme preference to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Theme preference must be light, dark, or system.', $exception->getMessage());
        }

        $this->assertSame(ThemePreference::System, $user->refresh()->theme_preference);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'user.theme_preference.changed')->count());
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }
}
