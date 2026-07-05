<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Application\Identity\CreateSharedWorkspace;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

final class WorkspaceProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_provisions_a_personal_workspace_for_a_saved_user(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'password' => Hash::make('password'),
        ]);

        app(CreatePersonalWorkspaceForUser::class)->handle($user);

        $workspace = Workspace::query()
            ->where('personal_owner_uid', $user->uid)
            ->sole();

        $this->assertSame('Ada Lovelace', $workspace->name);
        $this->assertSame(WorkspaceType::Personal, $workspace->type);

        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_uid' => $workspace->uid,
            'user_uid' => $user->uid,
            'role' => WorkspaceRole::Admin->value,
        ]);

        $event = DomainEvent::query()
            ->where('event_type', 'workspace.personal.created')
            ->sole();

        $this->assertSame('workspace', $event->aggregate_type);
        $this->assertSame($workspace->uid, $event->aggregate_uid);
        $this->assertSame($workspace->uid, $event->payload['workspace_uid']);
        $this->assertSame($user->uid, $event->payload['user_uid']);

        $auditEntry = AuditEntry::query()
            ->where('action', 'workspace.personal.created')
            ->sole();

        $this->assertSame($event->uid, $auditEntry->event_uid);
        $this->assertSame($user->uid, $auditEntry->actor_user_uid);
        $this->assertSame('workspace', $auditEntry->auditable_type);
        $this->assertSame($workspace->uid, $auditEntry->auditable_uid);
        $this->assertSame('Personal workspace created.', $auditEntry->summary);
        $this->assertSame('personal', $auditEntry->metadata['workspace_type']);
    }

    public function test_personal_workspace_provisioning_is_idempotent(): void
    {
        $user = User::query()->create([
            'name' => 'Grace Hopper',
            'email' => 'grace@example.test',
            'password' => Hash::make('password'),
        ]);

        app(CreatePersonalWorkspaceForUser::class)->handle($user);
        app(CreatePersonalWorkspaceForUser::class)->handle($user);

        $this->assertSame(1, Workspace::query()->where('personal_owner_uid', $user->uid)->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'workspace.personal.created')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'workspace.personal.created')->count());
    }

    public function test_personal_workspace_provisioning_repairs_a_missing_admin_membership_without_recording_a_new_event(): void
    {
        $user = User::query()->create([
            'name' => 'Margaret Hamilton',
            'email' => 'margaret@example.test',
            'password' => Hash::make('password'),
        ]);
        app(CreatePersonalWorkspaceForUser::class)->handle($user);

        $workspace = Workspace::query()
            ->where('personal_owner_uid', $user->uid)
            ->sole();

        WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $user->uid)
            ->delete();

        app(CreatePersonalWorkspaceForUser::class)->handle($user);

        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_uid' => $workspace->uid,
            'user_uid' => $user->uid,
            'role' => WorkspaceRole::Admin->value,
        ]);

        $this->assertSame(1, DomainEvent::query()->where('event_type', 'workspace.personal.created')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'workspace.personal.created')->count());
    }

    public function test_it_rejects_personal_workspace_provisioning_for_unsaved_users(): void
    {
        $user = new User([
            'name' => 'Unsaved User',
            'email' => 'unsaved-personal@example.test',
            'password' => Hash::make('password'),
        ]);

        try {
            app(CreatePersonalWorkspaceForUser::class)->handle($user);
            $this->fail('Expected an unsaved user to be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('Cannot create a personal workspace for an unsaved user.', $exception->getMessage());
        }

        $this->assertSame(0, Workspace::query()->count());
        $this->assertSame(0, DomainEvent::query()->count());
        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_it_creates_a_shared_workspace_with_the_actor_as_admin(): void
    {
        $actor = User::query()->create([
            'name' => 'Katherine Johnson',
            'email' => 'katherine@example.test',
            'password' => Hash::make('password'),
        ]);

        $workspace = app(CreateSharedWorkspace::class)->handle($actor, 'Mission Control');

        $this->assertSame('Mission Control', $workspace->name);
        $this->assertSame(WorkspaceType::Shared, $workspace->type);
        $this->assertNull($workspace->personal_owner_uid);

        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_uid' => $workspace->uid,
            'user_uid' => $actor->uid,
            'role' => WorkspaceRole::Admin->value,
        ]);

        $event = DomainEvent::query()
            ->where('event_type', 'workspace.shared.created')
            ->sole();

        $this->assertSame($workspace->uid, $event->aggregate_uid);
        $this->assertSame($workspace->uid, $event->payload['workspace_uid']);
        $this->assertSame($actor->uid, $event->payload['created_by_user_uid']);

        $auditEntry = AuditEntry::query()
            ->where('action', 'workspace.shared.created')
            ->sole();

        $this->assertSame($event->uid, $auditEntry->event_uid);
        $this->assertSame($actor->uid, $auditEntry->actor_user_uid);
        $this->assertSame('workspace', $auditEntry->auditable_type);
        $this->assertSame($workspace->uid, $auditEntry->auditable_uid);
        $this->assertSame('Shared workspace created.', $auditEntry->summary);
        $this->assertSame('shared', $auditEntry->metadata['workspace_type']);
    }

    public function test_it_trims_shared_workspace_names(): void
    {
        $actor = User::query()->create([
            'name' => 'Mary Jackson',
            'email' => 'mary@example.test',
            'password' => Hash::make('password'),
        ]);

        $workspace = app(CreateSharedWorkspace::class)->handle($actor, '  Wind Tunnel Team  ');

        $this->assertSame('Wind Tunnel Team', $workspace->name);
    }

    public function test_it_rejects_blank_shared_workspace_names(): void
    {
        $actor = User::query()->create([
            'name' => 'Dorothy Vaughan',
            'email' => 'dorothy@example.test',
            'password' => Hash::make('password'),
        ]);

        try {
            app(CreateSharedWorkspace::class)->handle($actor, '   ');
            $this->fail('Expected a blank shared workspace name to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Workspace name must not be blank.', $exception->getMessage());
        }

        $this->assertSame(0, Workspace::query()->where('type', WorkspaceType::Shared)->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.shared.created')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'workspace.shared.created')->count());
    }

    public function test_it_rejects_shared_workspace_creation_for_unsaved_actors(): void
    {
        $actor = new User([
            'name' => 'Unsaved Actor',
            'email' => 'unsaved-shared@example.test',
            'password' => Hash::make('password'),
        ]);

        try {
            app(CreateSharedWorkspace::class)->handle($actor, 'Research');
            $this->fail('Expected an unsaved actor to be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('Cannot create a shared workspace for an unsaved user.', $exception->getMessage());
        }

        $this->assertSame(0, Workspace::query()->count());
        $this->assertSame(0, DomainEvent::query()->count());
        $this->assertSame(0, AuditEntry::query()->count());
    }
}
