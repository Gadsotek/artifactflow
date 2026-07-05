<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\WorkspaceRole;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class WorkspaceManagementHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_a_shared_workspace(): void
    {
        $user = $this->createUser('Workspace User', 'workspace@example.test');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('aria-label="Create workspace"', false)
            ->assertSee('data-open-editor-dialog="workspace-create-dialog"', false)
            ->assertSee('action="http://localhost:18080/workspaces"', false)
            ->assertSee('Create shared workspace');

        $this->actingAs($user)
            ->post('/workspaces', ['name' => 'Research Team'])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('current_workspace_uid');

        $workspace = Workspace::query()->where('name', 'Research Team')->sole();
        $membership = WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $user->uid)
            ->sole();

        $this->assertSame(WorkspaceRole::Admin, $membership->role);
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'workspace.shared.created')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'workspace.shared.created')->count());
    }

    public function test_library_offers_workspace_creation_and_returns_to_the_new_workspace(): void
    {
        $user = $this->createUser('Library Workspace User', 'library-workspace@example.test');

        $this->actingAs($user)
            ->get('/pages?workspace_uid=all')
            ->assertOk()
            ->assertSee('aria-label="Create workspace"', false)
            ->assertSee('data-open-editor-dialog="library-workspace-create-dialog"', false)
            ->assertSee('name="return_to"', false)
            ->assertSee('value="library"', false);

        $response = $this->actingAs($user)
            ->post('/workspaces', [
                'name' => 'Library Team',
                'return_to' => 'library',
            ]);

        $workspace = Workspace::query()->where('name', 'Library Team')->sole();
        $response->assertRedirect(route('pages.index', ['workspace_uid' => $workspace->uid]));
    }

    public function test_shared_workspace_creation_is_rate_limited(): void
    {
        config(['rate_limits.workspace_creates_per_minute' => 2]);

        $user = $this->createUser('Workspace Spammer', 'workspace-spammer@example.test');

        $this->actingAs($user)
            ->post('/workspaces', ['name' => 'Workspace One'])
            ->assertRedirect('/dashboard');
        $this->actingAs($user)
            ->post('/workspaces', ['name' => 'Workspace Two'])
            ->assertRedirect('/dashboard');
        $this->actingAs($user)
            ->post('/workspaces', ['name' => 'Workspace Three'])
            ->assertTooManyRequests();

        $this->assertSame(0, Workspace::query()->where('name', 'Workspace Three')->count());
    }

    public function test_shared_workspace_creation_requires_authentication_and_a_name(): void
    {
        $this->post('/workspaces', ['name' => 'Research Team'])
            ->assertRedirect('/login');

        $user = $this->createUser('Workspace User', 'workspace@example.test');

        $this->actingAs($user)
            ->post('/workspaces', ['name' => '  '])
            ->assertSessionHasErrors('name');
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
