<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\PageCatalog\PageType;
use App\Http\Middleware\RequireRecentSystemAdminPasswordConfirmation;
use App\Models\DomainEvent;
use App\Models\InstallationSettings;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class RealtimeFoundationGatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_realtime_disabled_by_default(): void
    {
        config([
            'app.env' => 'production',
            'app.artifact_url' => 'https://artifacts.example.test',
        ]);
        $user = app(CreateUser::class)->handle(
            name: 'Realtime User',
            email: 'realtime-default@example.test',
            password: 'correct horse battery staple',
        );

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $this->assertFalse(app(InstallationLimitSettings::class)->current()->realtimeEnabled);
        $this->assertStringNotContainsString(
            'wss://',
            (string) $response->headers->get('Content-Security-Policy'),
        );
        $response->assertDontSee('data-realtime-enabled="true"', false);
    }

    public function test_enabling_realtime_requires_reverb_configured(): void
    {
        config(['broadcasting.default' => 'null']);
        $admin = $this->createSystemAdmin();

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->put('/admin/settings', $this->validSettingsPayload([
                'realtime_enabled' => '1',
            ]))
            ->assertSessionHasErrors([
                'realtime_enabled' => 'Realtime can only be enabled when Reverb is configured.',
            ]);

        $this->assertDatabaseCount('installation_settings', 0);
    }

    public function test_system_admin_can_enable_realtime_when_reverb_is_configured(): void
    {
        $this->configureLocalReverb();
        $admin = $this->createSystemAdmin();

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->put('/admin/settings', $this->validSettingsPayload([
                'realtime_enabled' => '1',
            ]))
            ->assertRedirect('/admin/settings')
            ->assertSessionHas('status', 'Installation limits updated.');

        $this->assertDatabaseHas('installation_settings', [
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'realtime_enabled' => true,
            'updated_by_user_uid' => $admin->uid,
        ]);

        $event = DomainEvent::query()
            ->where('event_type', 'installation.limits.updated')
            ->sole();

        $this->assertTrue($event->payload['realtime_enabled']);
    }

    public function test_enabled_realtime_adds_only_the_app_origin_websocket_csp_source(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://app.example.test',
            'app.artifact_url' => 'https://artifacts.example.test',
        ]);
        $this->configureLocalReverb('https://app.example.test');
        $user = app(CreateUser::class)->handle(
            name: 'Realtime CSP User',
            email: 'realtime-csp@example.test',
            password: 'correct horse battery staple',
        );
        $this->createInstallationSettings(realtimeEnabled: true, updatedByUserUid: $user->uid);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk()
            ->assertSee('data-realtime-enabled="true"', false);
        $this->assertStringContainsString(
            "connect-src 'self' wss://app.example.test",
            (string) $response->headers->get('Content-Security-Policy'),
        );

        config(['app.runtime_role' => 'artifact-host']);

        $artifactResponse = $this->get('/up');
        $artifactCsp = (string) $artifactResponse->headers->get('Content-Security-Policy');

        $artifactResponse->assertNotFound();
        $this->assertStringContainsString("default-src 'none'", $artifactCsp);
        $this->assertStringNotContainsString('ws://', $artifactCsp);
        $this->assertStringNotContainsString('wss://', $artifactCsp);
    }

    public function test_disabling_realtime_does_not_weaken_occ_or_artifact_host_csp(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'realtime-occ@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Realtime Off OCC Page',
            description: null,
            content: '# Version one',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Version two',
            baseVersionUid: $firstVersion->uid,
        ));
        $this->createInstallationSettings(realtimeEnabled: false, updatedByUserUid: $editor->uid);

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions", [
                'content' => '# v3',
                'base_version_uid' => $firstVersion->uid,
            ])
            ->assertStatus(409)
            ->assertSee('This page changed since you opened it.');

        $this->assertSame($secondVersion->uid, $page->refresh()->current_version_uid);
        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());

        config(['app.runtime_role' => 'artifact-host']);

        $response = $this->get('/up');
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $response->assertNotFound();
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringNotContainsString('ws://', $csp);
        $this->assertStringNotContainsString('wss://', $csp);
    }

    public function test_broadcast_auth_route_requires_app_runtime_and_authentication(): void
    {
        $this->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'presence-page.01J00000000000000000000000',
        ])->assertRedirect('/login');

        config(['app.runtime_role' => 'artifact-host']);

        $user = $this->createUser('Broadcast User', 'broadcast@example.test');

        $this->actingAs($user)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'presence-page.01J00000000000000000000000',
            ])
            ->assertNotFound();
    }

    public function test_broadcast_auth_returns_forbidden_when_two_factor_enrollment_is_required(): void
    {
        $user = $this->createUser('Broadcast 2FA User', 'broadcast-2fa@example.test');
        $user->forceFill(['two_factor_required' => true])->save();

        $this->actingAs($user)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'presence-page.01J00000000000000000000000',
            ])
            ->assertForbidden();
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function validSettingsPayload(array $overrides = []): array
    {
        return $overrides + [
            'max_markdown_bytes' => '32',
            'max_html_bytes' => '64',
            'artifact_max_bytes' => '64',
            'max_workspace_storage_bytes' => '20',
            'max_page_storage_bytes' => '12',
            'max_page_versions' => '2',
            'max_tags_per_page' => '8',
            'two_factor_required_for_system_admins' => '1',
            'two_factor_required_for_all_users' => '0',
            'realtime_enabled' => '0',
        ];
    }

    private function configureLocalReverb(string $publicUrl = 'http://localhost:8080'): void
    {
        config([
            'app.reverb_url' => $publicUrl,
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.app_id' => 'artifactflow-local',
            'broadcasting.connections.reverb.key' => 'artifactflow-local-key',
            'broadcasting.connections.reverb.secret' => str_repeat('r', 32),
            'broadcasting.connections.reverb.options.host' => parse_url($publicUrl, PHP_URL_HOST),
            'broadcasting.connections.reverb.options.port' => parse_url($publicUrl, PHP_URL_PORT) ?: 443,
            'broadcasting.connections.reverb.options.scheme' => parse_url($publicUrl, PHP_URL_SCHEME) ?: 'https',
        ]);
    }

    private function createInstallationSettings(bool $realtimeEnabled, string $updatedByUserUid): InstallationSettings
    {
        return InstallationSettings::query()->forceCreate([
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'max_markdown_bytes' => 32,
            'max_html_bytes' => 64,
            'artifact_max_bytes' => 64,
            'max_workspace_storage_bytes' => 20,
            'max_page_storage_bytes' => 12,
            'max_page_versions' => 2,
            'max_tags_per_page' => 8,
            'two_factor_required_for_system_admins' => true,
            'two_factor_required_for_all_users' => false,
            'realtime_enabled' => $realtimeEnabled,
            'updated_by_user_uid' => $updatedByUserUid,
        ]);
    }

    private function createSystemAdmin(): User
    {
        $user = $this->createUser('System Admin', 'system-admin@example.test');
        $user->forceFill([
            'is_system_admin' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [Hash::make('ABCD2-EFGH3')],
        ])->save();

        return $user;
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
