<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\UpdateWorkspaceSettings;
use App\Application\Identity\UpdateWorkspaceSettingsCommand;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class WorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_can_update_shared_workspace_settings_with_traceability_and_idempotency(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $command = new UpdateWorkspaceSettingsCommand(
            workspaceUid: $workspace->uid,
            name: 'Delivery Platform',
            allowEditorInvites: true,
            allowEditorPageSharing: false,
        );
        $handler = app(UpdateWorkspaceSettings::class);

        $updated = $handler->handle($admin, $command);
        $repeated = $handler->handle($admin, $command);

        $this->assertSame($workspace->uid, $updated->uid);
        $this->assertSame('Delivery Platform', $updated->name);
        $this->assertTrue($updated->allow_editor_invites);
        $this->assertFalse($updated->allow_editor_page_sharing);
        $this->assertSame('Delivery Platform', $repeated->name);

        $event = DomainEvent::query()
            ->where('event_type', 'workspace.settings.updated')
            ->sole();

        $this->assertSame('workspace', $event->aggregate_type);
        $this->assertSame($workspace->uid, $event->aggregate_uid);
        $this->assertSame($admin->uid, $event->payload['updated_by_user_uid']);
        $this->assertSame('Platform Team', $event->payload['previous_name']);
        $this->assertSame('Delivery Platform', $event->payload['new_name']);
        $this->assertFalse($event->payload['previous_allow_editor_invites']);
        $this->assertTrue($event->payload['new_allow_editor_invites']);
        $this->assertTrue($event->payload['previous_allow_editor_page_sharing']);
        $this->assertFalse($event->payload['new_allow_editor_page_sharing']);

        $audit = AuditEntry::query()
            ->where('action', 'workspace.settings.updated')
            ->sole();

        $this->assertSame($event->uid, $audit->event_uid);
        $this->assertSame($admin->uid, $audit->actor_user_uid);
        $this->assertSame($workspace->uid, $audit->auditable_uid);
        $this->assertSame('Workspace settings updated.', $audit->summary);
        $this->assertSame('Delivery Platform', $audit->metadata['new_name']);
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'workspace.settings.updated')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'workspace.settings.updated')->count());
    }

    public function test_non_admins_cannot_update_workspace_settings_and_personal_workspaces_are_immutable(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $outsider = $this->createUser('Outside User', 'outside@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);
        $command = new UpdateWorkspaceSettingsCommand(
            workspaceUid: $workspace->uid,
            name: 'Changed Team',
            allowEditorInvites: true,
            allowEditorPageSharing: false,
        );

        foreach ([$editor, $outsider] as $actor) {
            try {
                app(UpdateWorkspaceSettings::class)->handle($actor, $command);
                $this->fail('Expected workspace settings update to be forbidden.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('Only workspace admins can update workspace settings.', $exception->getMessage());
            }
        }

        $personalWorkspace = Workspace::query()->where('personal_owner_uid', $admin->uid)->sole();

        try {
            app(UpdateWorkspaceSettings::class)->handle($admin, new UpdateWorkspaceSettingsCommand(
                workspaceUid: $personalWorkspace->uid,
                name: 'Renamed Personal',
                allowEditorInvites: false,
                allowEditorPageSharing: true,
            ));
            $this->fail('Expected personal workspace settings to be immutable.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Personal workspace settings cannot be changed.', $exception->getMessage());
        }

        $this->assertSame('Platform Team', $workspace->refresh()->name);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.settings.updated')->count());
    }

    public function test_workspace_settings_validate_the_name_before_mutation(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        foreach (['', '   ', str_repeat('w', 161)] as $name) {
            try {
                app(UpdateWorkspaceSettings::class)->handle($admin, new UpdateWorkspaceSettingsCommand(
                    workspaceUid: $workspace->uid,
                    name: $name,
                    allowEditorInvites: false,
                    allowEditorPageSharing: true,
                ));
                $this->fail('Expected invalid workspace name to be rejected.');
            } catch (DomainRuleViolation $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }

        $this->assertSame('Platform Team', $workspace->refresh()->name);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.settings.updated')->count());
    }

    public function test_workspace_rename_is_rate_limited_by_cooldown(): void
    {
        config(['pages.workspace_rename_cooldown_seconds' => 300]);
        Cache::flush();

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $handler = app(UpdateWorkspaceSettings::class);

        $handler->handle($admin, new UpdateWorkspaceSettingsCommand(
            workspaceUid: $workspace->uid,
            name: 'Delivery Platform',
            allowEditorInvites: false,
            allowEditorPageSharing: true,
        ));

        try {
            $handler->handle($admin, new UpdateWorkspaceSettingsCommand(
                workspaceUid: $workspace->uid,
                name: 'Platform Delivery',
                allowEditorInvites: false,
                allowEditorPageSharing: true,
            ));
            $this->fail('Expected rapid workspace rename to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Workspace name was changed recently. Try again later.', $exception->getMessage());
        }

        $this->assertSame('Delivery Platform', $workspace->refresh()->name);
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'workspace.settings.updated')->count());
    }

    public function test_workspace_admin_can_manage_settings_from_dashboard_but_editor_cannot(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Workspace settings')
            ->assertSee("/workspaces/{$workspace->uid}/settings", false)
            ->assertSee('Allow Editors to invite members')
            ->assertSee('Allow Editors and page owners to share pages');

        $this->actingAs($editor)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Workspace settings')
            ->assertDontSee("/workspaces/{$workspace->uid}/settings", false);

        $this->actingAs($admin)
            ->put("/workspaces/{$workspace->uid}/settings", [
                'name' => 'Delivery Platform',
                'allow_editor_invites' => '1',
            ])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'Workspace settings updated.')
            ->assertSessionHas('current_workspace_uid', $workspace->uid);

        $workspace->refresh();
        $this->assertSame('Delivery Platform', $workspace->name);
        $this->assertTrue($workspace->allow_editor_invites);
        $this->assertTrue($workspace->allow_editor_page_sharing);

        $this->actingAs($admin)
            ->put("/workspaces/{$workspace->uid}/settings", [
                'name' => 'Delivery Platform',
                'allow_editor_page_sharing' => '0',
            ])
            ->assertRedirect('/dashboard');

        $this->assertFalse($workspace->refresh()->allow_editor_page_sharing);

        $this->actingAs($editor)
            ->put("/workspaces/{$workspace->uid}/settings", [
                'name' => 'Forged Name',
                'allow_editor_invites' => '1',
                'allow_editor_page_sharing' => '1',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->put("/workspaces/{$workspace->uid}/settings", ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame('Delivery Platform', $workspace->refresh()->name);
    }

    public function test_rename_still_refreshes_page_search_vectors_after_commit(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Release Notes',
            description: 'Notes for the workspace.',
            content: '# Release Notes' . PHP_EOL . PHP_EOL . 'Body text.',
        ));

        // The workspace name is part of every page's search vector. Before the
        // rename the new, unique name matches nothing.
        $this->actingAs($admin)
            ->get('/pages?workspace_uid=all&q=Frontierdelivery')
            ->assertOk()
            ->assertSee('No pages found');

        // Capture the transaction depth at which the workspace-wide search-vector
        // rebuild runs. This proves the refresh is deferred OUT of the rename
        // transaction (the point of the lock-order fix), not merely that it still
        // happens. Under RefreshDatabase the base level is 1; the rename's own
        // DB::transaction nests to 2, so an in-transaction refresh would run at 2
        // while the deferred afterCommit refresh runs back at the base level.
        $baseLevel = DB::transactionLevel();
        $refreshLevels = [];
        DB::listen(static function (QueryExecuted $query) use (&$refreshLevels): void {
            if (str_contains($query->sql, 'SET search_vector') && str_contains($query->sql, 'workspace_uid')) {
                $refreshLevels[] = DB::transactionLevel();
            }
        });

        app(UpdateWorkspaceSettings::class)->handle($admin, new UpdateWorkspaceSettingsCommand(
            workspaceUid: $workspace->uid,
            name: 'Frontierdelivery Team',
            allowEditorInvites: false,
            allowEditorPageSharing: true,
        ));

        $this->assertNotEmpty($refreshLevels, 'The workspace search-vector refresh did not run.');
        foreach ($refreshLevels as $level) {
            $this->assertSame(
                $baseLevel,
                $level,
                'Search-vector refresh must run after commit, not inside the rename transaction.',
            );
        }

        // The deferred refresh must still re-index the workspace's pages under the
        // new name.
        $this->actingAs($admin)
            ->get('/pages?workspace_uid=all&q=Frontierdelivery')
            ->assertOk()
            ->assertSee('Release Notes');
    }

    private function createUser(string $name, string $email): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        app(\App\Application\Identity\CreatePersonalWorkspaceForUser::class)->handle($user);

        return $user;
    }

    private function addMember(string $workspaceUid, User $user, WorkspaceRole $role): WorkspaceMembership
    {
        return WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspaceUid,
            'user_uid' => $user->uid,
            'role' => $role,
            'accepted_at' => now(),
        ]);
    }
}
