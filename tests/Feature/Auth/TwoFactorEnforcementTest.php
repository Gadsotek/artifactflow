<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Http\Middleware\RequireRecentSystemAdminPasswordConfirmation;
use App\Models\InstallationSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class TwoFactorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_installation_requires_system_admin_two_factor_by_default(): void
    {
        $values = app(InstallationLimitSettings::class)->current();

        $this->assertTrue($values->twoFactorRequiredForSystemAdmins);
        $this->assertFalse($values->twoFactorRequiredForAllUsers);
        $this->assertDatabaseCount('installation_settings', 0);

        $settings = InstallationSettings::query()->forceCreate([
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'max_markdown_bytes' => 32,
            'max_html_bytes' => 64,
            'artifact_max_bytes' => 80,
            'max_workspace_storage_bytes' => 1024,
            'max_page_storage_bytes' => 512,
            'max_page_versions' => 2,
            'max_tags_per_page' => 8,
        ]);

        $settings->refresh();
        $this->assertTrue($settings->two_factor_required_for_system_admins);
        $this->assertFalse($settings->two_factor_required_for_all_users);
    }

    public function test_system_admins_are_required_to_enroll_by_default_without_a_redirect_loop(): void
    {
        $admin = $this->createUser('System Admin', 'admin@example.test', isSystemAdmin: true);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertRedirect('/settings/two-factor');

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertRedirect('/settings/two-factor');

        $this->actingAs($admin)
            ->get('/settings/two-factor')
            ->assertOk()
            ->assertSee('Two-factor authentication');

        $this->actingAs($admin)
            ->get('/settings/confirm-password')
            ->assertOk()
            ->assertSee('Confirm password');

        $this->actingAs($admin)
            ->post('/settings/theme', ['theme' => 'dark'])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post('/logout')
            ->assertRedirect('/');
    }

    public function test_fresh_password_login_allows_required_admin_enrollment_for_three_minutes(): void
    {
        $this->assertSame(180, config('auth.two_factor_enrollment_password_timeout'));
        $admin = $this->createUser('Fresh System Admin', 'fresh-admin@example.test', isSystemAdmin: true);

        $this->post('/login', [
            'email' => 'fresh-admin@example.test',
            'password' => 'correct horse battery staple',
        ])
            ->assertRedirect('/dashboard')
            ->assertSessionHas(RequireRecentPasswordConfirmation::SESSION_KEY);

        $this->get('/dashboard')->assertRedirect('/settings/two-factor');
        $confirmedAt = session(RequireRecentPasswordConfirmation::SESSION_KEY);
        $this->assertIsInt($confirmedAt);
        $this->get('/settings/two-factor')
            ->assertOk()
            ->assertSee('Finish setup within 3 minutes')
            ->assertSee('data-two-factor-enrollment-timer', escape: false)
            ->assertSee('data-two-factor-enrollment-expired-url="' . route('settings.two-factor.index') . '"', escape: false)
            ->assertSee('data-two-factor-enrollment-deadline="' . ($confirmedAt + 180) . '"', escape: false);

        $this->post('/settings/two-factor/enroll')
            ->assertRedirect('/settings/two-factor');
        $this->assertIsString($admin->refresh()->two_factor_secret);
        $expiredSecret = (string) $admin->two_factor_secret;

        $this->travel(181)->seconds();

        $this->actingAs($admin->refresh())
            ->get('/settings/two-factor')
            ->assertRedirect('/settings/confirm-password');
        $this->post('/settings/two-factor/confirm', ['code' => '000000'])
            ->assertRedirect('/settings/confirm-password');
        $this->assertFalse($admin->refresh()->hasEnabledTwoFactor());

        $this->post('/settings/confirm-password', [
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/settings/two-factor');

        $this->get('/settings/two-factor')
            ->assertOk()
            ->assertDontSee($expiredSecret)
            ->assertSee('Start enrollment')
            ->assertSee('The previous QR code and authenticator key expired');
    }

    public function test_org_wide_and_per_user_enforcement_redirect_only_required_users(): void
    {
        $admin = $this->createUser('System Admin', 'admin@example.test', isSystemAdmin: true, twoFactorEnabled: true);
        $regular = $this->createUser('Regular User', 'regular@example.test');
        $required = $this->createUser('Required User', 'required@example.test');
        $required->forceFill(['two_factor_required' => true])->save();

        $this->actingAs($regular)
            ->get('/dashboard')
            ->assertOk();

        $this->actingAs($required)
            ->get('/dashboard')
            ->assertRedirect('/settings/two-factor');

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->put('/admin/settings', $this->settingsPayload(twoFactorRequiredForAllUsers: true))
            ->assertRedirect('/admin/settings');

        $this->actingAs($regular)
            ->get('/dashboard')
            ->assertRedirect('/settings/two-factor');
    }

    /**
     * @return array<string, string>
     */
    private function settingsPayload(bool $twoFactorRequiredForAllUsers): array
    {
        return [
            'max_markdown_bytes' => '32',
            'max_html_bytes' => '64',
            'artifact_max_bytes' => '80',
            'max_workspace_storage_bytes' => '1024',
            'max_page_storage_bytes' => '512',
            'max_page_versions' => '2',
            'max_tags_per_page' => '8',
            'two_factor_required_for_system_admins' => '1',
            'two_factor_required_for_all_users' => $twoFactorRequiredForAllUsers ? '1' : '0',
        ];
    }

    private function createUser(
        string $name,
        string $email,
        bool $isSystemAdmin = false,
        bool $twoFactorEnabled = false,
    ): User {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('correct horse battery staple'),
        ]);
        $user->forceFill([
            'is_system_admin' => $isSystemAdmin,
            'two_factor_confirmed_at' => $twoFactorEnabled ? now() : null,
            'two_factor_secret' => $twoFactorEnabled ? 'JBSWY3DPEHPK3PXP' : null,
            'two_factor_recovery_codes' => $twoFactorEnabled ? [Hash::make('ABCD2-EFGH3')] : null,
        ])->save();

        app(CreatePersonalWorkspaceForUser::class)->handle($user);

        InstallationSettings::query()->first();

        return $user;
    }
}
