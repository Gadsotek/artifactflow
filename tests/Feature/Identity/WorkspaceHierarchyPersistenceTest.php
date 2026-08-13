<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Application\Identity\CreateSharedWorkspace;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class WorkspaceHierarchyPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_personal_and_shared_workspaces_start_as_hierarchy_roots(): void
    {
        $user = User::query()->create([
            'name' => 'Hierarchy Owner',
            'email' => 'hierarchy-owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $personal = app(CreatePersonalWorkspaceForUser::class)->handle($user);
        $shared = app(CreateSharedWorkspace::class)->handle($user, 'Hierarchy Root');
        $direct = Workspace::query()->forceCreate([
            'name' => 'Direct Root',
            'type' => WorkspaceType::Shared,
        ]);

        $this->assertNull($personal->parent_workspace_uid);
        $this->assertNull($shared->parent_workspace_uid);
        $this->assertDatabaseHas('workspace_ancestry', [
            'ancestor_workspace_uid' => $personal->uid,
            'descendant_workspace_uid' => $personal->uid,
            'depth' => 0,
        ]);
        $this->assertDatabaseHas('workspace_ancestry', [
            'ancestor_workspace_uid' => $shared->uid,
            'descendant_workspace_uid' => $shared->uid,
            'depth' => 0,
        ]);
        $this->assertDatabaseHas('workspace_ancestry', [
            'ancestor_workspace_uid' => $direct->uid,
            'descendant_workspace_uid' => $direct->uid,
            'depth' => 0,
        ]);
        $this->assertSame(3, DB::table('workspace_ancestry')->count());
    }

    public function test_personal_workspace_cannot_receive_a_parent_even_through_a_direct_database_write(): void
    {
        $user = User::query()->create([
            'name' => 'Personal Hierarchy Guard',
            'email' => 'personal-hierarchy-guard@example.test',
            'password' => Hash::make('password'),
        ]);

        $personal = app(CreatePersonalWorkspaceForUser::class)->handle($user);
        $shared = app(CreateSharedWorkspace::class)->handle($user, 'Shared Parent');

        try {
            DB::transaction(static function () use ($personal, $shared): void {
                Workspace::query()
                    ->whereKey($personal->uid)
                    ->update(['parent_workspace_uid' => $shared->uid]);
            });
            $this->fail('Expected the database to reject a parent on a personal workspace.');
        } catch (QueryException) {
            $this->assertDatabaseHas('workspaces', [
                'uid' => $personal->uid,
                'parent_workspace_uid' => null,
            ]);
        }
    }

    public function test_root_workspace_cannot_disable_parent_membership_inheritance_in_the_database(): void
    {
        $user = User::query()->create([
            'name' => 'Root Inheritance Guard',
            'email' => 'root-inheritance-guard@example.test',
            'password' => Hash::make('password'),
        ]);
        $root = app(CreateSharedWorkspace::class)->handle($user, 'Guarded Root');

        try {
            DB::transaction(static function () use ($root): void {
                Workspace::query()
                    ->whereKey($root->uid)
                    ->update(['inherits_parent_memberships' => false]);
            });
            $this->fail('Expected the database to reject disabled inheritance on a root workspace.');
        } catch (QueryException) {
            $this->assertDatabaseHas('workspaces', [
                'uid' => $root->uid,
                'parent_workspace_uid' => null,
                'inherits_parent_memberships' => true,
            ]);
        }
    }

    public function test_ancestry_rows_enforce_self_identity_and_three_level_depth(): void
    {
        $user = User::query()->create([
            'name' => 'Ancestry Constraint Owner',
            'email' => 'ancestry-constraint-owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $first = app(CreateSharedWorkspace::class)->handle($user, 'First Root');
        $second = app(CreateSharedWorkspace::class)->handle($user, 'Second Root');

        $invalidRows = [
            [
                'ancestor_workspace_uid' => $first->uid,
                'descendant_workspace_uid' => $second->uid,
                'depth' => 0,
            ],
            [
                'ancestor_workspace_uid' => $first->uid,
                'descendant_workspace_uid' => $first->uid,
                'depth' => 1,
            ],
            [
                'ancestor_workspace_uid' => $first->uid,
                'descendant_workspace_uid' => $second->uid,
                'depth' => 3,
            ],
        ];

        foreach ($invalidRows as $invalidRow) {
            try {
                DB::transaction(static function () use ($invalidRow): void {
                    DB::table('workspace_ancestry')->insert($invalidRow);
                });
                $this->fail('Expected the database to reject an invalid workspace ancestry row.');
            } catch (QueryException) {
                $this->assertDatabaseMissing('workspace_ancestry', $invalidRow);
            }
        }
    }

    public function test_admin_can_create_children_and_grandchildren_but_not_a_fourth_level(): void
    {
        $admin = User::query()->create([
            'name' => 'Hierarchy Creator',
            'email' => 'hierarchy-creator@example.test',
            'password' => Hash::make('password'),
        ]);
        $outsider = User::query()->create([
            'name' => 'Hierarchy Outsider',
            'email' => 'hierarchy-create-outsider@example.test',
            'password' => Hash::make('password'),
        ]);
        $create = app(CreateSharedWorkspace::class);
        $root = $create->handle($admin, 'Root');
        $child = $create->handle($admin, 'Child', $root->uid);
        $grandchild = $create->handle($admin, 'Grandchild', $child->uid);

        $this->assertSame($root->uid, $child->parent_workspace_uid);
        $this->assertSame($child->uid, $grandchild->parent_workspace_uid);
        $this->assertDatabaseHas('workspace_ancestry', [
            'ancestor_workspace_uid' => $root->uid,
            'descendant_workspace_uid' => $grandchild->uid,
            'depth' => 2,
        ]);
        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_uid' => $child->uid,
            'user_uid' => $admin->uid,
            'role' => WorkspaceRole::Admin->value,
        ]);

        try {
            $create->handle($admin, 'Fourth', $grandchild->uid);
            $this->fail('Expected fourth-level workspace creation to fail.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Workspace hierarchy is limited to three levels.', $exception->getMessage());
        }

        try {
            $create->handle($outsider, 'Unauthorized Child', $root->uid);
            $this->fail('Expected parent authority to be required.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Only workspace admins can create a child workspace.', $exception->getMessage());
        }
    }
}
