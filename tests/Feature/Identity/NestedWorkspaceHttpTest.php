<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\ReparentWorkspace;
use App\Application\Identity\ReparentWorkspaceCommand;
use App\Application\Identity\WorkspaceContext;
use App\Domain\Identity\WorkspaceRole;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class NestedWorkspaceHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_reparent_a_child_from_the_dashboard(): void
    {
        $admin = $this->createUser('Hierarchy Admin', 'hierarchy-http-admin@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Root Workspace');

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $root->uid])
            ->post('/workspaces', [
                'name' => 'Child Workspace',
                'parent_workspace_uid' => $root->uid,
            ])
            ->assertRedirect('/dashboard');

        $child = Workspace::query()->where('name', 'Child Workspace')->sole();
        $this->assertSame($root->uid, $child->parent_workspace_uid);

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $child->uid])
            ->get('/dashboard?tab=settings')
            ->assertOk()
            ->assertSee('data-workspace-depth="0"', false)
            ->assertSee('data-workspace-depth="1"', false)
            ->assertSee('Workspace hierarchy')
            ->assertSee("/workspaces/{$child->uid}/hierarchy", false);

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $child->uid])
            ->post("/workspaces/{$child->uid}/hierarchy/preview", [
                'parent_workspace_uid' => '',
            ])
            ->assertRedirect('/dashboard?tab=settings')
            ->assertSessionHas('workspace_hierarchy_confirmation');

        $this->assertSame($root->uid, $child->refresh()->parent_workspace_uid);
        $confirmation = session('workspace_hierarchy_confirmation');
        $this->assertIsArray($confirmation);
        $previewId = $confirmation['preview_id'] ?? null;
        $this->assertIsString($previewId);

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $child->uid])
            ->get('/dashboard?tab=settings')
            ->assertOk()
            ->assertSee('Review hierarchy change')
            ->assertSee('1 workspace')
            ->assertSee('0 affected pages');

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $child->uid])
            ->put("/workspaces/{$child->uid}/hierarchy", [
                'parent_workspace_uid' => '',
            ])
            ->assertSessionHasErrors('confirmed');

        $this->assertSame($root->uid, $child->refresh()->parent_workspace_uid);

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $child->uid])
            ->put("/workspaces/{$child->uid}/hierarchy", [
                'parent_workspace_uid' => '',
                'confirmed' => '1',
                'preview_id' => $previewId,
            ])
            ->assertRedirect('/dashboard?tab=settings')
            ->assertSessionHas('status', 'Workspace hierarchy updated.');

        $this->assertNull($child->refresh()->parent_workspace_uid);
    }

    public function test_inherited_member_can_switch_to_child_and_can_be_removed_at_that_boundary(): void
    {
        $admin = $this->createUser('Hierarchy Admin', 'hierarchy-switch-admin@example.test');
        $member = $this->createUser('Inherited Editor', 'hierarchy-switch-member@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Root Workspace');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Child Workspace');
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        $membership = $this->addMember($root, $member, WorkspaceRole::Editor);

        $this->actingAs($member)
            ->post("/workspaces/{$child->uid}/switch")
            ->assertRedirect();
        $this->assertSame($child->uid, session('current_workspace_uid'));

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $child->uid])
            ->get('/dashboard?tab=members')
            ->assertOk()
            ->assertSee('Inherited from Root Workspace')
            ->assertSee('editor · inherited')
            ->assertDontSee("/workspaces/{$child->uid}/memberships/{$membership->uid}", false)
            ->assertSee("/workspaces/{$child->uid}/inherited-members/{$member->uid}", false);

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $child->uid])
            ->delete("/workspaces/{$child->uid}/inherited-members/{$member->uid}")
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'Inherited workspace access removed.');

        $this->assertDatabaseHas('workspace_membership_exclusions', [
            'workspace_uid' => $child->uid,
            'user_uid' => $member->uid,
        ]);
        $this->actingAs($member)
            ->post("/workspaces/{$child->uid}/switch")
            ->assertForbidden();
    }

    public function test_workspace_creation_inherits_parent_members_by_default_and_accepts_an_explicit_opt_out(): void
    {
        $admin = $this->createUser('Creation Inheritance Admin', 'creation-inheritance-admin@example.test');
        $member = $this->createUser('Creation Parent Member', 'creation-parent-member@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Creation Root');
        $this->addMember($root, $member, WorkspaceRole::Reader);

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $root->uid])
            ->get('/dashboard?tab=settings')
            ->assertOk()
            ->assertSee('name="inherits_parent_memberships"', false)
            ->assertSee('value="1" checked', false);

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $root->uid])
            ->post('/workspaces', [
                'name' => 'Isolated Child',
                'parent_workspace_uid' => $root->uid,
                'inherits_parent_memberships' => '0',
            ])
            ->assertRedirect('/dashboard');

        $child = Workspace::query()->where('name', 'Isolated Child')->sole();

        $this->assertFalse($child->inherits_parent_memberships);
        $this->actingAs($member)
            ->post("/workspaces/{$child->uid}/switch")
            ->assertForbidden();
    }

    public function test_outsider_cannot_create_or_reparent_under_an_inaccessible_workspace(): void
    {
        $admin = $this->createUser('Hierarchy Admin', 'hierarchy-deny-admin@example.test');
        $outsider = $this->createUser('Hierarchy Outsider', 'hierarchy-deny-outsider@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Root Workspace');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Child Workspace');

        $this->actingAs($outsider)
            ->post('/workspaces', [
                'name' => 'Unauthorized Child',
                'parent_workspace_uid' => $root->uid,
            ])
            ->assertForbidden();

        $this->actingAs($outsider)
            ->put("/workspaces/{$child->uid}/hierarchy", [
                'parent_workspace_uid' => $root->uid,
            ])
            ->assertNotFound();
    }

    public function test_actual_grandchild_is_not_offered_as_a_parent_when_its_ancestors_are_hidden(): void
    {
        $admin = $this->createUser('Depth Admin', 'hierarchy-depth-admin@example.test');
        $limitedAdmin = $this->createUser('Limited Admin', 'hierarchy-depth-limited@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Hidden Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Hidden Child');
        $grandchild = app(CreateSharedWorkspace::class)->handle($admin, 'Visible Grandchild');
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($grandchild->uid, $child->uid, true));
        $this->addMember($grandchild, $limitedAdmin, WorkspaceRole::Admin);

        $items = app(WorkspaceContext::class)->itemsFor($limitedAdmin);
        $this->assertCount(1, $items);
        $this->assertSame(0, $items[0]->depth);
        $this->assertSame([], app(WorkspaceContext::class)->parentItemsFor($limitedAdmin));
    }

    public function test_hidden_ancestor_depth_is_not_disclosed_by_a_forged_child_creation(): void
    {
        $owner = $this->createUser('Hidden Depth Owner', 'hidden-depth-owner@example.test');
        $limitedAdmin = $this->createUser('Hidden Depth Admin', 'hidden-depth-admin@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($owner, 'Secret Root');
        $child = app(CreateSharedWorkspace::class)->handle($owner, 'Secret Child');
        $grandchild = app(CreateSharedWorkspace::class)->handle($owner, 'Visible Leaf');
        app(ReparentWorkspace::class)->handle($owner, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        app(ReparentWorkspace::class)->handle($owner, new ReparentWorkspaceCommand($grandchild->uid, $child->uid, true));
        $this->addMember($grandchild, $limitedAdmin, WorkspaceRole::Admin);

        $this->actingAs($limitedAdmin)
            ->withSession(['current_workspace_uid' => $grandchild->uid])
            ->from('/dashboard')
            ->post('/workspaces', [
                'name' => 'Forbidden Fourth Level',
                'parent_workspace_uid' => $grandchild->uid,
            ])
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors([
                'parent_workspace_uid' => 'This workspace cannot contain a child workspace.',
            ]);

        $this->assertFalse(Workspace::query()->where('name', 'Forbidden Fourth Level')->exists());
    }

    public function test_hidden_inheritance_origin_has_no_name_or_parent_placeholder(): void
    {
        $owner = $this->createUser('Hidden Origin Owner', 'hidden-origin-owner@example.test');
        $childAdmin = $this->createUser('Child-only Admin', 'child-only-admin@example.test');
        $inheritedMember = $this->createUser('Hidden Origin Member', 'hidden-origin-member@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($owner, 'Invisible Origin');
        $child = app(CreateSharedWorkspace::class)->handle($owner, 'Visible Child');
        app(ReparentWorkspace::class)->handle($owner, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        $this->addMember($child, $childAdmin, WorkspaceRole::Admin);
        $this->addMember($root, $inheritedMember, WorkspaceRole::Reader);

        $this->actingAs($childAdmin)
            ->withSession(['current_workspace_uid' => $child->uid])
            ->get('/dashboard?tab=members')
            ->assertOk()
            ->assertSee('Hidden Origin Member')
            ->assertSee('reader · inherited')
            ->assertDontSee('Invisible Origin')
            ->assertDontSee('Inherited from parent workspace');
    }

    public function test_member_removal_controls_use_only_pages_that_would_actually_lose_write_authority(): void
    {
        $admin = $this->createUser('Removal UI Admin', 'removal-ui-admin@example.test');
        $member = $this->createUser('Retained Child Owner', 'removal-ui-member@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Removal UI Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Removal UI Child');
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        $this->addMember($root, $member, WorkspaceRole::Editor);
        $this->addMember($child, $member, WorkspaceRole::Editor);
        Page::factory()->create([
            'workspace_uid' => $child->uid,
            'owner_user_uid' => $member->uid,
        ]);

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $root->uid])
            ->get('/dashboard?tab=members')
            ->assertOk()
            ->assertDontSee('Reassign owned pages to');
    }

    public function test_replacement_picker_includes_a_writer_for_the_exact_owned_workspace_loss_set(): void
    {
        $admin = $this->createUser('Candidate UI Admin', 'candidate-ui-admin@example.test');
        $departing = $this->createUser('Departing Root Owner', 'candidate-ui-departing@example.test');
        $candidate = $this->createUser('Grandchild-only Replacement', 'candidate-ui-replacement@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Candidate UI Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Candidate UI Child');
        $grandchild = app(CreateSharedWorkspace::class)->handle($admin, 'Candidate UI Grandchild');
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($grandchild->uid, $child->uid, true));
        $this->addMember($root, $departing, WorkspaceRole::Editor);
        $this->addMember($grandchild, $candidate, WorkspaceRole::Editor);
        Page::factory()->create([
            'workspace_uid' => $grandchild->uid,
            'owner_user_uid' => $departing->uid,
        ]);

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $root->uid])
            ->get('/dashboard?tab=members')
            ->assertOk()
            ->assertSee('Reassign owned pages to')
            ->assertSee('Grandchild-only Replacement');
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
