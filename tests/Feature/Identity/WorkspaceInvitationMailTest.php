<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\InviteUserToWorkspace;
use App\Application\Identity\InviteUserToWorkspaceCommand;
use App\Application\Identity\RevokeWorkspaceInvitation;
use App\Application\Identity\RevokeWorkspaceInvitationCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Mail\WorkspaceInvitationMail;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

final class WorkspaceInvitationMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_inviting_a_new_user_queues_a_resend_backed_mailable_to_the_invited_address(): void
    {
        Mail::fake();

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'New.Member@Example.TEST',
                role: WorkspaceRole::Editor,
            ),
        );

        Mail::assertQueued(WorkspaceInvitationMail::class, function (WorkspaceInvitationMail $mail) use ($invitation): bool {
            return $mail->hasTo('new.member@example.test')
                && $mail->invitedEmail === 'new.member@example.test'
                && $mail->acceptUrl === $this->appAcceptUrl($invitation)
                && $mail->roleLabel === 'Editor';
        });
    }

    public function test_invitation_queue_payload_is_encrypted_so_the_bearer_token_is_not_persisted(): void
    {
        config(['queue.default' => 'database']);

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'secret-link@example.test',
                role: WorkspaceRole::Admin,
            ),
        );
        $plainToken = $invitation->plainToken;

        if (!is_string($plainToken) || $plainToken === '') {
            $this->fail('A newly minted invitation must expose its plaintext token only to the caller.');
        }

        $job = DB::table('jobs')->select('payload')->first();

        if (!is_object($job) || !property_exists($job, 'payload') || !is_string($job->payload)) {
            $this->fail('The database queue must contain the invitation delivery job.');
        }

        $this->assertStringNotContainsString($plainToken, $job->payload);
        $this->assertStringNotContainsString($this->appAcceptUrl($invitation), $job->payload);

        $interfaces = class_implements(WorkspaceInvitationMail::class);
        $this->assertIsArray($interfaces);
        $this->assertContains(ShouldQueue::class, $interfaces);
        $this->assertContains(ShouldBeEncrypted::class, $interfaces);
    }

    public function test_queue_insertion_failure_rolls_back_the_invitation_for_a_safe_retry(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        Mail::shouldReceive('to')
            ->once()
            ->with('retry@example.test')
            ->andReturnSelf();
        Mail::shouldReceive('queue')
            ->once()
            ->andThrow(new RuntimeException('Database queue is unavailable.'));

        try {
            app(InviteUserToWorkspace::class)->handle(
                actor: $admin,
                command: new InviteUserToWorkspaceCommand(
                    workspaceUid: $workspace->uid,
                    email: 'retry@example.test',
                    role: WorkspaceRole::Reader,
                ),
            );
            $this->fail('Expected queue insertion to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Database queue is unavailable.', $exception->getMessage());
        }

        $this->assertSame(0, WorkspaceInvitation::query()->where('invited_email', 'retry@example.test')->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.invitation.created')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'workspace.invitation.created')->count());
    }

    public function test_the_accept_link_targets_the_app_origin_and_resolves_the_invitation(): void
    {
        Mail::fake();
        config([
            'app.url' => 'https://app.example.internal',
            'app.artifact_url' => 'https://artifacts.example.internal',
        ]);

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('New Member', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: $invitee->email,
                role: WorkspaceRole::Reader,
            ),
        );

        Mail::assertQueued(WorkspaceInvitationMail::class, function (WorkspaceInvitationMail $mail) use ($invitation): bool {
            return $mail->acceptUrl === $this->appAcceptUrl($invitation)
                && str_starts_with($mail->acceptUrl, 'https://app.example.internal/')
                && !str_contains($mail->acceptUrl, 'artifacts.example.internal');
        });

        $this->actingAs($invitee)
            ->post(route('workspace-invitations.accept', $invitation))
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHas('status', 'Workspace invitation accepted.');
    }

    public function test_revoked_then_reinvited_address_resends_and_link_is_usable(): void
    {
        Mail::fake();

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('New Member', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: $invitee->email,
                role: WorkspaceRole::Reader,
            ),
        );

        app(RevokeWorkspaceInvitation::class)->handle(
            actor: $admin,
            command: new RevokeWorkspaceInvitationCommand(
                workspaceUid: $workspace->uid,
                invitationUid: $invitation->uid,
            ),
        );

        $reactivatedInvitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: $invitee->email,
                role: WorkspaceRole::Editor,
            ),
        );

        Mail::assertQueued(WorkspaceInvitationMail::class, 2);

        $this->actingAs($invitee)
            ->post(route('workspace-invitations.accept', $reactivatedInvitation))
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHas('status', 'Workspace invitation accepted.');
    }

    public function test_expired_then_reinvited_address_resends(): void
    {
        Mail::fake();

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'expired@example.test',
                role: WorkspaceRole::Reader,
            ),
        );
        $invitation->forceFill(['expires_at' => now()->subMinute()])->save();

        app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'expired@example.test',
                role: WorkspaceRole::Reader,
            ),
        );

        Mail::assertQueued(WorkspaceInvitationMail::class, 2);
    }

    public function test_role_change_reinvite_resends(): void
    {
        Mail::fake();

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'member@example.test',
                role: WorkspaceRole::Reader,
            ),
        );
        app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'member@example.test',
                role: WorkspaceRole::Admin,
            ),
        );

        Mail::assertQueued(WorkspaceInvitationMail::class, 2);
    }

    public function test_idempotent_reinvite_same_active_role_does_not_resend(): void
    {
        Mail::fake();

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'member@example.test',
                role: WorkspaceRole::Reader,
            ),
        );
        app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'member@example.test',
                role: WorkspaceRole::Reader,
            ),
        );

        Mail::assertQueued(WorkspaceInvitationMail::class, 1);
    }

    public function test_mail_body_escapes_invitation_context(): void
    {
        Mail::fake();

        $admin = $this->createUser('Admin <script>alert(1)</script>', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Team <script>alert(2)</script>');

        app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'member@example.test',
                role: WorkspaceRole::Reader,
            ),
        );

        Mail::assertQueued(WorkspaceInvitationMail::class, function (WorkspaceInvitationMail $mail): bool {
            $rendered = $mail->render();

            $this->assertStringNotContainsString('<script>alert(1)</script>', $rendered);
            $this->assertStringNotContainsString('<script>alert(2)</script>', $rendered);
            $this->assertStringContainsString('&lt;script&gt;', $rendered);

            return true;
        });
    }

    public function test_mail_body_neutralizes_markdown_link_injection_in_names(): void
    {
        Mail::fake();

        $admin = $this->createUser('Eve [click here](https://phish.example/inviter)', 'eve@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Ops [urgent](https://phish.example/workspace)');

        app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'member@example.test',
                role: WorkspaceRole::Reader,
            ),
        );

        Mail::assertQueued(WorkspaceInvitationMail::class, function (WorkspaceInvitationMail $mail): bool {
            $rendered = $mail->render();

            // The injected Markdown links must not become real anchors pointing at
            // the phishing destinations; the bracket syntax renders as literal text
            // (the URL may still appear as plain text, just never as a link target).
            $this->assertStringNotContainsString('href="https://phish.example/inviter"', $rendered);
            $this->assertStringNotContainsString('href="https://phish.example/workspace"', $rendered);
            $this->assertStringContainsString('[click here]', $rendered);
            $this->assertStringContainsString('[urgent]', $rendered);

            return true;
        });
    }

    public function test_invitation_store_route_has_a_dedicated_rate_limiter(): void
    {
        Mail::fake();
        config(['rate_limits.invitations_per_minute' => 1]);

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        $this->actingAs($admin)
            ->post(route('workspace-invitations.store', $workspace), [
                'email' => 'first@example.test',
                'role' => WorkspaceRole::Reader->value,
            ])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->actingAs($admin)
            ->post(route('workspace-invitations.store', $workspace), [
                'email' => 'second@example.test',
                'role' => WorkspaceRole::Reader->value,
            ])
            ->assertStatus(429);

        Mail::assertQueued(WorkspaceInvitationMail::class, 1);
    }

    public function test_failed_invitation_attempt_does_not_queue_mail(): void
    {
        Mail::fake();

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = Workspace::query()
            ->where('personal_owner_uid', $owner->uid)
            ->sole();

        try {
            app(InviteUserToWorkspace::class)->handle(
                actor: $owner,
                command: new InviteUserToWorkspaceCommand(
                    workspaceUid: $workspace->uid,
                    email: 'friend@example.test',
                    role: WorkspaceRole::Reader,
                ),
            );
            $this->fail('Expected a personal workspace invitation to be rejected.');
        } catch (\App\Domain\DomainRuleViolation) {
        }

        Mail::assertNothingQueued();
    }

    public function test_mail_configuration_defaults_to_log(): void
    {
        $mailConfig = $this->readProjectFile('config/mail.php');
        $servicesConfig = $this->readProjectFile('config/services.php');
        $envExample = $this->readProjectFile('.env.example');
        $productionEnvExample = $this->readProjectFile('.env.production.example');

        // Default transport is the local log driver so a fresh self-hosted
        // install boots without requiring a third-party mail account; operators
        // opt into a real transport (smtp/resend) explicitly.
        $this->assertStringContainsString("'default' => env('MAIL_MAILER', 'log')", $mailConfig);
        $this->assertStringContainsString('MAIL_MAILER=log', $envExample);
        $this->assertStringContainsString('MAIL_MAILER=log', $productionEnvExample);

        // The resend transport stays wired so switching to it is a one-liner.
        $this->assertStringContainsString("'resend' => [", $mailConfig);
        $this->assertStringContainsString("'transport' => 'resend'", $mailConfig);
        $this->assertStringContainsString("'key' => env('RESEND_KEY')", $servicesConfig);
        $this->assertStringContainsString('RESEND_KEY=replace-with-resend-api-key', $productionEnvExample);
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

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        return $contents;
    }

    private function appAcceptUrl(WorkspaceInvitation $invitation): string
    {
        $appUrl = config('app.url');
        $this->assertIsString($appUrl);

        return rtrim($appUrl, '/') . route('workspace-invitations.join', ['invitation' => $invitation->plainToken], false);
    }
}
