<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Application\Identity\CreateSharedWorkspace;
use App\Domain\Identity\WorkspaceRole;
use App\Mail\WorkspaceMembershipAddedMail;
use App\Models\AuditEntry;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class WorkspaceCollaboratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_internal_human_users_not_already_in_the_target_workspace(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $shared = app(CreateSharedWorkspace::class)->handle($admin, 'Alpha');
        $target = app(CreateSharedWorkspace::class)->handle($admin, 'Beta');

        $bob = $this->createUser('Bob Builder', 'bob@example.test');
        $carol = $this->createUser('Carol Danvers', 'carol@example.test');
        $dave = $this->createUser('Dave Stranger', 'dave@example.test');

        // Carol is already in the target. Bob and Dave are both registered human
        // users, so both are valid internal-directory results even though Dave
        // does not share another workspace with the admin.
        $this->addMember($shared->uid, $bob, WorkspaceRole::Editor);
        $this->addMember($shared->uid, $carol, WorkspaceRole::Editor);
        $this->addMember($target->uid, $carol, WorkspaceRole::Editor);

        $response = $this->actingAs($admin)
            ->getJson(route('workspace-collaborators.search', $target->uid) . '?q=example.test');

        // The already-member (Carol) and the actor themselves are excluded.
        $response->assertOk()
            ->assertJsonCount(2, 'results')
            ->assertJsonPath('results.0.email', 'bob@example.test')
            ->assertJsonPath('results.1.email', 'dave@example.test')
            ->assertJsonMissing(['email' => 'carol@example.test'])
            ->assertJsonMissing(['email' => 'admin@example.test']);
    }

    public function test_search_is_gated_by_invite_permission(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $target = app(CreateSharedWorkspace::class)->handle($admin, 'Beta');

        $reader = $this->createUser('Reader User', 'reader@example.test');
        $this->addMember($target->uid, $reader, WorkspaceRole::Reader);

        $this->actingAs($reader)
            ->getJson(route('workspace-collaborators.search', $target->uid) . '?q=example.test')
            ->assertForbidden();
    }

    public function test_adding_an_existing_collaborator_grants_immediate_access_and_notifies_them(): void
    {
        Mail::fake();

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $shared = app(CreateSharedWorkspace::class)->handle($admin, 'Alpha');
        $target = app(CreateSharedWorkspace::class)->handle($admin, 'Beta');

        $bob = $this->createUser('Bob Builder', 'bob@example.test');
        $this->addMember($shared->uid, $bob, WorkspaceRole::Editor);

        $this->actingAs($admin)
            ->post(route('workspace-collaborators.store', $target->uid), [
                'user_uid' => $bob->uid,
                'role' => WorkspaceRole::Editor->value,
            ])
            ->assertRedirect(route('dashboard'));

        // Immediate, accepted membership — no invitation to accept.
        $membership = WorkspaceMembership::query()
            ->where('workspace_uid', $target->uid)
            ->where('user_uid', $bob->uid)
            ->sole();
        $this->assertNotNull($membership->accepted_at);
        $this->assertSame(WorkspaceRole::Editor, $membership->role);
        $this->assertSame(0, WorkspaceInvitation::query()->where('workspace_uid', $target->uid)->count());

        // An informational (not accept/reject) email goes to the added person.
        Mail::assertQueued(
            WorkspaceMembershipAddedMail::class,
            static fn (WorkspaceMembershipAddedMail $mail): bool => $mail->hasTo('bob@example.test'),
        );

        $this->assertTrue(
            AuditEntry::query()
                ->where('action', 'workspace.membership.added')
                ->where('auditable_uid', $membership->uid)
                ->exists(),
        );
    }

    public function test_can_add_any_registered_human_coworker(): void
    {
        Mail::fake();

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $target = app(CreateSharedWorkspace::class)->handle($admin, 'Beta');
        $coworker = $this->createUser('Dave Coworker', 'dave@example.test');

        $this->actingAs($admin)
            ->post(route('workspace-collaborators.store', $target->uid), [
                'user_uid' => $coworker->uid,
                'role' => WorkspaceRole::Editor->value,
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, WorkspaceMembership::query()
            ->where('workspace_uid', $target->uid)
            ->where('user_uid', $coworker->uid)
            ->count());
        Mail::assertQueued(
            WorkspaceMembershipAddedMail::class,
            static fn (WorkspaceMembershipAddedMail $mail): bool => $mail->hasTo('dave@example.test'),
        );
    }

    public function test_cannot_add_a_service_account_as_a_workspace_coworker(): void
    {
        Mail::fake();

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $target = app(CreateSharedWorkspace::class)->handle($admin, 'Beta');
        $serviceAccount = $this->createUser('Automation', 'automation@example.test');
        $serviceAccount->forceFill(['is_service_account' => true])->save();

        $this->actingAs($admin)
            ->from(route('dashboard'))
            ->post(route('workspace-collaborators.store', $target->uid), [
                'user_uid' => $serviceAccount->uid,
                'role' => WorkspaceRole::Editor->value,
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('collaborator');

        $this->assertSame(0, WorkspaceMembership::query()
            ->where('workspace_uid', $target->uid)
            ->where('user_uid', $serviceAccount->uid)
            ->count());
        Mail::assertNothingQueued();
    }

    public function test_cannot_add_a_person_who_is_already_a_member(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $shared = app(CreateSharedWorkspace::class)->handle($admin, 'Alpha');
        $target = app(CreateSharedWorkspace::class)->handle($admin, 'Beta');

        $carol = $this->createUser('Carol Danvers', 'carol@example.test');
        $this->addMember($shared->uid, $carol, WorkspaceRole::Editor);
        $this->addMember($target->uid, $carol, WorkspaceRole::Reader);

        $this->actingAs($admin)
            ->from(route('dashboard'))
            ->post(route('workspace-collaborators.store', $target->uid), [
                'user_uid' => $carol->uid,
                'role' => WorkspaceRole::Editor->value,
            ])
            ->assertSessionHasErrors('collaborator');

        // The existing (reader) membership is untouched, not upgraded.
        $membership = WorkspaceMembership::query()
            ->where('workspace_uid', $target->uid)
            ->where('user_uid', $carol->uid)
            ->sole();
        $this->assertSame(WorkspaceRole::Reader, $membership->role);
    }

    public function test_adding_a_collaborator_is_gated_by_invite_permission(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $shared = app(CreateSharedWorkspace::class)->handle($admin, 'Alpha');
        $target = app(CreateSharedWorkspace::class)->handle($admin, 'Beta');

        $reader = $this->createUser('Reader User', 'reader@example.test');
        $bob = $this->createUser('Bob Builder', 'bob@example.test');
        $this->addMember($target->uid, $reader, WorkspaceRole::Reader);
        $this->addMember($shared->uid, $reader, WorkspaceRole::Editor);
        $this->addMember($shared->uid, $bob, WorkspaceRole::Editor);

        $this->actingAs($reader)
            ->post(route('workspace-collaborators.store', $target->uid), [
                'user_uid' => $bob->uid,
                'role' => WorkspaceRole::Editor->value,
            ])
            ->assertForbidden();

        $this->assertSame(0, WorkspaceMembership::query()
            ->where('workspace_uid', $target->uid)
            ->where('user_uid', $bob->uid)
            ->count());
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

    private function createUser(string $name, string $email): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('correct horse battery staple'),
        ]);

        app(CreatePersonalWorkspaceForUser::class)->handle($user);

        return $user;
    }
}
