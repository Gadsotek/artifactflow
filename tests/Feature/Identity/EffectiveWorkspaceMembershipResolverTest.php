<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\EffectiveWorkspaceMembershipResolver;
use App\Domain\Identity\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EffectiveWorkspaceMembershipResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_membership_flows_downward_and_a_direct_child_role_can_elevate_it(): void
    {
        [$root, $child, $grandchild] = $this->threeLevelHierarchy();
        $member = $this->createUser('Inherited Member', 'inherited-member@example.test');

        $rootMembership = $this->addMember($root, $member, WorkspaceRole::Reader);
        $childMembership = $this->addMember($child, $member, WorkspaceRole::Editor);

        $resolver = app(EffectiveWorkspaceMembershipResolver::class);
        $rootAuthority = $resolver->resolve($member->uid, $root->uid);
        $childAuthority = $resolver->resolve($member->uid, $child->uid);
        $grandchildAuthority = $resolver->resolve($member->uid, $grandchild->uid);

        $this->assertSame(WorkspaceRole::Reader, $rootAuthority->role);
        $this->assertSame(WorkspaceRole::Editor, $childAuthority->role);
        $this->assertSame(WorkspaceRole::Editor, $grandchildAuthority->role);
        $this->assertSame([$rootMembership->uid], array_map(
            static fn ($origin): string => $origin->membershipUid,
            $rootAuthority->origins,
        ));
        $this->assertSame(
            [$childMembership->uid, $rootMembership->uid],
            array_map(
                static fn ($origin): string => $origin->membershipUid,
                $grandchildAuthority->origins,
            ),
        );
        $this->assertTrue($grandchildAuthority->origins[0]->isInherited);
        $this->assertTrue($grandchildAuthority->origins[1]->isInherited);
    }

    public function test_child_only_membership_does_not_flow_to_a_parent_or_sibling(): void
    {
        [$root, $child, $grandchild, $sibling] = $this->threeLevelHierarchyWithSibling();
        $member = $this->createUser('Child Member', 'child-member@example.test');
        $this->addMember($child, $member, WorkspaceRole::Editor);

        $resolver = app(EffectiveWorkspaceMembershipResolver::class);

        $this->assertNull($resolver->resolve($member->uid, $root->uid)->role);
        $this->assertSame(WorkspaceRole::Editor, $resolver->resolve($member->uid, $child->uid)->role);
        $this->assertSame(WorkspaceRole::Editor, $resolver->resolve($member->uid, $grandchild->uid)->role);
        $this->assertNull($resolver->resolve($member->uid, $sibling->uid)->role);
        $this->assertSame(
            [$child->uid, $grandchild->uid],
            $resolver->workspaceUidsFor($member->uid),
        );
    }

    public function test_workspace_exclusion_blocks_only_ancestor_memberships_at_that_boundary_and_below(): void
    {
        [$root, $child, $grandchild, $sibling] = $this->threeLevelHierarchyWithSibling();
        $member = $this->createUser('Excluded Inherited Member', 'excluded-inherited-member@example.test');
        $this->addMember($root, $member, WorkspaceRole::Reader);

        DB::table('workspace_membership_exclusions')->insert([
            'uid' => (string) Str::ulid(),
            'workspace_uid' => $child->uid,
            'user_uid' => $member->uid,
            'excluded_by_user_uid' => WorkspaceMembership::query()
                ->where('workspace_uid', $child->uid)
                ->where('role', WorkspaceRole::Admin)
                ->valueOrFail('user_uid'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolver = app(EffectiveWorkspaceMembershipResolver::class);

        $this->assertSame(WorkspaceRole::Reader, $resolver->resolve($member->uid, $root->uid)->role);
        $this->assertNull($resolver->resolve($member->uid, $child->uid)->role);
        $this->assertNull($resolver->resolve($member->uid, $grandchild->uid)->role);
        $this->assertSame(WorkspaceRole::Reader, $resolver->resolve($member->uid, $sibling->uid)->role);
        $this->assertSame([$root->uid, $sibling->uid], $resolver->workspaceUidsFor($member->uid));
        $this->assertNotContains(
            $member->uid,
            $resolver->userUidsForAny([$child->uid, $grandchild->uid]),
        );

        $this->addMember($grandchild, $member, WorkspaceRole::Editor);

        $this->assertSame(WorkspaceRole::Editor, $resolver->resolve($member->uid, $grandchild->uid)->role);
        $this->assertSame(
            [$root->uid, $grandchild->uid, $sibling->uid],
            $resolver->workspaceUidsFor($member->uid),
        );
    }

    public function test_child_can_opt_out_of_parent_membership_inheritance_without_blocking_direct_child_members(): void
    {
        $owner = $this->createUser('Opt-out Owner', 'opt-out-owner@example.test');
        $parentMember = $this->createUser('Parent Member', 'opt-out-parent-member@example.test');
        $childMember = $this->createUser('Child Member', 'opt-out-child-member@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($owner, 'Opt-out Root');
        $this->addMember($root, $parentMember, WorkspaceRole::Editor);
        $child = app(CreateSharedWorkspace::class)->handle($owner, 'Opt-out Child', $root->uid, false);
        $this->addMember($child, $childMember, WorkspaceRole::Reader);
        $grandchild = app(CreateSharedWorkspace::class)->handle($owner, 'Opt-out Grandchild', $child->uid);

        $resolver = app(EffectiveWorkspaceMembershipResolver::class);

        $this->assertFalse($child->inherits_parent_memberships);
        $this->assertNull($resolver->resolve($parentMember->uid, $child->uid)->role);
        $this->assertNull($resolver->resolve($parentMember->uid, $grandchild->uid)->role);
        $this->assertSame(WorkspaceRole::Reader, $resolver->resolve($childMember->uid, $child->uid)->role);
        $this->assertSame(WorkspaceRole::Reader, $resolver->resolve($childMember->uid, $grandchild->uid)->role);
        $this->assertSame(WorkspaceRole::Admin, $resolver->resolve($owner->uid, $child->uid)->role);
    }

    public function test_allowed_role_filtering_is_batched_for_all_candidate_users_and_workspaces(): void
    {
        [$root, $child, $grandchild] = $this->threeLevelHierarchy();

        for ($index = 1; $index <= 20; ++$index) {
            $member = $this->createUser(
                'Batch Member ' . $index,
                'batch-member-' . $index . '@example.test',
            );
            $this->addMember($root, $member, WorkspaceRole::Editor);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $userUids = app(EffectiveWorkspaceMembershipResolver::class)->userUidsForAny(
            [$root->uid, $child->uid, $grandchild->uid],
            [WorkspaceRole::Editor, WorkspaceRole::Admin],
        );
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(21, $userUids);
        $this->assertLessThanOrEqual(
            6,
            $queryCount,
            'Effective-role filtering must not add ancestry and membership queries per candidate user.',
        );
    }

    /**
     * @return array{Workspace, Workspace, Workspace}
     */
    private function threeLevelHierarchy(): array
    {
        [$root, $child, $grandchild] = array_slice($this->threeLevelHierarchyWithSibling(), 0, 3);

        return [$root, $child, $grandchild];
    }

    /**
     * @return array{Workspace, Workspace, Workspace, Workspace}
     */
    private function threeLevelHierarchyWithSibling(): array
    {
        $owner = $this->createUser('Hierarchy Fixture Owner', 'hierarchy-fixture-owner@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($owner, 'Root');
        $child = app(CreateSharedWorkspace::class)->handle($owner, 'Child');
        $grandchild = app(CreateSharedWorkspace::class)->handle($owner, 'Grandchild');
        $sibling = app(CreateSharedWorkspace::class)->handle($owner, 'Sibling');

        Workspace::query()->whereKey($child->uid)->update(['parent_workspace_uid' => $root->uid]);
        Workspace::query()->whereKey($grandchild->uid)->update(['parent_workspace_uid' => $child->uid]);
        Workspace::query()->whereKey($sibling->uid)->update(['parent_workspace_uid' => $root->uid]);

        DB::table('workspace_ancestry')->insert([
            [
                'ancestor_workspace_uid' => $root->uid,
                'descendant_workspace_uid' => $child->uid,
                'depth' => 1,
            ],
            [
                'ancestor_workspace_uid' => $child->uid,
                'descendant_workspace_uid' => $grandchild->uid,
                'depth' => 1,
            ],
            [
                'ancestor_workspace_uid' => $root->uid,
                'descendant_workspace_uid' => $grandchild->uid,
                'depth' => 2,
            ],
            [
                'ancestor_workspace_uid' => $root->uid,
                'descendant_workspace_uid' => $sibling->uid,
                'depth' => 1,
            ],
        ]);

        return [$root, $child, $grandchild, $sibling];
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function addMember(
        Workspace $workspace,
        User $user,
        WorkspaceRole $role,
    ): WorkspaceMembership {
        return WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $user->uid,
            'role' => $role,
            'accepted_at' => now(),
        ]);
    }
}
