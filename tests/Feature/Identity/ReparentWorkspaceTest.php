<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\ReparentWorkspace;
use App\Application\Identity\ReparentWorkspaceCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipExclusion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ReparentWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_build_three_levels_and_reparent_a_subtree_with_traceability(): void
    {
        $admin = $this->createUser('Hierarchy Admin', 'hierarchy-admin@example.test');
        $firstRoot = app(CreateSharedWorkspace::class)->handle($admin, 'First Root');
        $secondRoot = app(CreateSharedWorkspace::class)->handle($admin, 'Second Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Child');
        $grandchild = app(CreateSharedWorkspace::class)->handle($admin, 'Grandchild');
        $childPage = Page::factory()->create([
            'workspace_uid' => $child->uid,
            'owner_user_uid' => $admin->uid,
            'access_mode' => PageAccessMode::Inherited,
        ]);
        $grandchildPage = Page::factory()->create([
            'workspace_uid' => $grandchild->uid,
            'owner_user_uid' => $admin->uid,
            'access_mode' => PageAccessMode::Inherited,
        ]);
        $grantedPage = Page::factory()->create([
            'workspace_uid' => $secondRoot->uid,
            'owner_user_uid' => $admin->uid,
            'access_mode' => PageAccessMode::Restricted,
        ]);
        PageAccessGrant::query()->forceCreate([
            'page_uid' => $grantedPage->uid,
            'subject_type' => PageAccessSubjectType::Workspace,
            'subject_uid' => $child->uid,
            'role' => WorkspaceRole::Reader,
            'granted_by_user_uid' => $admin->uid,
        ]);
        $reparent = app(ReparentWorkspace::class);

        $reparent->handle($admin, new ReparentWorkspaceCommand($child->uid, $firstRoot->uid, true));
        $reparent->handle($admin, new ReparentWorkspaceCommand($grandchild->uid, $child->uid, true));

        $this->assertSame($firstRoot->uid, $child->refresh()->parent_workspace_uid);
        $this->assertSame($child->uid, $grandchild->refresh()->parent_workspace_uid);
        $this->assertDatabaseHas('workspace_ancestry', [
            'ancestor_workspace_uid' => $firstRoot->uid,
            'descendant_workspace_uid' => $grandchild->uid,
            'depth' => 2,
        ]);

        $beforeReparent = [
            $childPage->uid => $childPage->refresh()->preview_access_revision,
            $grandchildPage->uid => $grandchildPage->refresh()->preview_access_revision,
            $grantedPage->uid => $grantedPage->refresh()->preview_access_revision,
        ];

        $reparent->handle($admin, new ReparentWorkspaceCommand($child->uid, $secondRoot->uid, true));

        $this->assertDatabaseMissing('workspace_ancestry', [
            'ancestor_workspace_uid' => $firstRoot->uid,
            'descendant_workspace_uid' => $grandchild->uid,
        ]);
        $this->assertDatabaseHas('workspace_ancestry', [
            'ancestor_workspace_uid' => $secondRoot->uid,
            'descendant_workspace_uid' => $grandchild->uid,
            'depth' => 2,
        ]);
        $this->assertGreaterThan($beforeReparent[$childPage->uid], $childPage->refresh()->preview_access_revision);
        $this->assertGreaterThan(
            $beforeReparent[$grandchildPage->uid],
            $grandchildPage->refresh()->preview_access_revision,
        );
        $this->assertGreaterThan($beforeReparent[$grantedPage->uid], $grantedPage->refresh()->preview_access_revision);

        $event = DomainEvent::query()
            ->where('event_type', 'workspace.hierarchy.changed')
            ->where('aggregate_uid', $child->uid)
            ->latest('uid')
            ->firstOrFail();
        $this->assertSame($child->uid, $event->aggregate_uid);
        $this->assertSame($firstRoot->uid, $event->payload['previous_parent_workspace_uid']);
        $this->assertSame($secondRoot->uid, $event->payload['new_parent_workspace_uid']);
        $this->assertSame(3, $event->payload['affected_page_count']);
        $this->assertArrayNotHasKey('workspace_name', $event->payload);

        $audit = AuditEntry::query()
            ->where('action', 'workspace.hierarchy.changed')
            ->where('auditable_uid', $child->uid)
            ->latest('uid')
            ->firstOrFail();
        $this->assertSame($event->uid, $audit->event_uid);
        $this->assertSame($admin->uid, $audit->actor_user_uid);
    }

    public function test_cycle_fourth_level_personal_and_unauthorized_reparents_are_rejected(): void
    {
        $admin = $this->createUser('Hierarchy Guard Admin', 'hierarchy-guard-admin@example.test');
        $outsider = $this->createUser('Hierarchy Outsider', 'hierarchy-outsider@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Child');
        $grandchild = app(CreateSharedWorkspace::class)->handle($admin, 'Grandchild');
        $fourth = app(CreateSharedWorkspace::class)->handle($admin, 'Fourth');
        $personal = app(CreatePersonalWorkspaceForUser::class)->handle($admin);
        $reparent = app(ReparentWorkspace::class);

        $reparent->handle($admin, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        $reparent->handle($admin, new ReparentWorkspaceCommand($grandchild->uid, $child->uid, true));

        $this->assertDomainViolation(
            'Workspace hierarchy is limited to three levels.',
            static fn () => $reparent->handle(
                $admin,
                new ReparentWorkspaceCommand($fourth->uid, $grandchild->uid, true),
            ),
        );
        $this->assertDomainViolation(
            'A workspace cannot be moved inside its own subtree.',
            static fn () => $reparent->handle(
                $admin,
                new ReparentWorkspaceCommand($root->uid, $grandchild->uid, true),
            ),
        );
        $this->assertDomainViolation(
            'Personal workspaces cannot participate in a workspace hierarchy.',
            static fn () => $reparent->handle(
                $admin,
                new ReparentWorkspaceCommand($personal->uid, $root->uid, true),
            ),
        );

        try {
            $reparent->handle($outsider, new ReparentWorkspaceCommand($child->uid, null, true));
            $this->fail('Expected an outsider hierarchy mutation to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Only workspace admins can change workspace hierarchy.', $exception->getMessage());
        }

        $this->assertNull($fourth->refresh()->parent_workspace_uid);
        $this->assertSame($root->uid, $child->refresh()->parent_workspace_uid);
        $this->assertSame($child->uid, $grandchild->refresh()->parent_workspace_uid);
    }

    public function test_reparent_is_blocked_when_an_inherited_page_owner_would_lose_write_access(): void
    {
        $admin = $this->createUser('Ownership Admin', 'ownership-admin@example.test');
        $inheritedOwner = $this->createUser('Inherited Owner', 'inherited-owner@example.test');
        $oldRoot = app(CreateSharedWorkspace::class)->handle($admin, 'Old Root');
        $newRoot = app(CreateSharedWorkspace::class)->handle($admin, 'New Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Owned Child');
        $this->addMember($oldRoot, $inheritedOwner, WorkspaceRole::Editor);
        $reparent = app(ReparentWorkspace::class);
        $reparent->handle($admin, new ReparentWorkspaceCommand($child->uid, $oldRoot->uid, true));
        Page::factory()->create([
            'workspace_uid' => $child->uid,
            'owner_user_uid' => $inheritedOwner->uid,
            'access_mode' => PageAccessMode::Inherited,
        ]);

        $this->assertDomainViolation(
            'Reassign pages whose owners would lose workspace edit access before moving this workspace.',
            static fn () => $reparent->handle(
                $admin,
                new ReparentWorkspaceCommand($child->uid, $newRoot->uid, true),
            ),
        );

        $this->assertSame($oldRoot->uid, $child->refresh()->parent_workspace_uid);
        $this->assertDatabaseHas('workspace_ancestry', [
            'ancestor_workspace_uid' => $oldRoot->uid,
            'descendant_workspace_uid' => $child->uid,
            'depth' => 1,
        ]);
        $this->assertDatabaseMissing('workspace_ancestry', [
            'ancestor_workspace_uid' => $newRoot->uid,
            'descendant_workspace_uid' => $child->uid,
        ]);
    }

    public function test_reparent_revokes_pending_invitations_in_workspaces_where_inherited_authority_is_reduced(): void
    {
        $admin = $this->createUser('Reparent Invitation Admin', 'reparent-invitation-admin@example.test');
        $member = $this->createUser('Reparent Invitation Member', 'reparent-invitation-member@example.test');
        $oldRoot = app(CreateSharedWorkspace::class)->handle($admin, 'Invitation Old Root');
        $newRoot = app(CreateSharedWorkspace::class)->handle($admin, 'Invitation New Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Invitation Moving Child');
        $this->addMember($oldRoot, $member, WorkspaceRole::Admin);
        $reparent = app(ReparentWorkspace::class);
        $reparent->handle($admin, new ReparentWorkspaceCommand($child->uid, $oldRoot->uid, true));
        $invitation = WorkspaceInvitation::query()->forceCreate([
            'workspace_uid' => $child->uid,
            'invited_email' => strtolower($member->email),
            'role' => WorkspaceRole::Editor,
            'invited_by_user_uid' => $admin->uid,
            'expires_at' => now()->addDay(),
        ]);

        $reparent->handle($admin, new ReparentWorkspaceCommand($child->uid, $newRoot->uid, true));

        $this->assertNotNull($invitation->refresh()->revoked_at);
        $event = DomainEvent::query()
            ->where('event_type', 'workspace.hierarchy.changed')
            ->where('aggregate_uid', $child->uid)
            ->latest('uid')
            ->firstOrFail();
        $this->assertSame(0, $event->payload['gained_user_count']);
        $this->assertSame(1, $event->payload['reduced_user_count']);
        $this->assertSame(1, $event->payload['revoked_invitation_count']);
    }

    public function test_reparent_rejects_a_confirmation_when_the_authority_impact_changed_after_preview(): void
    {
        $admin = $this->createUser('Stale Preview Admin', 'stale-preview-admin@example.test');
        $newMember = $this->createUser('Stale Preview Member', 'stale-preview-member@example.test');
        $oldRoot = app(CreateSharedWorkspace::class)->handle($admin, 'Stale Preview Old Root');
        $newRoot = app(CreateSharedWorkspace::class)->handle($admin, 'Stale Preview New Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Stale Preview Child');
        $reparent = app(ReparentWorkspace::class);
        $reparent->handle($admin, new ReparentWorkspaceCommand($child->uid, $oldRoot->uid, true));
        $preview = $reparent->preview($admin, new ReparentWorkspaceCommand($child->uid, $newRoot->uid));
        $this->assertSame(0, $preview->gainedUserCount);

        $this->addMember($newRoot, $newMember, WorkspaceRole::Editor);

        $this->assertDomainViolation(
            'Workspace hierarchy impact changed. Review the current impact before confirming again.',
            static fn () => $reparent->handle(
                $admin,
                new ReparentWorkspaceCommand(
                    workspaceUid: $child->uid,
                    newParentWorkspaceUid: $newRoot->uid,
                    confirmed: true,
                    expectedImpact: $preview,
                ),
            ),
        );
        $this->assertSame($oldRoot->uid, $child->refresh()->parent_workspace_uid);
    }

    public function test_reparent_simulation_applies_disabled_inheritance_on_the_proposed_path(): void
    {
        $this->assertProposedInheritanceBarrierRetiresLostAuthority(false);
    }

    public function test_reparent_simulation_applies_user_exclusions_on_the_proposed_path(): void
    {
        $this->assertProposedInheritanceBarrierRetiresLostAuthority(true);
    }

    public function test_promoting_an_opted_out_child_to_root_resets_inheritance_before_reattachment(): void
    {
        $admin = $this->createUser('Root Promotion Admin', 'root-promotion-admin@example.test');
        $member = $this->createUser('Root Promotion Member', 'root-promotion-member@example.test');
        $parent = app(CreateSharedWorkspace::class)->handle($admin, 'Root Promotion Parent');
        $child = app(CreateSharedWorkspace::class)->handle(
            $admin,
            'Root Promotion Child',
            $parent->uid,
            false,
        );
        $this->addMember($parent, $member, WorkspaceRole::Reader);
        $reparent = app(ReparentWorkspace::class);

        $this->assertFalse($child->inherits_parent_memberships);

        $reparent->handle($admin, new ReparentWorkspaceCommand($child->uid, null, true));

        $this->assertNull($child->refresh()->parent_workspace_uid);
        $this->assertTrue($child->inherits_parent_memberships);

        $reparent->handle($admin, new ReparentWorkspaceCommand($child->uid, $parent->uid, true));

        $effective = app(\App\Application\Identity\EffectiveWorkspaceMembershipResolver::class)
            ->resolve($member->uid, $child->uid);
        $this->assertSame(WorkspaceRole::Reader, $effective->role);
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function addMember(Workspace $workspace, User $user, WorkspaceRole $role): void
    {
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $user->uid,
            'role' => $role,
            'accepted_at' => now(),
        ]);
    }

    private function assertProposedInheritanceBarrierRetiresLostAuthority(bool $useUserExclusion): void
    {
        $suffix = $useUserExclusion ? 'exclusion' : 'opt-out';
        $admin = $this->createUser(
            'Proposed Barrier Admin',
            "proposed-barrier-admin-{$suffix}@example.test",
        );
        $member = $this->createUser(
            'Proposed Barrier Member',
            "proposed-barrier-member-{$suffix}@example.test",
        );
        $oldRoot = app(CreateSharedWorkspace::class)->handle($admin, "Old Root {$suffix}");
        $newGrandparent = app(CreateSharedWorkspace::class)->handle($admin, "New Grandparent {$suffix}");
        $newParent = app(CreateSharedWorkspace::class)->handle(
            $admin,
            "New Parent {$suffix}",
            $newGrandparent->uid,
            $useUserExclusion,
        );
        $movingWorkspace = app(CreateSharedWorkspace::class)->handle(
            $admin,
            "Moving Workspace {$suffix}",
            $oldRoot->uid,
        );
        $this->addMember($oldRoot, $member, WorkspaceRole::Editor);
        $this->addMember($newGrandparent, $member, WorkspaceRole::Editor);

        if ($useUserExclusion) {
            WorkspaceMembershipExclusion::query()->forceCreate([
                'workspace_uid' => $newParent->uid,
                'user_uid' => $member->uid,
                'excluded_by_user_uid' => $admin->uid,
            ]);
        }

        $page = Page::factory()->create([
            'workspace_uid' => $movingWorkspace->uid,
            'owner_user_uid' => $admin->uid,
            'access_mode' => PageAccessMode::Restricted,
        ]);
        $grant = PageAccessGrant::query()->forceCreate([
            'page_uid' => $page->uid,
            'subject_type' => PageAccessSubjectType::User,
            'subject_uid' => $member->uid,
            'role' => WorkspaceRole::Admin,
            'granted_by_user_uid' => $admin->uid,
        ]);
        $reparent = app(ReparentWorkspace::class);
        $command = new ReparentWorkspaceCommand($movingWorkspace->uid, $newParent->uid);

        $preview = $reparent->preview($admin, $command);

        $this->assertSame(1, $preview->reducedUserCount);

        $reparent->handle($admin, new ReparentWorkspaceCommand(
            workspaceUid: $movingWorkspace->uid,
            newParentWorkspaceUid: $newParent->uid,
            confirmed: true,
            expectedImpact: $preview,
        ));

        $this->assertDatabaseMissing('page_access_grants', ['uid' => $grant->uid]);
        $this->assertDatabaseHas('workspace_membership_removals', [
            'workspace_uid' => $movingWorkspace->uid,
            'user_uid' => $member->uid,
        ]);
        $event = DomainEvent::query()
            ->where('event_type', 'workspace.hierarchy.changed')
            ->where('aggregate_uid', $movingWorkspace->uid)
            ->latest('uid')
            ->firstOrFail();
        $this->assertSame(1, $event->payload['reduced_user_count']);
        $this->assertSame(1, $event->payload['revoked_page_access_grant_count']);
    }

    private function assertDomainViolation(string $message, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a workspace hierarchy domain rule violation.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
