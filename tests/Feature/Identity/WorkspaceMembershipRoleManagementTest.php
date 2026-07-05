<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\ChangeWorkspaceMembershipRole;
use App\Application\Identity\ChangeWorkspaceMembershipRoleCommand;
use App\Application\Identity\CreateSharedWorkspace;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class WorkspaceMembershipRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_can_change_a_member_role_with_traceability_and_idempotency(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Reader);
        $handler = app(ChangeWorkspaceMembershipRole::class);
        $command = new ChangeWorkspaceMembershipRoleCommand(
            workspaceUid: $workspace->uid,
            membershipUid: $membership->uid,
            role: WorkspaceRole::Editor,
        );

        $updatedMembership = $handler->handle($admin, $command);
        $repeatedMembership = $handler->handle($admin, $command);

        $this->assertSame($membership->uid, $updatedMembership->uid);
        $this->assertSame(WorkspaceRole::Editor, $updatedMembership->role);
        $this->assertSame(WorkspaceRole::Editor, $repeatedMembership->role);

        $event = DomainEvent::query()
            ->where('event_type', 'workspace.membership.role_changed')
            ->sole();

        $this->assertSame('workspace_membership', $event->aggregate_type);
        $this->assertSame($membership->uid, $event->aggregate_uid);
        $this->assertSame($workspace->uid, $event->payload['workspace_uid']);
        $this->assertSame($member->uid, $event->payload['member_user_uid']);
        $this->assertSame($admin->uid, $event->payload['changed_by_user_uid']);
        $this->assertSame('reader', $event->payload['previous_role']);
        $this->assertSame('editor', $event->payload['new_role']);

        $audit = AuditEntry::query()
            ->where('action', 'workspace.membership.role_changed')
            ->sole();

        $this->assertSame($event->uid, $audit->event_uid);
        $this->assertSame($admin->uid, $audit->actor_user_uid);
        $this->assertSame('workspace_membership', $audit->auditable_type);
        $this->assertSame($membership->uid, $audit->auditable_uid);
        $this->assertSame($workspace->uid, $audit->metadata['workspace_uid']);
        $this->assertSame('reader', $audit->metadata['previous_role']);
        $this->assertSame('editor', $audit->metadata['new_role']);
    }

    public function test_non_admin_and_outsider_cannot_change_workspace_member_roles(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $target = $this->createUser('Target User', 'target@example.test');
        $outsider = $this->createUser('Outside User', 'outside@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);
        $targetMembership = $this->addMember($workspace->uid, $target, WorkspaceRole::Reader);
        $command = new ChangeWorkspaceMembershipRoleCommand(
            workspaceUid: $workspace->uid,
            membershipUid: $targetMembership->uid,
            role: WorkspaceRole::Editor,
        );

        foreach ([$editor, $outsider] as $actor) {
            try {
                app(ChangeWorkspaceMembershipRole::class)->handle($actor, $command);
                $this->fail('Expected workspace role management to be forbidden.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('Only workspace admins can change member roles.', $exception->getMessage());
            }
        }

        $this->assertSame(WorkspaceRole::Reader, $targetMembership->refresh()->role);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.membership.role_changed')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'workspace.membership.role_changed')->count());
    }

    public function test_personal_workspace_membership_roles_are_immutable(): void
    {
        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = Workspace::query()
            ->where('personal_owner_uid', $owner->uid)
            ->sole();
        $membership = WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $owner->uid)
            ->sole();

        try {
            app(ChangeWorkspaceMembershipRole::class)->handle($owner, new ChangeWorkspaceMembershipRoleCommand(
                workspaceUid: $workspace->uid,
                membershipUid: $membership->uid,
                role: WorkspaceRole::Reader,
            ));
            $this->fail('Expected personal workspace role changes to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Personal workspace membership roles cannot be changed.', $exception->getMessage());
        }

        $this->assertSame(WorkspaceRole::Admin, $membership->refresh()->role);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.membership.role_changed')->count());
    }

    public function test_last_workspace_admin_cannot_be_demoted_but_an_admin_can_step_down_when_another_remains(): void
    {
        $firstAdmin = $this->createUser('First Admin', 'first-admin@example.test');
        $secondAdmin = $this->createUser('Second Admin', 'second-admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($firstAdmin, 'Platform Team');
        $firstMembership = WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $firstAdmin->uid)
            ->sole();

        try {
            app(ChangeWorkspaceMembershipRole::class)->handle($firstAdmin, new ChangeWorkspaceMembershipRoleCommand(
                workspaceUid: $workspace->uid,
                membershipUid: $firstMembership->uid,
                role: WorkspaceRole::Editor,
            ));
            $this->fail('Expected the last workspace admin demotion to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('A shared workspace must retain at least one admin.', $exception->getMessage());
        }

        $this->assertSame(WorkspaceRole::Admin, $firstMembership->refresh()->role);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.membership.role_changed')->count());

        $this->addMember($workspace->uid, $secondAdmin, WorkspaceRole::Admin);

        $updatedMembership = app(ChangeWorkspaceMembershipRole::class)->handle(
            $firstAdmin,
            new ChangeWorkspaceMembershipRoleCommand(
                workspaceUid: $workspace->uid,
                membershipUid: $firstMembership->uid,
                role: WorkspaceRole::Editor,
            ),
        );

        $this->assertSame(WorkspaceRole::Editor, $updatedMembership->role);
        $this->assertSame(1, WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('role', WorkspaceRole::Admin)
            ->count());
    }

    public function test_role_demotion_bumps_preview_access_revisions_for_workspace_pages(): void
    {
        $admin = $this->createUser('Admin User', 'admin-role-revision@example.test');
        $demotedAdmin = $this->createUser('Demoted Admin', 'demoted-admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Preview Revision Team');
        $membership = $this->addMember($workspace->uid, $demotedAdmin, WorkspaceRole::Admin);
        $restrictedPage = Page::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'owner_user_uid' => $admin->uid,
            'title' => 'Restricted preview page',
            'slug' => 'restricted-preview-page',
            'description' => null,
            'access_mode' => PageAccessMode::Restricted,
            'type' => PageType::Markdown,
            'status' => PageStatus::Draft,
        ]);
        $inheritedPage = Page::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'owner_user_uid' => $admin->uid,
            'title' => 'Inherited preview page',
            'slug' => 'inherited-preview-page',
            'description' => null,
            'access_mode' => PageAccessMode::Inherited,
            'type' => PageType::Markdown,
            'status' => PageStatus::Draft,
        ]);

        app(ChangeWorkspaceMembershipRole::class)->handle($admin, new ChangeWorkspaceMembershipRoleCommand(
            workspaceUid: $workspace->uid,
            membershipUid: $membership->uid,
            role: WorkspaceRole::Editor,
        ));

        $this->assertSame(1, $restrictedPage->refresh()->preview_access_revision);
        $this->assertSame(1, $inheritedPage->refresh()->preview_access_revision);
    }

    public function test_page_owner_cannot_be_demoted_to_reader_until_ownership_is_reassigned(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $owner = $this->createUser('Page Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = $this->addMember($workspace->uid, $owner, WorkspaceRole::Editor);
        $page = Page::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'owner_user_uid' => $owner->uid,
            'title' => 'Owned page',
            'slug' => 'owned-page',
            'description' => null,
            'access_mode' => PageAccessMode::Inherited,
            'type' => PageType::Markdown,
            'status' => PageStatus::Draft,
        ]);

        try {
            app(ChangeWorkspaceMembershipRole::class)->handle($admin, new ChangeWorkspaceMembershipRoleCommand(
                workspaceUid: $workspace->uid,
                membershipUid: $membership->uid,
                role: WorkspaceRole::Reader,
            ));
            $this->fail('Expected page owner demotion to Reader to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame(
                'Reassign pages owned by this member before changing their role to Reader.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(WorkspaceRole::Editor, $membership->refresh()->role);
        $this->assertSame($owner->uid, $page->refresh()->owner_user_uid);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.membership.role_changed')->count());
    }

    public function test_workspace_admin_can_manage_member_roles_from_the_dashboard(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Reader);

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Workspace members')
            ->assertSee('member@example.test')
            ->assertSee("workspaces/{$workspace->uid}/memberships/{$membership->uid}", false)
            ->assertSee('Update role');

        $this->actingAs($admin)
            ->put("/workspaces/{$workspace->uid}/memberships/{$membership->uid}", [
                'role' => WorkspaceRole::Editor->value,
            ])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'Workspace member role updated.')
            ->assertSessionHas('current_workspace_uid', $workspace->uid);

        $this->assertSame(WorkspaceRole::Editor, $membership->refresh()->role);
    }

    public function test_dashboard_paginates_workspace_members_twenty_at_a_time(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        foreach (range(1, 21) as $number) {
            $member = $this->createUser(
                sprintf('Member %02d', $number),
                sprintf('member-%02d@example.test', $number),
            );
            $this->addMember($workspace->uid, $member, WorkspaceRole::Reader);
        }

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard?tab=members')
            ->assertOk()
            ->assertSee('data-workspace-tabs', false)
            ->assertSee('aria-selected="true"', false)
            ->assertSee('member-19@example.test')
            ->assertDontSee('member-20@example.test')
            ->assertSee('members_page=2', false);

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard?tab=members&members_page=2')
            ->assertOk()
            ->assertSee('member-20@example.test')
            ->assertSee('member-21@example.test')
            ->assertDontSee('member-01@example.test');
    }

    public function test_members_can_see_workspace_members_but_only_admins_can_submit_valid_role_changes(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $otherMember = $this->createUser('Other Member', 'other@example.test');
        $otherAdmin = $this->createUser('Other Admin', 'other-admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $readerMembership = $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);
        $otherMembership = $this->addMember($workspace->uid, $otherMember, WorkspaceRole::Reader);
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($otherAdmin, 'Other Team');
        $foreignMembership = $this->addMember($otherWorkspace->uid, $otherMember, WorkspaceRole::Reader);

        $this->put("/workspaces/{$workspace->uid}/memberships/{$otherMembership->uid}", [
            'role' => WorkspaceRole::Editor->value,
        ])->assertRedirect('/login');

        $this->actingAs($reader)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Workspace members')
            ->assertSee('other@example.test')
            ->assertDontSee("workspaces/{$workspace->uid}/memberships/{$otherMembership->uid}", false)
            ->assertDontSee('Update role');

        $this->actingAs($reader)
            ->put("/workspaces/{$workspace->uid}/memberships/{$otherMembership->uid}", [
                'role' => WorkspaceRole::Editor->value,
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->put("/workspaces/{$workspace->uid}/memberships/{$otherMembership->uid}", [
                'role' => 'owner',
            ])
            ->assertSessionHasErrors('role');

        $this->actingAs($admin)
            ->put("/workspaces/{$workspace->uid}/memberships/{$foreignMembership->uid}", [
                'role' => WorkspaceRole::Editor->value,
            ])
            ->assertNotFound();

        $this->assertSame(WorkspaceRole::Reader, $readerMembership->refresh()->role);
        $this->assertSame(WorkspaceRole::Reader, $otherMembership->refresh()->role);
        $this->assertSame(WorkspaceRole::Reader, $foreignMembership->refresh()->role);
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
