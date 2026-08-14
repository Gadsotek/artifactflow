<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\ReparentWorkspace;
use App\Application\Identity\ReparentWorkspaceCommand;
use App\Application\Identity\UpdateWorkspaceSettings;
use App\Application\Identity\UpdateWorkspaceSettingsCommand;
use App\Application\Identity\WorkspaceInvitationAccess;
use App\Application\PageCatalog\PageAccess;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class NestedWorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_capabilities_use_the_restrictive_and_of_local_and_ancestor_settings(): void
    {
        $admin = $this->createUser('Settings Admin', 'nested-settings-admin@example.test');
        $editor = $this->createUser('Settings Editor', 'nested-settings-editor@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Child');
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        $this->addMember($root, $editor, WorkspaceRole::Editor);
        $root->forceFill([
            'allow_editor_invites' => false,
            'allow_editor_page_sharing' => false,
        ])->save();
        $child->forceFill([
            'allow_editor_invites' => true,
            'allow_editor_page_sharing' => true,
        ])->save();
        $page = Page::factory()->create([
            'workspace_uid' => $child->uid,
            'owner_user_uid' => $editor->uid,
            'access_mode' => PageAccessMode::Restricted,
        ]);

        $this->assertFalse(app(WorkspaceInvitationAccess::class)->canInvite($editor, $child->refresh()));
        $this->assertFalse(app(PageAccess::class)->canManageAccess($editor, $page));

        $root->forceFill([
            'allow_editor_invites' => true,
            'allow_editor_page_sharing' => true,
        ])->save();
        app(PageAccess::class)->flushCache();

        $this->assertTrue(app(WorkspaceInvitationAccess::class)->canInvite($editor, $child->refresh()));
        $this->assertTrue(app(PageAccess::class)->canManageAccess($editor, $page));
    }

    public function test_parent_sharing_setting_change_invalidates_descendant_page_previews(): void
    {
        $admin = $this->createUser('Settings Admin', 'nested-revision-admin@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Child');
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        $root->refresh();
        $page = Page::factory()->create([
            'workspace_uid' => $child->uid,
            'owner_user_uid' => $admin->uid,
            'access_mode' => PageAccessMode::Inherited,
        ]);
        $previousRevision = $page->preview_access_revision;

        app(UpdateWorkspaceSettings::class)->handle($admin, new UpdateWorkspaceSettingsCommand(
            workspaceUid: $root->uid,
            name: $root->name,
            allowEditorInvites: $root->allow_editor_invites,
            allowEditorPageSharing: !$root->allow_editor_page_sharing,
        ));

        $this->assertSame($previousRevision + 1, $page->refresh()->preview_access_revision);
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function addMember(Workspace $workspace, User $user, WorkspaceRole $role): WorkspaceMembership
    {
        return WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $user->uid,
            'role' => $role,
            'accepted_at' => now(),
        ]);
    }
}
