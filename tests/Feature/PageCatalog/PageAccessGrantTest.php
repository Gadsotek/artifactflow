<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\PageAccessGrantTargetUnavailable;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageType;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\PageAccessGrant;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageAccessGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_granting_access_locks_the_page_row_so_duplicate_grants_serialize(): void
    {
        Storage::fake('artifacts');

        $owner = app(CreateUser::class)->handle('Owner User', 'grant-lock-owner@example.test', 'correct horse battery staple');
        $reader = app(CreateUser::class)->handle('Reader User', 'grant-lock-reader@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Grant Lock Page',
            description: null,
            content: '# Grant Lock Page',
        ));

        // Two concurrent identical grants would both pass the existence check and race
        // the (page_uid, subject_type, subject_uid) unique index, turning the loser into
        // a 23505 -> 500. Locking the page row FOR UPDATE serializes them; assert that
        // lock is taken so a duplicate blocks and re-reads instead of colliding.
        $lockedPage = false;
        DB::listen(function (QueryExecuted $query) use (&$lockedPage, $page): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'for update') && str_contains($sql, '"pages"')
                && in_array($page->uid, $query->bindings, true)) {
                $lockedPage = true;
            }
        });

        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $reader->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->assertTrue($lockedPage, 'The page row must be locked FOR UPDATE so concurrent grants serialize.');
        $this->assertSame(1, PageAccessGrant::query()->where('page_uid', $page->uid)->count());
    }

    public function test_page_owner_can_grant_user_access_with_traceability(): void
    {
        Storage::fake('artifacts');

        $owner = app(CreateUser::class)->handle('Owner User', 'owner@example.test', 'correct horse battery staple');
        $reader = app(CreateUser::class)->handle('Reader User', 'reader@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Access Page',
            description: null,
            content: '# Access Page',
        ));

        $grant = app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $reader->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->assertSame($page->uid, $grant->page_uid);
        $this->assertSame(PageAccessSubjectType::User, $grant->subject_type);
        $this->assertSame($reader->uid, $grant->subject_uid);
        $this->assertSame(WorkspaceRole::Reader, $grant->role);
        $this->assertSame($owner->uid, $grant->granted_by_user_uid);

        $event = DomainEvent::query()
            ->where('event_type', 'page.access_grant.created')
            ->sole();

        $this->assertSame('page', $event->aggregate_type);
        $this->assertSame($page->uid, $event->aggregate_uid);
        $this->assertSame($grant->uid, $event->payload['page_access_grant_uid']);
        $this->assertSame($reader->uid, $event->payload['subject_uid']);

        $auditEntry = AuditEntry::query()
            ->where('action', 'page.access_grant.created')
            ->sole();

        $this->assertSame($event->uid, $auditEntry->event_uid);
        $this->assertSame($owner->uid, $auditEntry->actor_user_uid);
        $this->assertSame('page_access_grant', $auditEntry->auditable_type);
        $this->assertSame($grant->uid, $auditEntry->auditable_uid);
    }

    public function test_repeating_the_same_grant_is_idempotent(): void
    {
        Storage::fake('artifacts');

        $owner = app(CreateUser::class)->handle('Owner User', 'owner@example.test', 'correct horse battery staple');
        $reader = app(CreateUser::class)->handle('Reader User', 'reader@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Access Page',
            description: null,
            content: '# Access Page',
        ));
        $command = new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $reader->uid,
            role: WorkspaceRole::Reader,
        );

        $firstGrant = app(GrantPageAccess::class)->handle($owner, $command);
        $secondGrant = app(GrantPageAccess::class)->handle($owner, $command);

        $this->assertSame($firstGrant->uid, $secondGrant->uid);
        $this->assertSame(1, PageAccessGrant::query()->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.access_grant.created')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'page.access_grant.created')->count());
    }

    public function test_existing_grant_role_can_be_updated_with_traceability(): void
    {
        Storage::fake('artifacts');

        $owner = app(CreateUser::class)->handle('Owner User', 'owner@example.test', 'correct horse battery staple');
        $reader = app(CreateUser::class)->handle('Reader User', 'reader@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Access Page',
            description: null,
            content: '# Access Page',
        ));

        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $reader->uid,
            role: WorkspaceRole::Reader,
        ));
        $updatedGrant = app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $reader->uid,
            role: WorkspaceRole::Editor,
        ));

        $this->assertSame(WorkspaceRole::Editor, $updatedGrant->role);
        $this->assertSame(1, PageAccessGrant::query()->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.access_grant.updated')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'page.access_grant.updated')->count());
    }

    public function test_workspace_admin_can_grant_access_but_reader_cannot(): void
    {
        Storage::fake('artifacts');

        $owner = app(CreateUser::class)->handle('Owner User', 'owner@example.test', 'correct horse battery staple');
        $admin = app(CreateUser::class)->handle('Admin User', 'admin@example.test', 'correct horse battery staple');
        $reader = app(CreateUser::class)->handle('Reader User', 'reader@example.test', 'correct horse battery staple');
        $target = app(CreateUser::class)->handle('Target User', 'target@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Access Page',
            description: null,
            content: '# Access Page',
        ));

        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $admin->uid,
            'role' => WorkspaceRole::Admin,
            'accepted_at' => now(),
        ]);
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        // Admin page grants require the target to belong to the page workspace.
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $target->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        $grant = app(GrantPageAccess::class)->handle($admin, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $target->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->assertSame($admin->uid, $grant->granted_by_user_uid);

        $this->expectException(AuthorizationException::class);

        app(GrantPageAccess::class)->handle($reader, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $target->uid,
            role: WorkspaceRole::Editor,
        ));
    }

    public function test_editor_owner_cannot_grant_admin_role_to_self_or_others(): void
    {
        Storage::fake('artifacts');

        $workspaceAdmin = app(CreateUser::class)->handle('Workspace Admin', 'workspace-admin@example.test', 'correct horse battery staple');
        $owner = app(CreateUser::class)->handle('Owner User', 'owner@example.test', 'correct horse battery staple');
        $target = app(CreateUser::class)->handle('Target User', 'target@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($workspaceAdmin, 'Platform Team');
        $workspace->forceFill(['allow_editor_page_sharing' => true])->save();
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $owner->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $target->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Delegated Sharing Page',
            description: null,
            content: '# Delegated Sharing',
        ));

        foreach ([$owner, $target] as $subject) {
            try {
                app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
                    pageUid: $page->uid,
                    subjectType: PageAccessSubjectType::User,
                    subjectUid: $subject->uid,
                    role: WorkspaceRole::Admin,
                ));
                $this->fail('Expected workspace editor Admin grants to be rejected.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('Editors cannot grant page Admin access.', $exception->getMessage());
            }
        }

        $this->assertDatabaseMissing('page_access_grants', [
            'page_uid' => $page->uid,
            'subject_uid' => $owner->uid,
            'role' => WorkspaceRole::Admin->value,
        ]);
        $this->assertFalse(app(\App\Application\PageCatalog\PageAccess::class)->canHardDelete($owner, $page->refresh()));

        $grant = app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $target->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->assertSame(WorkspaceRole::Reader, $grant->role);
    }

    public function test_grant_subjects_must_exist(): void
    {
        Storage::fake('artifacts');

        $owner = app(CreateUser::class)->handle('Owner User', 'owner@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Access Page',
            description: null,
            content: '# Access Page',
        ));

        try {
            app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
                pageUid: $page->uid,
                subjectType: PageAccessSubjectType::User,
                subjectUid: '01K00000000000000000000000',
                role: WorkspaceRole::Reader,
            ));
            $this->fail('Expected missing user grant subject to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('User access grant subject does not exist.', $exception->getMessage());
        }

        try {
            app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
                pageUid: $page->uid,
                subjectType: PageAccessSubjectType::Workspace,
                subjectUid: '01K00000000000000000000000',
                role: WorkspaceRole::Reader,
            ));
            $this->fail('Expected missing workspace grant subject to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Workspace access grant subject does not exist.', $exception->getMessage());
        }
    }

    public function test_workspace_grant_target_must_be_a_workspace_the_actor_belongs_to_even_for_system_admin(): void
    {
        Storage::fake('artifacts');

        $owner = app(CreateUser::class)->handle('Owner User', 'owner@example.test', 'correct horse battery staple');
        $owner->forceFill(['is_system_admin' => true])->save();
        $otherAdmin = app(CreateUser::class)->handle(
            'Other Admin',
            'other-admin@example.test',
            'correct horse battery staple',
        );
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($otherAdmin, 'Operations Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Workspace Target Page',
            description: null,
            content: '# Workspace Target Page',
        ));

        try {
            app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
                pageUid: $page->uid,
                subjectType: PageAccessSubjectType::Workspace,
                subjectUid: $sourceWorkspace->uid,
                role: WorkspaceRole::Reader,
            ));
            $this->fail('Expected self-workspace grant target to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame(
                'Use workspace inheritance instead of granting the page workspace to itself.',
                $exception->getMessage(),
            );
        }

        try {
            app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
                pageUid: $page->uid,
                subjectType: PageAccessSubjectType::Workspace,
                subjectUid: $targetWorkspace->uid,
                role: WorkspaceRole::Reader,
            ));
            $this->fail('Expected an unrelated workspace grant target to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame(
                'Workspace access grant target must be a workspace you belong to.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, PageAccessGrant::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.access_grant.created')->count());

        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $targetWorkspace->uid,
            'user_uid' => $owner->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        $grant = app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::Workspace,
            subjectUid: $targetWorkspace->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->assertSame($targetWorkspace->uid, $grant->subject_uid);
    }

    public function test_workspace_grants_are_reader_only_and_cannot_upgrade_target_workspace_readers(): void
    {
        Storage::fake('artifacts');

        $sourceAdmin = app(CreateUser::class)->handle(
            'Source Admin',
            'source-admin@example.test',
            'correct horse battery staple',
        );
        $targetAdmin = app(CreateUser::class)->handle(
            'Target Admin',
            'target-admin@example.test',
            'correct horse battery staple',
        );
        $targetReader = app(CreateUser::class)->handle(
            'Target Reader',
            'target-reader@example.test',
            'correct horse battery staple',
        );
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($sourceAdmin, 'Source Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($targetAdmin, 'Target Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $targetWorkspace->uid,
            'user_uid' => $sourceAdmin->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $targetWorkspace->uid,
            'user_uid' => $targetReader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($sourceAdmin, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Workspace Grant Escalation',
            description: null,
            content: '# Workspace Grant Escalation',
        ));

        foreach ([WorkspaceRole::Editor, WorkspaceRole::Admin] as $role) {
            try {
                app(GrantPageAccess::class)->handle($sourceAdmin, new GrantPageAccessCommand(
                    pageUid: $page->uid,
                    subjectType: PageAccessSubjectType::Workspace,
                    subjectUid: $targetWorkspace->uid,
                    role: $role,
                ));
                $this->fail('Expected non-Reader workspace grants to be rejected.');
            } catch (DomainRuleViolation $exception) {
                $this->assertSame(
                    'Workspace access grants are limited to Reader access.',
                    $exception->getMessage(),
                );
            }
        }

        $grant = app(GrantPageAccess::class)->handle($sourceAdmin, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::Workspace,
            subjectUid: $targetWorkspace->uid,
            role: WorkspaceRole::Reader,
        ));

        $access = app(\App\Application\PageCatalog\PageAccess::class);
        $this->assertSame(WorkspaceRole::Reader, $grant->role);
        $this->assertTrue($access->canView($targetReader, $page->refresh()));
        $this->assertFalse($access->canEdit($targetReader, $page));
        $this->assertFalse($access->canManageAccess($targetReader, $page));
        $this->assertFalse($access->canHardDelete($targetReader, $page));
    }

    public function test_legacy_elevated_workspace_grant_is_clamped_to_target_member_role(): void
    {
        Storage::fake('artifacts');

        $sourceAdmin = app(CreateUser::class)->handle(
            'Source Admin',
            'source-admin@example.test',
            'correct horse battery staple',
        );
        $targetAdmin = app(CreateUser::class)->handle(
            'Target Admin',
            'target-admin@example.test',
            'correct horse battery staple',
        );
        $targetReader = app(CreateUser::class)->handle(
            'Target Reader',
            'target-reader@example.test',
            'correct horse battery staple',
        );
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($sourceAdmin, 'Source Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($targetAdmin, 'Target Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $targetWorkspace->uid,
            'user_uid' => $sourceAdmin->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $targetWorkspace->uid,
            'user_uid' => $targetReader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($sourceAdmin, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Legacy Workspace Grant',
            description: null,
            content: '# Legacy Workspace Grant',
        ));

        PageAccessGrant::query()->forceCreate([
            'page_uid' => $page->uid,
            'subject_type' => PageAccessSubjectType::Workspace,
            'subject_uid' => $targetWorkspace->uid,
            'role' => WorkspaceRole::Admin,
            'granted_by_user_uid' => $sourceAdmin->uid,
        ]);

        $access = app(PageAccess::class);
        $access->flushCache();

        $this->assertTrue($access->canView($targetReader, $page->refresh()));
        $this->assertFalse($access->canEdit($targetReader, $page));
        $this->assertFalse($access->canManageAccess($targetReader, $page));
        $this->assertFalse($access->canHardDelete($targetReader, $page));
    }

    public function test_user_grant_accepts_any_registered_human_coworker_with_role_limits(): void
    {
        Storage::fake('artifacts');

        $owner = app(CreateUser::class)->handle('Owner User', 'owner@example.test', 'correct horse battery staple');
        $coworker = app(CreateUser::class)->handle('Coworker', 'coworker@example.test', 'correct horse battery staple');
        $serviceAccount = app(CreateUser::class)->handle('Automation', 'automation@example.test', 'correct horse battery staple');
        $serviceAccount->forceFill(['is_service_account' => true])->save();
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Internal Coworker Grants',
            description: null,
            content: '# Internal Coworker Grants',
        ));

        try {
            app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
                pageUid: $page->uid,
                subjectType: PageAccessSubjectType::User,
                subjectUid: $serviceAccount->uid,
                role: WorkspaceRole::Reader,
            ));
            $this->fail('Expected a human page grant to reject a service account.');
        } catch (PageAccessGrantTargetUnavailable $exception) {
            $this->assertSame(
                'Page access grants are limited to registered human coworkers.',
                $exception->getMessage(),
            );
        }

        // Explicit Reader and Editor grants are page-only authority and need no
        // membership in the containing workspace.
        $editorGrant = app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $coworker->uid,
            role: WorkspaceRole::Editor,
        ));

        $access = app(PageAccess::class);
        $this->assertSame(WorkspaceRole::Editor, $editorGrant->role);
        $this->assertTrue($access->canView($coworker, $page->refresh()));
        $this->assertTrue($access->canEdit($coworker, $page));
        $this->assertFalse($access->canManageAccess($coworker, $page));

        try {
            app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
                pageUid: $page->uid,
                subjectType: PageAccessSubjectType::User,
                subjectUid: $coworker->uid,
                role: WorkspaceRole::Admin,
            ));
            $this->fail('Expected page Admin grants for a non-page-member to be rejected.');
        } catch (PageAccessGrantTargetUnavailable $exception) {
            $this->assertSame(
                'Page Admin grants require the target user to belong to the page workspace.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(WorkspaceRole::Editor, $editorGrant->refresh()->role);
        $this->assertSame(1, PageAccessGrant::query()->where('page_uid', $page->uid)->count());
    }
}
