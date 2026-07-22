<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Http\Middleware\RequireRecentSystemAdminPasswordConfirmation;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\InstallationSettings;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SystemAdminInstallationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_can_view_usage_and_update_installation_limits_with_traceability(): void
    {
        Storage::fake('artifacts');
        config([
            'pages.max_markdown_bytes' => 100,
            'pages.max_page_storage_bytes' => 100,
            'pages.max_workspace_storage_bytes' => 100,
        ]);

        $admin = $this->createUser('System Admin', 'admin@example.test', true);
        $editor = app(CreateUser::class)->handle(
            name: 'Editor User',
            email: 'editor@example.test',
            password: 'correct horse battery staple',
        );
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Storage Runbook',
            description: null,
            content: '12345',
        ));

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('Storage and limits')
            ->assertDontSee('Platform Team')
            ->assertDontSee('Storage Runbook')
            ->assertSee('5 B');

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->put('/admin/settings', [
                'max_markdown_bytes' => '32',
                'max_html_bytes' => '64',
                'artifact_max_bytes' => '64',
                'max_workspace_storage_bytes' => '20',
                'max_page_storage_bytes' => '12',
                'max_page_versions' => '2',
                'max_tags_per_page' => '8',
            ])
            ->assertRedirect('/admin/settings')
            ->assertSessionHas('status', 'Installation limits updated.');

        $this->assertDatabaseHas('installation_settings', [
            'scope' => 'installation',
            'max_markdown_bytes' => 32,
            'max_html_bytes' => 64,
            'artifact_max_bytes' => 64,
            'max_workspace_storage_bytes' => 20,
            'max_page_storage_bytes' => 12,
            'max_page_versions' => 2,
            'max_tags_per_page' => 8,
            'updated_by_user_uid' => $admin->uid,
        ]);
        $this->assertDatabaseCount('installation_settings', 1);

        $event = DomainEvent::query()
            ->where('event_type', 'installation.limits.updated')
            ->sole();
        $this->assertSame('installation_settings', $event->aggregate_type);
        $this->assertSame($admin->uid, $event->payload['updated_by_user_uid']);
        $this->assertSame(12, $event->payload['max_page_storage_bytes']);
        $this->assertSame(2, $event->payload['max_page_versions']);

        $audit = AuditEntry::query()
            ->where('action', 'installation.limits.updated')
            ->sole();
        $this->assertSame($event->uid, $audit->event_uid);
        $this->assertSame($admin->uid, $audit->actor_user_uid);
        $this->assertSame(20, $audit->metadata['max_workspace_storage_bytes']);

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('20 B')
            ->assertSee('12 B')
            ->assertDontSee('5 B of 20 B');

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions", [
                'content' => '12345678',
                'base_version_uid' => $page->current_version_uid,
            ])
            ->assertSessionHasErrors('content');

        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_installation_settings_form_uses_readable_byte_units_and_usage_percentages(): void
    {
        Storage::fake('artifacts');
        config([
            'pages.max_markdown_bytes' => 5 * 1024 * 1024,
            'pages.max_html_bytes' => 5 * 1024 * 1024,
            'pages.artifact_max_bytes' => 10 * 1024 * 1024,
            'pages.max_page_storage_bytes' => 100 * 1024 * 1024,
            'pages.max_workspace_storage_bytes' => 1024 * 1024 * 1024,
        ]);

        $admin = $this->createUser('System Admin', 'friendly-settings-admin@example.test', true);
        $editor = app(CreateUser::class)->handle(
            name: 'Editor User',
            email: 'friendly-editor@example.test',
            password: 'correct horse battery staple',
        );
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Product Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $admin->uid,
            'role' => WorkspaceRole::Reader,
        ]);
        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Tiny Runbook',
            description: null,
            content: '12345',
        ));

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('name="max_markdown_bytes_amount"', false)
            ->assertSee('name="max_markdown_bytes_unit"', false)
            ->assertSee('value="5"', false)
            ->assertSee('MiB')
            ->assertSee('Product Team')
            ->assertSee('5 B of 1.0 GiB')
            ->assertSee('&lt; 0.1% used', false)
            ->assertSee('aria-valuenow="0.001"', false);
    }

    public function test_system_admin_can_update_byte_limits_with_readable_amounts_and_units(): void
    {
        $admin = $this->createUser('System Admin', 'readable-units-admin@example.test', true);

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->put('/admin/settings', [
                'max_markdown_bytes_amount' => '1.5',
                'max_markdown_bytes_unit' => 'KiB',
                'max_html_bytes_amount' => '2',
                'max_html_bytes_unit' => 'KiB',
                'artifact_max_bytes_amount' => '3',
                'artifact_max_bytes_unit' => 'KiB',
                'max_workspace_storage_bytes_amount' => '1',
                'max_workspace_storage_bytes_unit' => 'MiB',
                'max_page_storage_bytes_amount' => '512',
                'max_page_storage_bytes_unit' => 'KiB',
                'max_page_versions' => '2',
                'max_tags_per_page' => '8',
            ])
            ->assertRedirect('/admin/settings')
            ->assertSessionHas('status', 'Installation limits updated.');

        $this->assertDatabaseHas('installation_settings', [
            'scope' => 'installation',
            'max_markdown_bytes' => 1536,
            'max_html_bytes' => 2048,
            'artifact_max_bytes' => 3072,
            'max_workspace_storage_bytes' => 1024 * 1024,
            'max_page_storage_bytes' => 512 * 1024,
            'max_page_versions' => 2,
            'max_tags_per_page' => 8,
            'updated_by_user_uid' => $admin->uid,
        ]);
    }

    public function test_installation_limit_updates_reject_invalid_readable_byte_units(): void
    {
        $admin = $this->createUser('System Admin', 'invalid-readable-units-admin@example.test', true);

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->put('/admin/settings', [
                'max_markdown_bytes_amount' => '1',
                'max_markdown_bytes_unit' => 'TB',
                'max_html_bytes_amount' => '2',
                'max_html_bytes_unit' => 'KiB',
                'artifact_max_bytes_amount' => '3',
                'artifact_max_bytes_unit' => 'KiB',
                'max_workspace_storage_bytes_amount' => '1',
                'max_workspace_storage_bytes_unit' => 'MiB',
                'max_page_storage_bytes_amount' => '512',
                'max_page_storage_bytes_unit' => 'KiB',
                'max_page_versions' => '2',
                'max_tags_per_page' => '8',
            ])
            ->assertSessionHasErrors('max_markdown_bytes');

        $this->assertDatabaseCount('installation_settings', 0);
    }

    public function test_installation_settings_require_recent_system_admin_confirmation(): void
    {
        $admin = $this->createUser('System Admin', 'settings-admin@example.test', true);
        $user = $this->createUser('Normal User', 'settings-user@example.test');

        $this->actingAs($admin)
            ->get('/admin/settings')
            ->assertRedirect('/admin/confirm-password');

        $this->actingAs($user)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->get('/admin/settings')
            ->assertForbidden();

        $this->actingAs($user)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->put('/admin/settings', [
                'max_markdown_bytes' => '32',
            ])
            ->assertForbidden();
    }

    public function test_installation_limit_updates_validate_positive_numeric_bounds(): void
    {
        $admin = $this->createUser('System Admin', 'invalid-settings-admin@example.test', true);

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->put('/admin/settings', [
                'max_markdown_bytes' => '0',
                'max_html_bytes' => '-1',
                'artifact_max_bytes' => 'nope',
                'max_workspace_storage_bytes' => '20',
                'max_page_storage_bytes' => '12',
                'max_page_versions' => '2',
                'max_tags_per_page' => '8',
            ])
            ->assertSessionHasErrors(['max_markdown_bytes', 'max_html_bytes', 'artifact_max_bytes']);

        $this->assertDatabaseCount('installation_settings', 0);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'installation.limits.updated')->count());
    }

    public function test_installation_limit_updates_accept_sign_prefixed_integer_without_server_error(): void
    {
        // A leading '+' passes Laravel's non-strict 'integer' rule (filter_var),
        // so the value must not then throw a 500 in the request's own coercion.
        $admin = $this->createUser('System Admin', 'sign-prefixed-settings-admin@example.test', true);

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->put('/admin/settings', [
                'max_markdown_bytes' => '32',
                'max_html_bytes' => '64',
                'artifact_max_bytes' => '64',
                'max_workspace_storage_bytes' => '20',
                'max_page_storage_bytes' => '12',
                'max_page_versions' => '+5',
                'max_tags_per_page' => '8',
            ])
            ->assertRedirect('/admin/settings')
            ->assertSessionHas('status', 'Installation limits updated.');

        $this->assertDatabaseHas('installation_settings', [
            'scope' => 'installation',
            'max_page_versions' => 5,
            'max_tags_per_page' => 8,
        ]);
    }

    public function test_installation_limit_updates_require_artifact_read_limit_to_cover_html_write_limit(): void
    {
        $admin = $this->createUser('System Admin', 'read-write-settings-admin@example.test', true);

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->put('/admin/settings', [
                'max_markdown_bytes' => '32',
                'max_html_bytes' => '2048',
                'artifact_max_bytes' => '1024',
                'max_workspace_storage_bytes' => '4096',
                'max_page_storage_bytes' => '2048',
                'max_page_versions' => '2',
                'max_tags_per_page' => '8',
            ])
            ->assertSessionHasErrors('artifact_max_bytes');

        $this->assertDatabaseCount('installation_settings', 0);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'installation.limits.updated')->count());
    }

    public function test_installation_limit_updates_require_artifact_read_limit_to_cover_markdown_write_limit(): void
    {
        $admin = $this->createUser('System Admin', 'markdown-read-settings-admin@example.test', true);

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->put('/admin/settings', [
                'max_markdown_bytes' => '2048',
                'max_html_bytes' => '32',
                'artifact_max_bytes' => '1024',
                'max_workspace_storage_bytes' => '4096',
                'max_page_storage_bytes' => '2048',
                'max_page_versions' => '2',
                'max_tags_per_page' => '8',
            ])
            ->assertSessionHasErrors('artifact_max_bytes');

        $this->assertDatabaseCount('installation_settings', 0);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'installation.limits.updated')->count());
    }

    public function test_installation_limit_updates_reject_byte_values_above_hard_safety_ceilings(): void
    {
        $admin = $this->createUser('System Admin', 'huge-settings-admin@example.test', true);

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->put('/admin/settings', [
                'max_markdown_bytes' => (string) (5 * 1024 * 1024 + 1),
                'max_html_bytes' => (string) (5 * 1024 * 1024 + 1),
                'artifact_max_bytes' => (string) (64 * 1024 * 1024 + 1),
                'max_workspace_storage_bytes' => (string) (10 * 1024 * 1024 * 1024 + 1),
                'max_page_storage_bytes' => (string) (1024 * 1024 * 1024 + 1),
                'max_page_versions' => '200',
                'max_tags_per_page' => '25',
            ])
            ->assertSessionHasErrors([
                'max_markdown_bytes',
                'max_html_bytes',
                'artifact_max_bytes',
                'max_workspace_storage_bytes',
                'max_page_storage_bytes',
            ]);

        $this->assertDatabaseCount('installation_settings', 0);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'installation.limits.updated')->count());
    }

    public function test_database_rejects_content_limits_above_the_http_request_envelope(): void
    {
        $admin = $this->createUser('System Admin', 'db-limit-admin@example.test', true);

        $this->expectException(QueryException::class);

        InstallationSettings::query()->forceCreate([
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'max_markdown_bytes' => 5 * 1024 * 1024 + 1,
            'max_html_bytes' => 5 * 1024 * 1024 + 1,
            'artifact_max_bytes' => 64 * 1024 * 1024,
            'max_workspace_storage_bytes' => 10 * 1024 * 1024,
            'max_page_storage_bytes' => 5 * 1024 * 1024,
            'max_page_versions' => 10,
            'max_tags_per_page' => 10,
            'updated_by_user_uid' => $admin->uid,
        ]);
    }

    public function test_database_rejects_artifact_read_limit_below_a_content_write_limit(): void
    {
        $admin = $this->createUser('System Admin', 'db-read-limit-admin@example.test', true);

        $this->expectException(QueryException::class);

        InstallationSettings::query()->forceCreate([
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'max_markdown_bytes' => 2048,
            'max_html_bytes' => 32,
            'artifact_max_bytes' => 1024,
            'max_workspace_storage_bytes' => 10 * 1024 * 1024,
            'max_page_storage_bytes' => 5 * 1024 * 1024,
            'max_page_versions' => 10,
            'max_tags_per_page' => 10,
            'updated_by_user_uid' => $admin->uid,
        ]);
    }

    public function test_installation_limit_settings_memoizes_current_values_per_resolved_instance(): void
    {
        $admin = $this->createUser('System Admin', 'memoized-settings-admin@example.test', true);
        InstallationSettings::query()->forceCreate([
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'max_markdown_bytes' => 32,
            'max_html_bytes' => 64,
            'artifact_max_bytes' => 64,
            'max_workspace_storage_bytes' => 20,
            'max_page_storage_bytes' => 12,
            'max_page_versions' => 2,
            'max_tags_per_page' => 8,
            'updated_by_user_uid' => $admin->uid,
        ]);
        $limits = app(InstallationLimitSettings::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertSame(32, $limits->integer('pages.max_markdown_bytes'));
        $this->assertSame(64, $limits->integer('pages.max_html_bytes'));
        $this->assertSame(8, $limits->current()->maxTagsPerPage);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(1, $this->countQueriesContaining($queries, 'from "installation_settings"'));
    }

    private function createUser(string $name, string $email, bool $isSystemAdmin = false): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        if ($isSystemAdmin) {
            $user->forceFill([
                'is_system_admin' => true,
                'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
                'two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => [Hash::make('ABCD2-EFGH3')],
            ])->save();
        }

        return $user;
    }

    /**
     * @param array<int|string, array{query: string, bindings: array<int|string, mixed>, time: float|null}> $queries
     */
    private function countQueriesContaining(array $queries, string $needle): int
    {
        return count(array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['query'], $needle),
        ));
    }
}
