<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreateCategory;
use App\Application\PageCatalog\CreateCategoryCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Models\AuditEntry;
use App\Models\Category;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_category_locks_the_workspace_row_so_duplicates_serialize(): void
    {
        $owner = $this->createUser('Owner User', 'category-lock-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');

        // Two concurrent creates of the same category would both pass the existence
        // check and race the (workspace_uid, slug) unique index, turning the loser into
        // a 23505 -> 500. Locking the workspace row FOR UPDATE serializes them; assert
        // that lock is taken so a duplicate blocks and fails cleanly instead.
        $lockedWorkspace = false;
        DB::listen(function (QueryExecuted $query) use (&$lockedWorkspace, $workspace): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'for update') && str_contains($sql, '"workspaces"')
                && in_array($workspace->uid, $query->bindings, true)) {
                $lockedWorkspace = true;
            }
        });

        app(CreateCategory::class)->handle($owner, new CreateCategoryCommand(
            workspaceUid: $workspace->uid,
            name: 'Release Runbooks',
        ));

        $this->assertTrue($lockedWorkspace, 'The workspace row must be locked FOR UPDATE so concurrent category creates serialize.');
        $this->assertSame(1, Category::query()->where('workspace_uid', $workspace->uid)->count());
    }

    public function test_workspace_editor_can_create_a_normalized_category_with_traceability(): void
    {
        $owner = $this->createUser('Owner User', 'owner@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);

        $category = app(CreateCategory::class)->handle($editor, new CreateCategoryCommand(
            workspaceUid: $workspace->uid,
            name: '  Release Runbooks  ',
        ));

        $this->assertTrue(Str::isUlid($category->uid));
        $this->assertSame($workspace->uid, $category->workspace_uid);
        $this->assertSame('Release Runbooks', $category->name);
        $this->assertSame('release-runbooks', $category->slug);
        $this->assertSame($editor->uid, $category->created_by_user_uid);

        $event = DomainEvent::query()
            ->where('event_type', 'category.created')
            ->sole();

        $this->assertSame('category', $event->aggregate_type);
        $this->assertSame($category->uid, $event->aggregate_uid);
        $this->assertSame($workspace->uid, $event->payload['workspace_uid']);
        $this->assertSame($editor->uid, $event->payload['created_by_user_uid']);
        $this->assertSame('release-runbooks', $event->payload['category_slug']);

        $audit = AuditEntry::query()
            ->where('action', 'category.created')
            ->sole();

        $this->assertSame($event->uid, $audit->event_uid);
        $this->assertSame($editor->uid, $audit->actor_user_uid);
        $this->assertSame('category', $audit->auditable_type);
        $this->assertSame($category->uid, $audit->auditable_uid);
        $this->assertSame($workspace->uid, $audit->metadata['workspace_uid']);
    }

    public function test_reader_and_outsider_cannot_create_workspace_categories(): void
    {
        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $outsider = $this->createUser('Outside User', 'outside@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);

        foreach ([$reader, $outsider] as $actor) {
            try {
                app(CreateCategory::class)->handle($actor, new CreateCategoryCommand(
                    workspaceUid: $workspace->uid,
                    name: 'Security',
                ));
                $this->fail('Expected category creation to be forbidden.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('You cannot create categories in this workspace.', $exception->getMessage());
            }
        }

        $this->assertSame(0, Category::query()->where('workspace_uid', $workspace->uid)->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'category.created')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'category.created')->count());
    }

    public function test_duplicate_or_unusable_category_names_are_rejected_without_extra_traceability(): void
    {
        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $handler = app(CreateCategory::class);

        $handler->handle($owner, new CreateCategoryCommand(
            workspaceUid: $workspace->uid,
            name: 'Run Books',
        ));

        foreach ([
            ['run-books', 'Category already exists in this workspace.'],
            ['🛡️', 'Category name must contain letters or numbers.'],
        ] as [$name, $message]) {
            try {
                $handler->handle($owner, new CreateCategoryCommand(
                    workspaceUid: $workspace->uid,
                    name: $name,
                ));
                $this->fail('Expected invalid category name to be rejected.');
            } catch (DomainRuleViolation $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }

        $this->assertSame(1, Category::query()->where('workspace_uid', $workspace->uid)->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'category.created')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'category.created')->count());
    }

    public function test_workspace_admin_can_create_a_category_from_the_dashboard(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Workspace categories')
            ->assertSee('aria-label="Create category"', false)
            ->assertSee('data-open-editor-dialog="category-create-dialog"', false)
            ->assertSee('Create category for Platform Team')
            ->assertSee("workspaces/{$workspace->uid}/categories", false)
            ->assertSee('Create category');

        $this->actingAs($admin)
            ->post("/workspaces/{$workspace->uid}/categories", [
                'name' => 'Architecture',
            ])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'Category created.')
            ->assertSessionHas('current_workspace_uid', $workspace->uid);

        $this->assertDatabaseHas('categories', [
            'workspace_uid' => $workspace->uid,
            'name' => 'Architecture',
            'slug' => 'architecture',
            'created_by_user_uid' => $admin->uid,
        ]);
    }

    public function test_category_http_boundary_validates_input_and_enforces_workspace_authorization(): void
    {
        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);

        $this->post("/workspaces/{$workspace->uid}/categories", ['name' => 'Security'])
            ->assertRedirect('/login');

        $this->actingAs($owner)
            ->post("/workspaces/{$workspace->uid}/categories", ['name' => '  '])
            ->assertSessionHasErrors('name');

        $this->actingAs($owner)
            ->post("/workspaces/{$workspace->uid}/categories", ['name' => str_repeat('a', 121)])
            ->assertSessionHasErrors('name');

        $this->actingAs($reader)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Workspace categories')
            ->assertDontSee("workspaces/{$workspace->uid}/categories", false)
            ->assertDontSee('aria-label="Create category"', false)
            ->assertDontSee('data-open-editor-dialog="category-create-dialog"', false)
            ->assertDontSee('Create category');

        $this->actingAs($reader)
            ->post("/workspaces/{$workspace->uid}/categories", ['name' => 'Security'])
            ->assertForbidden();

        $this->assertSame(0, Category::query()->where('workspace_uid', $workspace->uid)->count());
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function addMember(string $workspaceUid, User $user, WorkspaceRole $role): void
    {
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspaceUid,
            'user_uid' => $user->uid,
            'role' => $role,
            'accepted_at' => now(),
        ]);
    }
}
