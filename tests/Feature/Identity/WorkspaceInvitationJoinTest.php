<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Application\Identity\CreateSharedWorkspace;
use App\Domain\Identity\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class WorkspaceInvitationJoinTest extends TestCase
{
    use RefreshDatabase;

    public function test_invited_person_without_an_account_can_register_and_join(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = $this->invite($admin, $workspace->uid, 'newbie@example.test', WorkspaceRole::Editor);

        // The token landing offers registration when the invited email has no account.
        $this->get("/join/{$invitation->token}")
            ->assertOk()
            ->assertSee('Finish setting up your account')
            ->assertSee('newbie@example.test');

        $this->post("/join/{$invitation->token}/register", [
            'name' => 'New Bie',
            'password' => 'a-strong-password-123',
            'password_confirmation' => 'a-strong-password-123',
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'newbie@example.test')->sole();
        // The account is created verified, bound to the invited email, and signed in.
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('newbie@example.test', $user->email);
        $this->assertAuthenticatedAs($user);

        $this->assertSame(1, WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $user->uid)
            ->whereNotNull('accepted_at')
            ->count());
        $this->assertNotNull($invitation->refresh()->accepted_at);
    }

    public function test_landing_sends_an_existing_account_to_sign_in_and_refuses_re_registration(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $existing = $this->createUser('Existing User', 'existing@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = $this->invite($admin, $workspace->uid, 'existing@example.test', WorkspaceRole::Reader);

        $this->get("/join/{$invitation->token}")
            ->assertOk()
            ->assertSee('Sign in to join');

        $this->post("/join/{$invitation->token}/register", [
            'name' => 'Existing User',
            'password' => 'a-strong-password-123',
            'password_confirmation' => 'a-strong-password-123',
        ])->assertRedirect(route('workspace-invitations.join', ['invitation' => $invitation->token]));

        $this->assertGuest();
        $this->assertSame(0, WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $existing->uid)
            ->count());
    }

    public function test_invalid_expired_or_revoked_token_is_rejected(): void
    {
        $this->get('/join/this-token-does-not-exist')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('invitation');

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = $this->invite($admin, $workspace->uid, 'newbie@example.test', WorkspaceRole::Reader);
        $invitation->forceFill(['revoked_at' => now()])->save();

        $this->get("/join/{$invitation->token}")
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('invitation');

        $this->post("/join/{$invitation->token}/register", [
            'name' => 'New Bie',
            'password' => 'a-strong-password-123',
            'password_confirmation' => 'a-strong-password-123',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, User::query()->where('email', 'newbie@example.test')->count());
    }

    public function test_public_registration_is_not_available_without_an_invitation(): void
    {
        // There is no general registration route; accounts come only from a valid
        // invitation token (or an operator command).
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.test',
            'password' => 'a-strong-password-123',
        ])->assertNotFound();

        $this->assertSame(0, User::query()->where('email', 'intruder@example.test')->count());
    }

    public function test_registration_requires_a_strong_confirmed_password(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = $this->invite($admin, $workspace->uid, 'newbie@example.test', WorkspaceRole::Reader);

        $this->from("/join/{$invitation->token}")
            ->post("/join/{$invitation->token}/register", [
                'name' => 'New Bie',
                'password' => 'too-short',
                'password_confirmation' => 'too-short',
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame(0, User::query()->where('email', 'newbie@example.test')->count());
        $this->assertGuest();
    }

    public function test_registration_rolls_the_account_back_when_the_invitation_is_revoked_mid_flow(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = $this->invite($admin, $workspace->uid, 'racer@example.test', WorkspaceRole::Editor);

        // Simulate a concurrent revoke that lands after registration passes its pending
        // pre-check but before the invitation is accepted: revoke the row the first time the
        // registration transaction locks it (the pre-lock revalidation still sees the row it
        // read a moment earlier, so acceptance is what discovers the revoke). The account is
        // created in that same transaction, so a failed acceptance must roll it back -- no
        // verified, logged-in orphan account may survive a revoked invitation.
        $revoked = false;
        DB::listen(function (QueryExecuted $query) use (&$revoked, $invitation): void {
            if ($revoked) {
                return;
            }

            $sql = strtolower($query->sql);
            if (!str_contains($sql, 'for update') || !str_contains($sql, 'workspace_invitations')) {
                return;
            }

            if (!in_array($invitation->uid, $query->bindings, true)) {
                return;
            }

            $revoked = true;
            DB::table('workspace_invitations')->where('uid', $invitation->uid)->update(['revoked_at' => now()]);
        });

        $this->post("/join/{$invitation->token}/register", [
            'name' => 'Racer',
            'password' => 'a-strong-password-123',
            'password_confirmation' => 'a-strong-password-123',
        ])->assertRedirect(route('login', absolute: false));

        $this->assertTrue($revoked, 'The mid-flow revoke should have fired on the registration lock.');
        $this->assertGuest();
        $this->assertSame(0, User::query()->where('email', 'racer@example.test')->count());
        // Only the admin's owner membership remains; no membership was created for the
        // rolled-back registrant.
        $this->assertSame(1, WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->count());
    }

    public function test_registration_is_refused_when_the_invitation_token_was_rotated_after_binding(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = $this->invite($admin, $workspace->uid, 'racer@example.test', WorkspaceRole::Editor);
        $presentedToken = $invitation->token;

        // Simulate a concurrent revoke-and-reinvite that rotates the invitation token to a
        // fresh value after this request route-bound the presented token but before
        // registration locks the row: rotate it the first time a query reads the row by the
        // presented token. The row stays pending, so only the token binding catches the stale
        // link -- registration must not complete against an invitation it no longer names.
        $rotated = false;
        DB::listen(function (QueryExecuted $query) use (&$rotated, $presentedToken, $invitation): void {
            if ($rotated) {
                return;
            }

            $sql = strtolower($query->sql);
            if (str_contains($sql, 'for update') || !str_contains($sql, 'workspace_invitations')) {
                return;
            }

            if (!in_array($presentedToken, $query->bindings, true)) {
                return;
            }

            $rotated = true;
            DB::table('workspace_invitations')
                ->where('uid', $invitation->uid)
                ->update(['token' => WorkspaceInvitation::freshToken()]);
        });

        $this->post("/join/{$presentedToken}/register", [
            'name' => 'Racer',
            'password' => 'a-strong-password-123',
            'password_confirmation' => 'a-strong-password-123',
        ])->assertRedirect(route('login', absolute: false));

        $this->assertTrue($rotated, 'The token should have been rotated on the binding read.');
        $this->assertGuest();
        $this->assertSame(0, User::query()->where('email', 'racer@example.test')->count());
        $this->assertSame(1, WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->count());
    }

    private function invite(User $actor, string $workspaceUid, string $email, WorkspaceRole $role): WorkspaceInvitation
    {
        $this->actingAs($actor)->post("/workspaces/{$workspaceUid}/invitations", [
            'email' => $email,
            'role' => $role->value,
        ]);

        // Reset the acting-as guard so the join flow is exercised as a guest.
        $this->app['auth']->forgetGuards();

        return WorkspaceInvitation::query()->where('invited_email', strtolower($email))->sole();
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
