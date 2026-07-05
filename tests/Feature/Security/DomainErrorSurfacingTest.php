<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Application\Identity\CreateSharedWorkspace;
use App\Domain\Identity\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

final class DomainErrorSurfacingTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_rule_violations_surface_as_field_validation_errors(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $admin->uid)
            ->sole();

        $this->actingAs($admin)
            ->from('/dashboard')
            ->put("/workspaces/{$workspace->uid}/memberships/{$membership->uid}", [
                'role' => WorkspaceRole::Editor->value,
            ])
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors([
                'role' => 'A shared workspace must retain at least one admin.',
            ]);

        $this->assertSame(WorkspaceRole::Admin, $membership->refresh()->role);
    }

    public function test_vendor_invalid_argument_exceptions_inside_handlers_are_not_surfaced_to_users(): void
    {
        config(['app.debug' => false]);

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $member->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        WorkspaceMembership::updating(static function (): void {
            throw new InvalidArgumentException('internal vendor detail');
        });

        $response = $this->actingAs($admin)
            ->from('/dashboard')
            ->put("/workspaces/{$workspace->uid}/memberships/{$membership->uid}", [
                'role' => WorkspaceRole::Editor->value,
            ]);

        $response->assertStatus(500);
        $response->assertSessionMissing('errors');
        $this->assertStringNotContainsString('internal vendor detail', (string) $response->getContent());

        $this->assertSame(WorkspaceRole::Reader, $membership->refresh()->role);
    }

    private function createUser(string $name, string $email): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        app(CreatePersonalWorkspaceForUser::class)->handle($user);

        return $user;
    }
}
