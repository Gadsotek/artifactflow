<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\ArtifactPreviewUrl;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\MovePageToWorkspace;
use App\Application\PageCatalog\MovePageToWorkspaceCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\AuditEntry;
use App\Models\Category;
use App\Models\DomainEvent;
use App\Models\PageAccessGrant;
use App\Models\PageVersion;
use App\Models\Tag;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageWorkspaceMoveTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_can_move_a_leaf_page_between_writable_workspaces_with_traceability(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $targetOwner = $this->createUser('Target Owner', 'target-owner@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Source Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Target Team');
        $this->addMember($targetWorkspace->uid, $targetOwner, WorkspaceRole::Editor);
        $category = Category::query()->create([
            'workspace_uid' => $sourceWorkspace->uid,
            'name' => 'Runbooks',
            'slug' => 'runbooks',
            'created_by_user_uid' => $admin->uid,
        ]);
        $parent = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Source Parent',
            description: null,
            content: '# Source Parent',
        ));
        $existingTargetPage = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $targetWorkspace->uid,
            type: PageType::Markdown,
            title: 'Portable Runbook',
            description: null,
            content: '# Existing',
        ));
        $page = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Portable Runbook',
            description: 'Move me safely.',
            content: '# Portable',
            status: PageStatus::Approved,
            categoryUid: $category->uid,
            parentPageUid: $parent->uid,
            tagNames: ['Operations', 'Runbook'],
        ));
        $previousPreviewRevision = $page->preview_access_revision;
        $previousMetadataRevision = $page->metadata_revision;
        $originalVersionUid = $page->current_version_uid;
        $page->forceFill(['access_mode' => PageAccessMode::Restricted])->save();
        PageAccessGrant::query()->forceCreate([
            'page_uid' => $page->uid,
            'subject_type' => PageAccessSubjectType::Workspace,
            'subject_uid' => $sourceWorkspace->uid,
            'role' => WorkspaceRole::Reader,
            'granted_by_user_uid' => $admin->uid,
        ]);

        $movedPage = app(MovePageToWorkspace::class)->handle($admin, new MovePageToWorkspaceCommand(
            pageUid: $page->uid,
            targetWorkspaceUid: $targetWorkspace->uid,
            targetOwnerUserUid: $targetOwner->uid,
            confirmed: true,
        ));

        $this->assertSame($targetWorkspace->uid, $movedPage->workspace_uid);
        $this->assertSame($previousPreviewRevision + 1, $movedPage->preview_access_revision);
        $this->assertSame($previousMetadataRevision + 1, $movedPage->metadata_revision);
        $this->assertSame($targetOwner->uid, $movedPage->owner_user_uid);
        $this->assertSame('portable-runbook-2', $movedPage->slug);
        $this->assertNotNull($movedPage->category_uid);
        $movedCategory = Category::query()->findOrFail($movedPage->category_uid);
        $this->assertSame($targetWorkspace->uid, $movedCategory->workspace_uid);
        $this->assertSame('Runbooks', $movedCategory->name);
        $this->assertSame('runbooks', $movedCategory->slug);
        $this->assertNull($movedPage->parent_page_uid);
        $this->assertSame(PageAccessMode::Inherited, $movedPage->access_mode);
        $this->assertSame(PageStatus::Draft, $movedPage->status);
        $this->assertSame($originalVersionUid, $movedPage->current_version_uid);
        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(0, PageAccessGrant::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(
            ['operations', 'runbook'],
            $movedPage->tags()->orderBy('name')->pluck('name')->all(),
        );
        $this->assertSame('portable-runbook', $existingTargetPage->refresh()->slug);

        $event = DomainEvent::query()
            ->where('event_type', 'page.workspace.moved')
            ->sole();
        $this->assertSame($page->uid, $event->aggregate_uid);
        $this->assertSame($sourceWorkspace->uid, $event->payload['previous_workspace_uid']);
        $this->assertSame($targetWorkspace->uid, $event->payload['new_workspace_uid']);
        $this->assertSame($admin->uid, $event->payload['previous_owner_user_uid']);
        $this->assertSame($targetOwner->uid, $event->payload['new_owner_user_uid']);
        $this->assertSame($admin->uid, $event->payload['moved_by_user_uid']);
        $this->assertSame(true, $event->payload['preserved_category']);
        $this->assertSame(true, $event->payload['cleared_parent']);
        $this->assertSame(1, $event->payload['revoked_access_grant_count']);
        $this->assertSame(2, $event->payload['tag_count']);
        $this->assertSame(PageStatus::Approved->value, $event->payload['previous_status']);
        $this->assertSame(PageStatus::Draft->value, $event->payload['new_status']);
        $this->assertArrayNotHasKey('title', $event->payload);
        $this->assertArrayNotHasKey('description', $event->payload);

        $auditEntry = AuditEntry::query()
            ->where('action', 'page.workspace.moved')
            ->sole();
        $this->assertSame($event->uid, $auditEntry->event_uid);
        $this->assertSame($admin->uid, $auditEntry->actor_user_uid);
        $this->assertSame($page->uid, $auditEntry->auditable_uid);
        $this->assertSame($sourceWorkspace->uid, $auditEntry->metadata['previous_workspace_uid']);
        $this->assertSame($targetWorkspace->uid, $auditEntry->metadata['new_workspace_uid']);
        $this->assertSame(PageStatus::Approved->value, $auditEntry->metadata['previous_status']);
        $this->assertSame(PageStatus::Draft->value, $auditEntry->metadata['new_status']);
        $this->assertArrayNotHasKey('title', $auditEntry->metadata);
        $this->assertArrayNotHasKey('description', $auditEntry->metadata);

        $ownershipEvent = DomainEvent::query()
            ->where('event_type', 'page.ownership.transferred')
            ->sole();
        $this->assertSame('workspace_move', $ownershipEvent->payload['reason']);
    }

    public function test_metadata_form_opened_before_a_workspace_move_cannot_restore_the_previous_owner(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('Admin User', 'metadata-move-admin@example.test');
        $previousOwner = $this->createUser('Previous Owner', 'metadata-move-owner@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Source Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Target Team');
        $this->addMember($sourceWorkspace->uid, $previousOwner, WorkspaceRole::Editor);
        $this->addMember($targetWorkspace->uid, $previousOwner, WorkspaceRole::Editor);
        $page = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Move OCC',
            description: 'Opened before move.',
            content: '# Move OCC',
            ownerUserUid: $previousOwner->uid,
        ));
        $openedRevision = $page->metadata_revision;

        $movedPage = app(MovePageToWorkspace::class)->handle($admin, new MovePageToWorkspaceCommand(
            pageUid: $page->uid,
            targetWorkspaceUid: $targetWorkspace->uid,
            targetOwnerUserUid: $admin->uid,
            confirmed: true,
        ));

        $this->actingAs($admin)
            ->put("/pages/{$page->uid}/metadata", [
                'metadata_revision' => $openedRevision,
                'title' => 'Move OCC',
                'description' => 'Opened before move.',
                'owner_user_uid' => $previousOwner->uid,
                'tags' => '',
            ])
            ->assertStatus(409);

        $page->refresh();
        $this->assertSame($targetWorkspace->uid, $page->workspace_uid);
        $this->assertSame($admin->uid, $page->owner_user_uid);
        $this->assertSame($openedRevision + 1, $page->metadata_revision);
        $this->assertSame($movedPage->metadata_revision, $page->metadata_revision);
    }

    public function test_workspace_move_invalidates_previously_signed_artifact_preview_urls(): void
    {
        Storage::fake('artifacts');
        config(['app.artifact_url' => 'http://localhost']);

        $admin = $this->createUser('Admin User', 'move-preview-admin@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Source Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Target Team');
        $page = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Movable Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Before Move</h1></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);

        app(MovePageToWorkspace::class)->handle($admin, new MovePageToWorkspaceCommand(
            pageUid: $page->uid,
            targetWorkspaceUid: $targetWorkspace->uid,
            targetOwnerUserUid: $admin->uid,
            confirmed: true,
        ));

        config(['app.runtime_role' => 'artifact-host']);

        $this->get($url)->assertNotFound();
    }

    public function test_workspace_move_preserves_global_tags_and_reuses_an_existing_target_category_by_slug(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('Admin User', 'normalizing-admin@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Source Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Target Team');
        $targetCategory = Category::query()->create([
            'workspace_uid' => $targetWorkspace->uid,
            'name' => 'Runbooks',
            'slug' => 'runbooks',
            'created_by_user_uid' => $admin->uid,
        ]);
        $sourceCategory = Category::query()->create([
            'workspace_uid' => $sourceWorkspace->uid,
            'name' => 'Runbooks',
            'slug' => 'runbooks',
            'created_by_user_uid' => $admin->uid,
        ]);
        $page = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Tagged Runbook',
            description: null,
            content: '# Tagged Runbook',
            categoryUid: $sourceCategory->uid,
            tagNames: ['OPERATIONS'],
        ));
        $tagUid = $page->tags()->sole()->uid;

        $movedPage = app(MovePageToWorkspace::class)->handle($admin, new MovePageToWorkspaceCommand(
            pageUid: $page->uid,
            targetWorkspaceUid: $targetWorkspace->uid,
            targetOwnerUserUid: $admin->uid,
            confirmed: true,
        ));

        $this->assertSame(['operations'], $movedPage->tags()->pluck('name')->all());
        $this->assertSame([$tagUid], $movedPage->tags()->pluck('tags.uid')->all());
        $this->assertSame($targetCategory->uid, $movedPage->category_uid);
        $this->assertSame(1, Category::query()->where('workspace_uid', $targetWorkspace->uid)->where('slug', 'runbooks')->count());
        $this->assertSame(1, Tag::query()->where('slug', 'operations')->count());
    }

    public function test_page_move_requires_source_administration_target_create_access_and_confirmation(): void
    {
        Storage::fake('artifacts');

        $sourceAdmin = $this->createUser('Source Admin', 'source-admin@example.test');
        $targetAdmin = $this->createUser('Target Admin', 'target-admin@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($sourceAdmin, 'Source Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($targetAdmin, 'Target Team');
        $this->addMember($sourceWorkspace->uid, $editor, WorkspaceRole::Editor);
        $this->addMember($targetWorkspace->uid, $editor, WorkspaceRole::Editor);
        $this->addMember($targetWorkspace->uid, $sourceAdmin, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($sourceAdmin, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Protected Move',
            description: null,
            content: '# Protected',
            ownerUserUid: $editor->uid,
        ));

        try {
            app(MovePageToWorkspace::class)->handle($editor, new MovePageToWorkspaceCommand(
                pageUid: $page->uid,
                targetWorkspaceUid: $targetWorkspace->uid,
                targetOwnerUserUid: $editor->uid,
                confirmed: true,
            ));
            $this->fail('Expected non-admin source move to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot move this page out of its current workspace.', $exception->getMessage());
        }

        try {
            app(MovePageToWorkspace::class)->handle($sourceAdmin, new MovePageToWorkspaceCommand(
                pageUid: $page->uid,
                targetWorkspaceUid: $targetWorkspace->uid,
                targetOwnerUserUid: $editor->uid,
                confirmed: true,
            ));
            $this->fail('Expected target Reader move to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot move pages into this workspace.', $exception->getMessage());
        }

        try {
            app(MovePageToWorkspace::class)->handle($sourceAdmin, new MovePageToWorkspaceCommand(
                pageUid: $page->uid,
                targetWorkspaceUid: $sourceWorkspace->uid,
                targetOwnerUserUid: $sourceAdmin->uid,
                confirmed: false,
            ));
            $this->fail('Expected unconfirmed move to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Page workspace moves must be explicitly confirmed.', $exception->getMessage());
        }

        $this->assertSame($sourceWorkspace->uid, $page->refresh()->workspace_uid);
        $this->assertSame($editor->uid, $page->owner_user_uid);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.workspace.moved')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'page.workspace.moved')->count());
    }

    public function test_system_admin_target_workspace_editor_cannot_pull_page_and_reset_grants(): void
    {
        Storage::fake('artifacts');

        $sourceAdmin = $this->createUser('Source Admin', 'source-admin@example.test');
        $sourceAdmin->forceFill([
            'is_system_admin' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [Hash::make('ABCD2-EFGH3')],
        ])->save();
        $targetAdmin = $this->createUser('Target Admin', 'target-admin@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($sourceAdmin, 'Source Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($targetAdmin, 'Target Team');
        $this->addMember($targetWorkspace->uid, $sourceAdmin, WorkspaceRole::Editor);
        $page = app(CreatePage::class)->handle($sourceAdmin, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Grant Protected Move',
            description: null,
            content: '# Grant Protected',
        ));
        PageAccessGrant::query()->forceCreate([
            'page_uid' => $page->uid,
            'subject_type' => PageAccessSubjectType::Workspace,
            'subject_uid' => $sourceWorkspace->uid,
            'role' => WorkspaceRole::Reader,
            'granted_by_user_uid' => $sourceAdmin->uid,
        ]);

        $this->actingAs($sourceAdmin)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertDontSee('data-move-target-workspace-option value="' . $targetWorkspace->uid . '"', false)
            ->assertDontSee('name="target_workspace_uid"', false);

        try {
            app(MovePageToWorkspace::class)->handle($sourceAdmin, new MovePageToWorkspaceCommand(
                pageUid: $page->uid,
                targetWorkspaceUid: $targetWorkspace->uid,
                targetOwnerUserUid: $sourceAdmin->uid,
                confirmed: true,
            ));
            $this->fail('Expected target workspace Editor move to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(
                'You must be a target workspace Admin to move pages into it.',
                $exception->getMessage(),
            );
        }

        $this->assertSame($sourceWorkspace->uid, $page->refresh()->workspace_uid);
        $this->assertSame(1, PageAccessGrant::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.workspace.moved')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'page.workspace.moved')->count());
    }

    public function test_page_move_rejects_target_workspace_storage_quota_without_side_effects(): void
    {
        Storage::fake('artifacts');
        config([
            'pages.max_markdown_bytes' => 100,
            'pages.max_workspace_storage_bytes' => 18,
        ]);

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Source Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Target Team');
        app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $targetWorkspace->uid,
            type: PageType::Markdown,
            title: 'Existing Target Bytes',
            description: null,
            content: '0123456789',
        ));
        $page = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Moving Bytes',
            description: null,
            content: 'abcdefghi',
        ));
        PageAccessGrant::query()->forceCreate([
            'page_uid' => $page->uid,
            'subject_type' => PageAccessSubjectType::Workspace,
            'subject_uid' => $sourceWorkspace->uid,
            'role' => WorkspaceRole::Reader,
            'granted_by_user_uid' => $admin->uid,
        ]);

        try {
            app(MovePageToWorkspace::class)->handle($admin, new MovePageToWorkspaceCommand(
                pageUid: $page->uid,
                targetWorkspaceUid: $targetWorkspace->uid,
                targetOwnerUserUid: $admin->uid,
                confirmed: true,
            ));
            $this->fail('Expected target workspace quota rejection.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Workspace page storage quota exceeded.', $exception->getMessage());
        }

        $this->assertSame($sourceWorkspace->uid, $page->refresh()->workspace_uid);
        $this->assertSame($admin->uid, $page->owner_user_uid);
        $this->assertSame(1, PageAccessGrant::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.workspace.moved')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'page.workspace.moved')->count());
    }

    public function test_shared_workspace_page_cannot_be_moved_into_a_personal_workspace(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Source Team');
        $personalWorkspace = \App\Models\Workspace::query()
            ->where('personal_owner_uid', $admin->uid)
            ->sole();
        $page = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Shared Knowledge',
            description: null,
            content: '# Shared',
        ));

        try {
            app(MovePageToWorkspace::class)->handle($admin, new MovePageToWorkspaceCommand(
                pageUid: $page->uid,
                targetWorkspaceUid: $personalWorkspace->uid,
                targetOwnerUserUid: $admin->uid,
                confirmed: true,
            ));
            $this->fail('Expected personal workspace moves to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Pages cannot be moved into personal workspaces.', $exception->getMessage());
        }

        $this->assertSame($sourceWorkspace->uid, $page->refresh()->workspace_uid);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.workspace.moved')->count());
    }

    public function test_page_move_rejects_children_and_target_reader_ownership_without_side_effects(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $targetReader = $this->createUser('Target Reader', 'target-reader@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Source Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Target Team');
        $this->addMember($targetWorkspace->uid, $targetReader, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Parent Page',
            description: null,
            content: '# Parent',
        ));
        app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Child Page',
            description: null,
            content: '# Child',
            parentPageUid: $page->uid,
        ));

        try {
            app(MovePageToWorkspace::class)->handle($admin, new MovePageToWorkspaceCommand(
                pageUid: $page->uid,
                targetWorkspaceUid: $targetWorkspace->uid,
                targetOwnerUserUid: $admin->uid,
                confirmed: true,
            ));
            $this->fail('Expected page with children to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame(
                'Move or detach child pages before moving this page to another workspace.',
                $exception->getMessage(),
            );
        }

        try {
            app(MovePageToWorkspace::class)->handle($admin, new MovePageToWorkspaceCommand(
                pageUid: $page->uid,
                targetWorkspaceUid: $targetWorkspace->uid,
                targetOwnerUserUid: $targetReader->uid,
                confirmed: true,
            ));
            $this->fail('Expected target Reader owner to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Page owner must be a target workspace editor or admin.', $exception->getMessage());
        }

        $this->assertSame($sourceWorkspace->uid, $page->refresh()->workspace_uid);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.workspace.moved')->count());
    }

    public function test_workspace_move_is_available_from_page_detail_only_to_allowed_admins(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $targetOwner = $this->createUser('Target Owner', 'target-owner@example.test');
        $targetReader = $this->createUser('Target Reader', 'target-reader@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Source Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Target Team');
        $this->addMember($sourceWorkspace->uid, $reader, WorkspaceRole::Reader);
        $this->addMember($targetWorkspace->uid, $targetOwner, WorkspaceRole::Editor);
        $this->addMember($targetWorkspace->uid, $targetReader, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'HTTP Move',
            description: null,
            content: '# Move',
        ));

        $this->actingAs($admin)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Move workspace')
            ->assertSee('name="target_workspace_uid"', false)
            ->assertSee('data-move-target-workspace-option value="' . $targetWorkspace->uid . '"', false)
            ->assertDontSee('data-move-target-workspace-option value="' . $sourceWorkspace->uid . '"', false)
            ->assertSee(
                'data-move-target-owner-workspace-uid="' . $targetWorkspace->uid . '" value="' . $targetOwner->uid . '"',
                false,
            )
            ->assertDontSee(
                'data-move-target-owner-workspace-uid="' . $targetWorkspace->uid . '" value="' . $targetReader->uid . '"',
                false,
            );

        $this->actingAs($reader)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertDontSee('Move workspace')
            ->assertDontSee('name="target_workspace_uid"', false);

        $this->actingAs($reader)
            ->put("/pages/{$page->uid}/workspace", [
                'target_workspace_uid' => $targetWorkspace->uid,
                'target_owner_user_uid' => $targetOwner->uid,
                'confirm_move' => '1',
            ])
            ->assertForbidden();

        $this->assertSame($sourceWorkspace->uid, $page->refresh()->workspace_uid);
        $this->assertSame($admin->uid, $page->owner_user_uid);

        $this->actingAs($admin)
            ->put("/pages/{$page->uid}/workspace", [
                'target_workspace_uid' => $targetWorkspace->uid,
                'target_owner_user_uid' => $targetOwner->uid,
                'confirm_move' => '1',
            ])
            ->assertRedirect("/pages/{$page->uid}")
            ->assertSessionHas('status', 'Page moved to the selected workspace.');

        $this->assertSame($targetWorkspace->uid, $page->refresh()->workspace_uid);
        $this->assertSame($targetOwner->uid, $page->owner_user_uid);
    }

    private function createUser(string $name, string $email): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        app(\App\Application\Identity\CreatePersonalWorkspaceForUser::class)->handle($user);

        return $user;
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
