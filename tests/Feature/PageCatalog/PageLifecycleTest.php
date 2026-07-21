<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\ArchivePage;
use App\Application\PageCatalog\ArchivePageCommand;
use App\Application\PageCatalog\ArtifactContentDeleter;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\DeprecatePage;
use App\Application\PageCatalog\DeprecatePageCommand;
use App\Application\PageCatalog\HardDeletePage;
use App\Application\PageCatalog\HardDeletePageCommand;
use App\Application\PageCatalog\MarkPageApproved;
use App\Application\PageCatalog\MarkPageApprovedCommand;
use App\Application\PageCatalog\RestoreDeprecatedPage;
use App\Application\PageCatalog\RestoreDeprecatedPageCommand;
use App\Application\PageCatalog\ReturnPageToDraft;
use App\Application\PageCatalog\ReturnPageToDraftCommand;
use App\Application\PageCatalog\UnarchivePage;
use App\Application\PageCatalog\UnarchivePageCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\InvalidPageStatusTransition;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class PageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_owner_can_archive_and_unarchive_page_with_traceability(): void
    {
        Storage::fake('artifacts');

        $workspaceAdmin = $this->createUser('Workspace Admin', 'admin@example.test');
        $pageOwner = $this->createUser('Page Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($workspaceAdmin, 'Platform Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $pageOwner->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($pageOwner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Lifecycle Runbook',
            description: null,
            content: '# Lifecycle Runbook',
            status: PageStatus::Approved,
        ));

        $archivedPage = app(ArchivePage::class)->handle(
            $pageOwner,
            new ArchivePageCommand($page->uid, confirmed: true),
        );

        $this->assertSame(PageStatus::Archived, $archivedPage->status);
        $archiveEvent = DomainEvent::query()
            ->where('event_type', 'page.archived')
            ->sole();
        $this->assertSame($page->uid, $archiveEvent->aggregate_uid);
        $this->assertSame(PageStatus::Approved->value, $archiveEvent->payload['previous_status']);
        $this->assertSame(PageStatus::Archived->value, $archiveEvent->payload['new_status']);
        $this->assertSame(1, AuditEntry::query()->where('action', 'page.archived')->count());

        app(ArchivePage::class)->handle(
            $pageOwner,
            new ArchivePageCommand($page->uid, confirmed: true),
        );
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.archived')->count());

        $unarchivedPage = app(UnarchivePage::class)->handle(
            $pageOwner,
            new UnarchivePageCommand($page->uid, confirmed: true),
        );

        $this->assertSame(PageStatus::Draft, $unarchivedPage->status);
        $unarchiveEvent = DomainEvent::query()
            ->where('event_type', 'page.unarchived')
            ->sole();
        $this->assertSame(PageStatus::Archived->value, $unarchiveEvent->payload['previous_status']);
        $this->assertSame(PageStatus::Draft->value, $unarchiveEvent->payload['new_status']);
        $this->assertSame(1, AuditEntry::query()->where('action', 'page.unarchived')->count());
    }

    public function test_workspace_admin_can_archive_while_non_owner_editor_cannot_archive_or_unarchive(): void
    {
        Storage::fake('artifacts');

        $workspaceAdmin = $this->createUser('Workspace Admin', 'admin@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($workspaceAdmin, 'Platform Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $editor->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($workspaceAdmin, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Admin Lifecycle Page',
            description: null,
            content: '# Admin Lifecycle Page',
        ));

        try {
            app(ArchivePage::class)->handle(
                $editor,
                new ArchivePageCommand($page->uid, confirmed: true),
            );
            $this->fail('Expected non-owner Editor archive to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot archive this page.', $exception->getMessage());
        }

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertDontSee('Archive page');

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/archive", ['confirmed' => '1'])
            ->assertForbidden();

        $this->assertSame(PageStatus::Draft, $page->refresh()->status);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.archived')->count());

        app(ArchivePage::class)->handle(
            $workspaceAdmin,
            new ArchivePageCommand($page->uid, confirmed: true),
        );

        try {
            app(UnarchivePage::class)->handle(
                $editor,
                new UnarchivePageCommand($page->uid, confirmed: true),
            );
            $this->fail('Expected non-owner Editor unarchive to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot unarchive this page.', $exception->getMessage());
        }

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertDontSee('Unarchive page');

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/unarchive", ['confirmed' => '1'])
            ->assertForbidden();

        $this->assertSame(PageStatus::Archived, $page->refresh()->status);
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.archived')->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.unarchived')->count());
    }

    public function test_archive_and_unarchive_require_explicit_reversible_action_confirmation(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Confirmed Lifecycle Page',
            description: null,
            content: '# Confirmed Lifecycle Page',
        ));

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Archiving is reversible')
            ->assertSee('name="confirmed"', false);

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/archive")
            ->assertSessionHasErrors('confirmation');

        $this->assertSame(PageStatus::Draft, $page->refresh()->status);

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/archive", ['confirmed' => '1'])
            ->assertRedirect("/pages/{$page->uid}");

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Unarchiving returns this page to draft')
            ->assertSee('name="confirmed"', false);

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/unarchive")
            ->assertSessionHasErrors('confirmation');

        $this->assertSame(PageStatus::Archived, $page->refresh()->status);

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/unarchive", ['confirmed' => '1'])
            ->assertRedirect("/pages/{$page->uid}");

        $this->assertSame(PageStatus::Draft, $page->refresh()->status);
    }

    public function test_explicit_page_admin_can_archive_and_unarchive_without_page_workspace_membership(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $pageAdmin = $this->createUser('Page Admin', 'page-admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        // The page admin stays outside the page workspace, so archive rights come
        // purely from the explicit page grant.
        $sharedWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Shared Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $sharedWorkspace->uid,
            'user_uid' => $pageAdmin->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Delegated Lifecycle Page',
            description: null,
            content: '# Delegated Lifecycle Page',
        ));
        PageAccessGrant::query()->forceCreate([
            'page_uid' => $page->uid,
            'subject_type' => 'user',
            'subject_uid' => $pageAdmin->uid,
            'role' => WorkspaceRole::Admin,
            'granted_by_user_uid' => $owner->uid,
        ]);

        app(ArchivePage::class)->handle(
            $pageAdmin,
            new ArchivePageCommand($page->uid, confirmed: true),
        );

        $this->assertSame(PageStatus::Archived, $page->refresh()->status);

        app(UnarchivePage::class)->handle(
            $pageAdmin,
            new UnarchivePageCommand($page->uid, confirmed: true),
        );

        $this->assertSame(PageStatus::Draft, $page->refresh()->status);
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.archived')->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.unarchived')->count());
    }

    public function test_editor_can_mark_a_draft_approved_and_return_it_to_draft_with_traceability(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $editor->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Status Marker Page',
            description: null,
            content: '# Status Marker Page',
        ));

        $approvedPage = app(MarkPageApproved::class)->handle(
            $editor,
            new MarkPageApprovedCommand($page->uid),
        );

        $this->assertSame(PageStatus::Approved, $approvedPage->status);
        $approvedEvent = DomainEvent::query()
            ->where('event_type', 'page.marked_approved')
            ->sole();
        $this->assertSame($page->uid, $approvedEvent->aggregate_uid);
        $this->assertSame(PageStatus::Draft->value, $approvedEvent->payload['previous_status']);
        $this->assertSame(PageStatus::Approved->value, $approvedEvent->payload['new_status']);
        $this->assertSame($editor->uid, $approvedEvent->payload['changed_by_user_uid']);
        $this->assertSame(1, AuditEntry::query()->where('action', 'page.marked_approved')->count());

        app(MarkPageApproved::class)->handle($editor, new MarkPageApprovedCommand($page->uid));
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.marked_approved')->count());

        $draftPage = app(ReturnPageToDraft::class)->handle(
            $editor,
            new ReturnPageToDraftCommand($page->uid),
        );

        $this->assertSame(PageStatus::Draft, $draftPage->status);
        $draftEvent = DomainEvent::query()
            ->where('event_type', 'page.returned_to_draft')
            ->sole();
        $this->assertSame(PageStatus::Approved->value, $draftEvent->payload['previous_status']);
        $this->assertSame(PageStatus::Draft->value, $draftEvent->payload['new_status']);
        $this->assertSame($editor->uid, $draftEvent->payload['changed_by_user_uid']);
        $this->assertSame(1, AuditEntry::query()->where('action', 'page.returned_to_draft')->count());

        app(ReturnPageToDraft::class)->handle($editor, new ReturnPageToDraftCommand($page->uid));
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.returned_to_draft')->count());
    }

    public function test_reader_cannot_archive_or_hard_delete_page(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Protected Lifecycle Page',
            description: null,
            content: '# Protected',
        ));
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        try {
            app(ArchivePage::class)->handle(
                $reader,
                new ArchivePageCommand($page->uid, confirmed: true),
            );
            $this->fail('Expected reader archive to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot archive this page.', $exception->getMessage());
        }

        try {
            app(HardDeletePage::class)->handle($reader, new HardDeletePageCommand($page->uid, $page->title));
            $this->fail('Expected reader hard delete to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot permanently delete this page.', $exception->getMessage());
        }

        try {
            app(DeprecatePage::class)->handle($reader, new DeprecatePageCommand($page->uid));
            $this->fail('Expected reader deprecation to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot edit this page.', $exception->getMessage());
        }

        try {
            app(RestoreDeprecatedPage::class)->handle(
                $reader,
                new RestoreDeprecatedPageCommand($page->uid),
            );
            $this->fail('Expected reader deprecation restoration to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot edit this page.', $exception->getMessage());
        }

        try {
            app(MarkPageApproved::class)->handle($reader, new MarkPageApprovedCommand($page->uid));
            $this->fail('Expected reader approval marker update to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot edit this page.', $exception->getMessage());
        }

        $this->assertSame(1, Page::query()->where('uid', $page->uid)->count());
        $this->assertSame(0, DomainEvent::query()->whereIn('event_type', [
            'page.archived',
            'page.deprecated',
            'page.deprecation_restored',
            'page.marked_approved',
            'page.hard_deleted',
        ])->count());
    }

    public function test_page_owner_without_admin_role_cannot_hard_delete_page_or_see_delete_controls(): void
    {
        Storage::fake('artifacts');

        $workspaceAdmin = $this->createUser('Workspace Admin', 'admin@example.test');
        $pageOwner = $this->createUser('Page Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($workspaceAdmin, 'Platform Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $pageOwner->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($pageOwner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Admin Delete Only',
            description: null,
            content: '# Protected from owner deletion',
        ));

        try {
            app(HardDeletePage::class)->handle(
                $pageOwner,
                new HardDeletePageCommand($page->uid, $page->title),
            );
            $this->fail('Expected non-admin page owner hard delete to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot permanently delete this page.', $exception->getMessage());
        }

        $this->actingAs($pageOwner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Archive page')
            ->assertDontSee('Delete page')
            ->assertDontSee('Type the page title to confirm');

        $this->actingAs($pageOwner)
            ->delete("/pages/{$page->uid}", [
                'confirmation' => $page->title,
            ])
            ->assertForbidden();

        $this->assertSame(1, Page::query()->where('uid', $page->uid)->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.hard_deleted')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'page.hard_deleted')->count());
    }

    public function test_workspace_admin_can_hard_delete_page_versions_access_grants_and_stored_content_with_traceability(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $target = $this->createUser('Target User', 'target@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Delete Me',
            description: null,
            content: '# Delete Me',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        PageAccessGrant::query()->forceCreate([
            'page_uid' => $page->uid,
            'subject_type' => 'user',
            'subject_uid' => $target->uid,
            'role' => WorkspaceRole::Reader,
            'granted_by_user_uid' => $owner->uid,
        ]);

        app(HardDeletePage::class)->handle($owner, new HardDeletePageCommand($page->uid, $page->title));

        $this->assertSame(0, Page::query()->where('uid', $page->uid)->count());
        $this->assertSame(0, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(0, PageAccessGrant::query()->where('page_uid', $page->uid)->count());
        Storage::disk('artifacts')->assertMissing($version->content_storage_path);

        $event = DomainEvent::query()
            ->where('event_type', 'page.hard_deleted')
            ->sole();
        $this->assertSame($page->uid, $event->aggregate_uid);
        $this->assertSame($owner->uid, $event->payload['deleted_by_user_uid']);
        $this->assertSame(1, $event->payload['version_count']);

        $auditEntry = AuditEntry::query()
            ->where('action', 'page.hard_deleted')
            ->sole();
        $this->assertSame($event->uid, $auditEntry->event_uid);
        $this->assertSame($page->uid, $auditEntry->auditable_uid);
    }

    public function test_hard_delete_records_artifact_cleanup_failure_without_rolling_back_page_deletion(): void
    {
        Storage::fake('artifacts');
        app()->instance(ArtifactContentDeleter::class, new class() extends ArtifactContentDeleter {
            /**
             * @param list<string> $storagePaths
             */
            public function deleteMany(array $storagePaths): bool
            {
                return false;
            }
        });

        $owner = $this->createUser('Owner User', 'delete-failure@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Cleanup Failure Page',
            description: null,
            content: '# Cleanup Failure Page',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();

        app(HardDeletePage::class)->handle($owner, new HardDeletePageCommand($page->uid, $page->title));

        $this->assertSame(0, Page::query()->where('uid', $page->uid)->count());
        Storage::disk('artifacts')->assertExists($version->content_storage_path);

        $event = DomainEvent::query()
            ->where('event_type', 'page.artifact_delete_failed')
            ->sole();

        $this->assertSame($page->uid, $event->aggregate_uid);
        $this->assertSame($owner->uid, $event->payload['deleted_by_user_uid']);
        $this->assertSame(1, $event->payload['storage_path_count']);

        $auditEntry = AuditEntry::query()
            ->where('action', 'page.artifact_delete_failed')
            ->sole();

        $this->assertSame($event->uid, $auditEntry->event_uid);
        $this->assertSame($page->uid, $auditEntry->auditable_uid);
    }

    public function test_artifact_cleanup_failure_event_and_audit_are_recorded_atomically(): void
    {
        Storage::fake('artifacts');
        app()->instance(ArtifactContentDeleter::class, new class() extends ArtifactContentDeleter {
            /**
             * @param list<string> $storagePaths
             */
            public function deleteMany(array $storagePaths): bool
            {
                return false;
            }
        });

        // Fault-inject the post-commit cleanup-failure audit write only (matched by
        // action), so a non-atomic implementation would leave its domain event orphaned.
        AuditEntry::creating(function (AuditEntry $entry): void {
            if ($entry->action === DomainEventType::PageArtifactDeleteFailed->value) {
                throw new \RuntimeException('Simulated audit write failure.');
            }
        });

        $owner = $this->createUser('Owner User', 'atomic-cleanup@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Atomic Cleanup Page',
            description: null,
            content: '# Atomic Cleanup Page',
        ));

        try {
            app(HardDeletePage::class)->handle($owner, new HardDeletePageCommand($page->uid, $page->title));
            $this->fail('Expected the injected cleanup-failure audit error to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit write failure.', $exception->getMessage());
        }

        // The main hard-delete transaction has already committed, so the page is gone.
        $this->assertSame(0, Page::query()->where('uid', $page->uid)->count());

        // The cleanup-failure event and its audit entry must commit atomically: because
        // the audit write failed, the paired domain event must have rolled back too, so
        // no orphaned event is left behind.
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.artifact_delete_failed')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'page.artifact_delete_failed')->count());
    }

    public function test_http_lifecycle_controls_update_search_visibility_and_redirect_after_delete(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'HTTP Lifecycle Page',
            description: null,
            content: '# HTTP Lifecycle Page',
        ));

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Archive page')
            ->assertSee('Delete page')
            ->assertSee('Type the page title to confirm');

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/archive", ['confirmed' => '1'])
            ->assertRedirect("/pages/{$page->uid}");

        $this->actingAs($owner)
            ->get("/pages?workspace_uid={$workspace->uid}&q=HTTP%20Lifecycle")
            ->assertOk()
            ->assertDontSee('HTTP Lifecycle Page');

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Unarchive page');

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/unarchive", ['confirmed' => '1'])
            ->assertRedirect("/pages/{$page->uid}");

        $this->actingAs($owner)
            ->delete("/pages/{$page->uid}", [
                'confirmation' => $page->title,
            ])
            ->assertRedirect('/pages');

        $this->assertSame(0, Page::query()->where('uid', $page->uid)->count());
    }

    public function test_approved_page_can_be_deprecated_and_restored_to_draft_idempotently_with_traceability(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Deprecated Runbook',
            description: null,
            content: '# Deprecated Runbook',
            status: PageStatus::Approved,
        ));

        $deprecatedPage = app(DeprecatePage::class)->handle($owner, new DeprecatePageCommand($page->uid));

        $this->assertSame(PageStatus::Deprecated, $deprecatedPage->status);
        $event = DomainEvent::query()->where('event_type', 'page.deprecated')->sole();
        $this->assertSame(PageStatus::Approved->value, $event->payload['previous_status']);
        $this->assertSame(PageStatus::Deprecated->value, $event->payload['new_status']);
        $this->assertSame(1, AuditEntry::query()->where('action', 'page.deprecated')->count());

        app(DeprecatePage::class)->handle($owner, new DeprecatePageCommand($page->uid));
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.deprecated')->count());

        $draftPage = app(RestoreDeprecatedPage::class)->handle(
            $owner,
            new RestoreDeprecatedPageCommand($page->uid),
        );

        $this->assertSame(PageStatus::Draft, $draftPage->status);
        $restoredEvent = DomainEvent::query()->where('event_type', 'page.deprecation_restored')->sole();
        $this->assertSame(PageStatus::Deprecated->value, $restoredEvent->payload['previous_status']);
        $this->assertSame(PageStatus::Draft->value, $restoredEvent->payload['new_status']);
        $this->assertSame(1, AuditEntry::query()->where('action', 'page.deprecation_restored')->count());

        app(RestoreDeprecatedPage::class)->handle($owner, new RestoreDeprecatedPageCommand($page->uid));
        $this->assertSame(
            1,
            DomainEvent::query()->where('event_type', 'page.deprecation_restored')->count(),
        );
    }

    public function test_invalid_deprecation_transitions_are_rejected_without_trace_events(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $draftPage = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Draft Page',
            description: null,
            content: '# Draft',
        ));
        $approvedPage = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Approved Page',
            description: null,
            content: '# Approved',
            status: PageStatus::Approved,
        ));

        try {
            app(DeprecatePage::class)->handle($owner, new DeprecatePageCommand($draftPage->uid));
            $this->fail('Expected draft deprecation to be rejected.');
        } catch (InvalidPageStatusTransition $exception) {
            $this->assertSame('Only approved pages can be deprecated.', $exception->getMessage());
        }

        try {
            app(RestoreDeprecatedPage::class)->handle(
                $owner,
                new RestoreDeprecatedPageCommand($approvedPage->uid),
            );
            $this->fail('Expected approved page restoration to draft to be rejected.');
        } catch (InvalidPageStatusTransition $exception) {
            $this->assertSame('Only deprecated pages can be restored to draft.', $exception->getMessage());
        }

        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.deprecated')->count());
        $this->assertSame(
            0,
            DomainEvent::query()->where('event_type', 'page.deprecation_restored')->count(),
        );
    }

    public function test_approval_marker_rejects_archived_and_deprecated_status_transitions(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $archivedPage = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Archived Status Page',
            description: null,
            content: '# Archived',
        ));
        app(ArchivePage::class)->handle(
            $owner,
            new ArchivePageCommand($archivedPage->uid, confirmed: true),
        );
        $deprecatedPage = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Deprecated Status Page',
            description: null,
            content: '# Deprecated',
            status: PageStatus::Approved,
        ));
        app(DeprecatePage::class)->handle($owner, new DeprecatePageCommand($deprecatedPage->uid));

        foreach ([
            [
                static fn () => app(MarkPageApproved::class)->handle(
                    $owner,
                    new MarkPageApprovedCommand($archivedPage->uid),
                ),
                'Only draft pages can be marked approved.',
            ],
            [
                static fn () => app(MarkPageApproved::class)->handle(
                    $owner,
                    new MarkPageApprovedCommand($deprecatedPage->uid),
                ),
                'Only draft pages can be marked approved.',
            ],
            [
                static fn () => app(ReturnPageToDraft::class)->handle(
                    $owner,
                    new ReturnPageToDraftCommand($archivedPage->uid),
                ),
                'Only approved pages can be returned to draft.',
            ],
            [
                static fn () => app(ReturnPageToDraft::class)->handle(
                    $owner,
                    new ReturnPageToDraftCommand($deprecatedPage->uid),
                ),
                'Only approved pages can be returned to draft.',
            ],
        ] as [$operation, $expectedMessage]) {
            try {
                $operation();
                $this->fail('Expected invalid page status marker transition to be rejected.');
            } catch (InvalidPageStatusTransition $exception) {
                $this->assertSame($expectedMessage, $exception->getMessage());
            }
        }

        $this->assertSame(PageStatus::Archived, $archivedPage->refresh()->status);
        $this->assertSame(PageStatus::Deprecated, $deprecatedPage->refresh()->status);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.marked_approved')->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.returned_to_draft')->count());
    }

    public function test_http_deprecation_lifecycle_shows_warning_banner_and_authorized_controls(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
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
            title: 'Deprecated HTTP Page',
            description: null,
            content: '# Deprecated',
            status: PageStatus::Approved,
        ));

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Deprecate page');

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/deprecate")
            ->assertRedirect("/pages/{$page->uid}");

        $this->actingAs($reader)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('This page is deprecated')
            ->assertDontSee('Restore to draft');

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('This page is deprecated')
            ->assertSee('Restore to draft');

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/restore-to-draft")
            ->assertRedirect("/pages/{$page->uid}");

        $this->assertSame(PageStatus::Draft, $page->refresh()->status);
    }

    public function test_http_status_marker_controls_are_visible_only_to_editors_and_validate_transitions(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
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
            title: 'HTTP Status Marker',
            description: null,
            content: '# Status',
        ));

        $this->actingAs($reader)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertDontSee('Mark approved')
            ->assertDontSee('Return to draft');

        $this->actingAs($reader)
            ->post("/pages/{$page->uid}/mark-approved")
            ->assertForbidden();

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Mark approved')
            ->assertDontSee('Return to draft');

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/mark-approved")
            ->assertRedirect("/pages/{$page->uid}");

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Return to draft')
            ->assertSee('Deprecate page')
            ->assertDontSee('Mark approved');

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/return-to-draft")
            ->assertRedirect("/pages/{$page->uid}");

        $this->assertSame(PageStatus::Draft, $page->refresh()->status);

        app(ArchivePage::class)->handle(
            $owner,
            new ArchivePageCommand($page->uid, confirmed: true),
        );

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/mark-approved")
            ->assertSessionHasErrors('lifecycle');
    }

    public function test_hard_delete_requires_exact_page_title_confirmation(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Exact Delete Title',
            description: null,
            content: '# Delete',
        ));

        foreach (['', 'exact delete title', 'Exact Delete Titles'] as $confirmation) {
            $this->actingAs($owner)
                ->delete("/pages/{$page->uid}", [
                    'confirmation' => $confirmation,
                ])
                ->assertSessionHasErrors('confirmation');

            $this->assertSame(1, Page::query()->where('uid', $page->uid)->count());
        }

        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.hard_deleted')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'page.hard_deleted')->count());
    }

    public function test_hard_delete_rechecks_confirmation_after_locking_a_concurrently_renamed_page(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Delete Owner', 'stale-delete-confirmation@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Delete Confirmation Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Old Delete Title',
            description: null,
            content: '# Keep after rename',
        ));
        $renamed = false;

        DB::listen(function (QueryExecuted $query) use (&$renamed, $page): void {
            $sql = strtolower($query->sql);
            if (
                $renamed
                || str_contains($sql, 'for update')
                || !str_contains($sql, 'from "pages"')
                || !in_array($page->uid, $query->bindings, true)
            ) {
                return;
            }

            $renamed = true;
            DB::table('pages')->where('uid', $page->uid)->update([
                'title' => 'New Delete Title',
                'updated_at' => now(),
            ]);
        });

        try {
            app(HardDeletePage::class)->handle(
                $owner,
                new HardDeletePageCommand($page->uid, 'Old Delete Title'),
            );
            $this->fail('Expected stale hard-delete confirmation to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame(
                'Type the page title exactly to permanently delete it.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue($renamed);
        $this->assertSame('New Delete Title', Page::query()->findOrFail($page->uid)->title);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.hard_deleted')->count());
    }

    public function test_failed_hard_delete_transaction_keeps_database_records_and_stored_content(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $page = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Rollback Delete',
            description: null,
            content: '# Keep this content',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $eventName = 'eloquent.deleting: ' . Page::class;

        Event::listen($eventName, static function (): void {
            throw new RuntimeException('Forced page deletion failure.');
        });

        try {
            app(HardDeletePage::class)->handle(
                $admin,
                new HardDeletePageCommand($page->uid, $page->title),
            );
            $this->fail('Expected hard delete transaction failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced page deletion failure.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame(1, Page::query()->where('uid', $page->uid)->count());
        $this->assertSame(1, PageVersion::query()->where('uid', $version->uid)->count());
        Storage::disk('artifacts')->assertExists($version->content_storage_path);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.hard_deleted')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'page.hard_deleted')->count());
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
