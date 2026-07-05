<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpRequestContext;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\RevokePageAccess;
use App\Application\PageCatalog\RevokePageAccessCommand;
use App\Application\PageCatalog\UpdatePageAccessMode;
use App\Application\PageCatalog\UpdatePageAccessModeCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\PageAccessGrant;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Policies\PagePolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private const string GRANT_STATUS_FLASH = 'If that email belongs to an eligible registered coworker, their access has been granted.';

    public function test_owner_can_restrict_workspace_inheritance_with_traceability_and_idempotency(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Security Team');
        $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);
        $this->addMember($workspace->uid, $admin, WorkspaceRole::Admin);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Restricted Runbook',
            description: null,
            content: '# Restricted Runbook',
        ));
        $access = app(PageAccess::class);

        $this->assertSame(PageAccessMode::Inherited, $page->access_mode);
        $this->assertTrue($access->canView($reader, $page));
        $this->assertTrue($access->canEdit($editor, $page));

        $command = new UpdatePageAccessModeCommand(
            pageUid: $page->uid,
            accessMode: PageAccessMode::Restricted,
        );
        $updatedPage = app(UpdatePageAccessMode::class)->handle($owner, $command);

        $this->assertSame(PageAccessMode::Restricted, $updatedPage->access_mode);
        $this->assertFalse($access->canView($reader, $updatedPage));
        $this->assertFalse($access->canEdit($editor, $updatedPage));
        $this->assertTrue($access->canView($owner, $updatedPage));
        $this->assertTrue($access->canEdit($owner, $updatedPage));
        $this->assertTrue($access->canView($admin, $updatedPage));
        $this->assertTrue($access->canEdit($admin, $updatedPage));
        $this->assertTrue($access->canHardDelete($admin, $updatedPage));

        $event = DomainEvent::query()
            ->where('event_type', 'page.access_mode.updated')
            ->sole();
        $this->assertSame($page->uid, $event->aggregate_uid);
        $this->assertSame(PageAccessMode::Inherited->value, $event->payload['previous_access_mode']);
        $this->assertSame(PageAccessMode::Restricted->value, $event->payload['new_access_mode']);
        $this->assertSame($owner->uid, $event->payload['updated_by_user_uid']);

        $auditEntry = AuditEntry::query()
            ->where('action', 'page.access_mode.updated')
            ->sole();
        $this->assertSame($event->uid, $auditEntry->event_uid);
        $this->assertSame($owner->uid, $auditEntry->actor_user_uid);
        $this->assertSame($page->uid, $auditEntry->auditable_uid);

        app(UpdatePageAccessMode::class)->handle($owner, $command);

        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.access_mode.updated')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'page.access_mode.updated')->count());

        $inheritedPage = app(UpdatePageAccessMode::class)->handle($owner, new UpdatePageAccessModeCommand(
            pageUid: $page->uid,
            accessMode: PageAccessMode::Inherited,
        ));

        $this->assertTrue($access->canView($reader, $inheritedPage));
        $this->assertTrue($access->canEdit($editor, $inheritedPage));
        $this->assertSame(2, DomainEvent::query()->where('event_type', 'page.access_mode.updated')->count());
        $this->assertSame(2, AuditEntry::query()->where('action', 'page.access_mode.updated')->count());
    }

    public function test_workspace_editor_cannot_change_page_access_mode_even_when_editor_sharing_is_enabled(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Security Team');
        $workspace->forceFill(['allow_editor_page_sharing' => true])->save();
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Protected Access Mode',
            description: null,
            content: '# Protected',
        ));

        try {
            app(UpdatePageAccessMode::class)->handle($editor, new UpdatePageAccessModeCommand(
                pageUid: $page->uid,
                accessMode: PageAccessMode::Restricted,
            ));
            $this->fail('Expected workspace editor access-mode update to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot change access mode for this page.', $exception->getMessage());
        }

        $this->assertSame(PageAccessMode::Inherited, $page->refresh()->access_mode);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.access_mode.updated')->count());
    }

    public function test_restricted_page_owner_can_still_view_edit_and_find_their_page_without_manage_access_when_sharing_is_disabled(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('Admin User', 'restricted-admin@example.test');
        $editorOwner = $this->createUser('Editor Owner', 'restricted-editor-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Restricted Owner Team');
        $workspace->forceFill(['allow_editor_page_sharing' => false])->save();
        $this->addMember($workspace->uid, $editorOwner, WorkspaceRole::Editor);
        $page = app(CreatePage::class)->handle($editorOwner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Editor Owned Restricted Page',
            description: null,
            content: '# Editor Owned Restricted Page',
        ));

        $restrictedPage = app(UpdatePageAccessMode::class)->handle($editorOwner, new UpdatePageAccessModeCommand(
            pageUid: $page->uid,
            accessMode: PageAccessMode::Restricted,
        ));

        $access = app(PageAccess::class);
        $this->assertTrue($access->canView($editorOwner, $restrictedPage));
        $this->assertTrue($access->canEdit($editorOwner, $restrictedPage));
        $this->assertFalse($access->canManageAccess($editorOwner, $restrictedPage));

        $this->actingAs($editorOwner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Editor Owned Restricted Page');

        $this->actingAs($editorOwner)
            ->get('/pages?workspace_uid=all&q=' . urlencode('Editor Owned Restricted Page'))
            ->assertOk()
            ->assertSee('Editor Owned Restricted Page');

        $this->actingAs($editorOwner)
            ->post("/pages/{$page->uid}/versions", [
                'content' => '# Owner can still edit',
                'base_version_uid' => $restrictedPage->current_version_uid,
            ])
            ->assertRedirect("/pages/{$page->uid}");

        $this->actingAs($editorOwner)
            ->post("/pages/{$page->uid}/access", [
                'subject_type' => PageAccessSubjectType::User->value,
                'subject_identifier' => 'someone@example.test',
                'role' => WorkspaceRole::Reader->value,
            ])
            ->assertForbidden();

        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_stale_page_owner_without_workspace_membership_cannot_archive_or_administer_page(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Stale Owner', 'stale-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Stale Owner Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Stale Owner Page',
            description: null,
            content: '# Stale Owner Page',
        ));

        WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $owner->uid)
            ->delete();
        $this->app->forgetScopedInstances();

        $access = app(PageAccess::class);

        $this->assertFalse($access->canView($owner, $page));
        $this->assertFalse($access->canEdit($owner, $page));
        $this->assertFalse($access->canArchive($owner, $page));
        $this->assertFalse($access->canChangeAccessMode($owner, $page));
        $this->assertFalse($access->canTransferOwnership($owner, $page));
        $this->assertFalse($access->canManageAccess($owner, $page));
    }

    public function test_page_editor_grant_cannot_change_access_mode(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $pageEditor = $this->createUser('Page Editor', 'page-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Security Team');
        $workspace->forceFill(['allow_editor_page_sharing' => true])->save();
        $this->addMember($workspace->uid, $pageEditor, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Delegated Editor Page',
            description: null,
            content: '# Delegated Editor Page',
        ));
        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $pageEditor->uid,
            role: WorkspaceRole::Editor,
        ));

        $this->assertTrue(app(PageAccess::class)->canEdit($pageEditor, $page));
        $this->assertFalse(app(PageAccess::class)->canChangeAccessMode($pageEditor, $page));

        try {
            app(UpdatePageAccessMode::class)->handle($pageEditor, new UpdatePageAccessModeCommand(
                pageUid: $page->uid,
                accessMode: PageAccessMode::Restricted,
            ));
            $this->fail('Expected page editor access-mode update to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot change access mode for this page.', $exception->getMessage());
        }

        $this->assertSame(PageAccessMode::Inherited, $page->refresh()->access_mode);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.access_mode.updated')->count());
    }

    public function test_page_policy_delegates_page_authority_without_granting_admin_class_actions_to_readers(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'policy-owner@example.test');
        $editor = $this->createUser('Editor User', 'policy-editor@example.test');
        $reader = $this->createUser('Reader User', 'policy-reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Policy Team');
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);
        $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Policy Page',
            description: null,
            content: '# Policy Page',
        ));

        $policy = app(PagePolicy::class);

        $this->assertTrue($policy->view($reader, $page));
        $this->assertFalse($policy->update($reader, $page));
        $this->assertFalse($policy->archive($reader, $page));
        $this->assertFalse($policy->move($reader, $page));
        $this->assertTrue($policy->update($editor, $page));
        $this->assertFalse($policy->archive($editor, $page));
        $this->assertTrue($policy->archive($owner, $page));
    }

    public function test_reader_cannot_archive_a_page_through_the_policy_protected_route(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'archive-policy-owner@example.test');
        $reader = $this->createUser('Reader User', 'archive-policy-reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Archive Policy Team');
        $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Archive Policy Page',
            description: null,
            content: '# Archive Policy Page',
        ));

        $this->actingAs($reader)
            ->post("/pages/{$page->uid}/archive", ['confirmed' => '1'])
            ->assertForbidden();

        $this->assertSame(PageStatus::Draft, $page->refresh()->status);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.archived')->count());
    }

    public function test_page_access_memoizes_workspace_and_page_grant_roles_per_actor(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $target = $this->createUser('Target User', 'target@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Security Team');
        $this->addMember($workspace->uid, $target, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Memoized Access Page',
            description: null,
            content: '# Memoized Access Page',
        ));
        app(UpdatePageAccessMode::class)->handle($owner, new UpdatePageAccessModeCommand(
            pageUid: $page->uid,
            accessMode: PageAccessMode::Restricted,
        ));
        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $target->uid,
            role: WorkspaceRole::Editor,
        ));

        $access = app(PageAccess::class);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertSame(WorkspaceRole::Reader, $access->workspaceRole($target, $workspace->uid));
        $this->assertSame(WorkspaceRole::Reader, $access->workspaceRole($target, $workspace->uid));

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(1, $this->countQueriesContaining($queries, 'from "workspace_memberships"'));

        $this->app->forgetScopedInstances();
        $access = app(PageAccess::class);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertTrue($access->canEdit($target, $page->refresh()));
        $this->assertTrue($access->canEdit($target, $page->refresh()));

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(1, $this->countQueriesContaining($queries, 'from "page_access_grants"'));
        $this->assertSame(2, $this->countQueriesContaining($queries, 'from "workspace_memberships"'));
    }

    public function test_page_access_is_scoped_within_the_current_container_lifecycle(): void
    {
        $first = app(PageAccess::class);

        $this->assertSame($first, app(PageAccess::class));

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, app(PageAccess::class));
    }

    public function test_explicit_page_admin_can_manage_access_but_page_editor_cannot(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $pageAdmin = $this->createUser('Page Admin', 'page-admin@example.test');
        $pageEditor = $this->createUser('Page Editor', 'page-editor@example.test');
        $target = $this->createUser('Target User', 'target@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Security Team');
        $this->addMember($workspace->uid, $pageAdmin, WorkspaceRole::Reader);
        $this->addMember($workspace->uid, $pageEditor, WorkspaceRole::Reader);
        // Elevated page roles require the target to belong to the page workspace.
        $this->addMember($workspace->uid, $target, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Delegated Administration',
            description: null,
            content: '# Delegated Administration',
        ));
        app(UpdatePageAccessMode::class)->handle($owner, new UpdatePageAccessModeCommand(
            pageUid: $page->uid,
            accessMode: PageAccessMode::Restricted,
        ));
        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $pageAdmin->uid,
            role: WorkspaceRole::Admin,
        ));
        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $pageEditor->uid,
            role: WorkspaceRole::Editor,
        ));
        $workspace->forceFill(['allow_editor_page_sharing' => false])->save();
        $access = app(PageAccess::class);

        $this->assertTrue($access->canManageAccess($pageAdmin, $page));
        $this->assertFalse($access->canManageAccess($pageEditor, $page));
        $this->assertTrue($access->canHardDelete($pageAdmin, $page));
        $this->assertFalse($access->canHardDelete($pageEditor, $page));

        $this->actingAs($pageAdmin)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Update access mode')
            ->assertSee('Grant user access');

        $this->actingAs($pageAdmin)
            ->post("/pages/{$page->uid}/access", [
                'subject_type' => 'user',
                'user_email' => $target->email,
                'role' => 'reader',
            ])
            ->assertRedirect("/pages/{$page->uid}");

        $targetGrant = PageAccessGrant::query()
            ->where('page_uid', $page->uid)
            ->where('subject_type', PageAccessSubjectType::User)
            ->where('subject_uid', $target->uid)
            ->sole();

        $this->actingAs($pageAdmin)
            ->delete("/pages/{$page->uid}/access/{$targetGrant->uid}")
            ->assertRedirect("/pages/{$page->uid}");

        $this->actingAs($pageEditor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertDontSee('Update access mode')
            ->assertDontSee('Grant user access');

        $this->actingAs($pageEditor)
            ->post("/pages/{$page->uid}/access", [
                'subject_type' => 'user',
                'user_email' => $target->email,
                'role' => 'reader',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('page_access_grants', ['uid' => $targetGrant->uid]);
    }

    public function test_workspace_setting_can_restrict_page_sharing_to_admins_and_explicit_page_admins(): void
    {
        Storage::fake('artifacts');

        $workspaceAdmin = $this->createUser('Workspace Admin', 'admin@example.test');
        $owner = $this->createUser('Page Owner', 'owner@example.test');
        $workspaceEditor = $this->createUser('Workspace Editor', 'workspace-editor@example.test');
        $pageAdmin = $this->createUser('Page Admin', 'page-admin@example.test');
        $pageEditor = $this->createUser('Page Editor', 'page-editor@example.test');
        $target = $this->createUser('Target User', 'target@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($workspaceAdmin, 'Platform Team');
        $this->addMember($workspace->uid, $owner, WorkspaceRole::Editor);
        $this->addMember($workspace->uid, $workspaceEditor, WorkspaceRole::Editor);
        $this->addMember($workspace->uid, $pageAdmin, WorkspaceRole::Reader);
        $this->addMember($workspace->uid, $pageEditor, WorkspaceRole::Reader);
        // Elevated page roles require the target to belong to the page workspace.
        $this->addMember($workspace->uid, $target, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Restricted Sharing',
            description: null,
            content: '# Restricted Sharing',
        ));
        app(GrantPageAccess::class)->handle($workspaceAdmin, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $pageAdmin->uid,
            role: WorkspaceRole::Admin,
        ));
        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $pageEditor->uid,
            role: WorkspaceRole::Editor,
        ));
        $access = app(PageAccess::class);

        $this->assertTrue($access->canManageAccess($owner, $page));
        $this->assertFalse($access->canManageAccess($workspaceEditor, $page));
        $this->assertFalse($access->canManageAccess($pageEditor, $page));

        $workspace->forceFill(['allow_editor_page_sharing' => false])->save();
        $access->flushCache();

        $this->assertFalse($access->canManageAccess($owner, $page));
        $this->assertFalse($access->canManageAccess($workspaceEditor, $page));
        $this->assertFalse($access->canManageAccess($pageEditor, $page));
        $this->assertTrue($access->canManageAccess($workspaceAdmin, $page));
        $this->assertTrue($access->canManageAccess($pageAdmin, $page));

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk();

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/access", [
                'subject_type' => PageAccessSubjectType::User->value,
                'user_email' => $target->email,
                'role' => WorkspaceRole::Reader->value,
            ])
            ->assertForbidden();

        $this->actingAs($pageAdmin)
            ->post("/pages/{$page->uid}/access", [
                'subject_type' => PageAccessSubjectType::User->value,
                'user_email' => $target->email,
                'role' => WorkspaceRole::Reader->value,
            ])
            ->assertRedirect("/pages/{$page->uid}");

        $this->assertSame(1, PageAccessGrant::query()
            ->where('page_uid', $page->uid)
            ->where('subject_uid', $target->uid)
            ->count());
    }

    public function test_unknown_email_is_neutral_while_any_registered_coworker_can_receive_a_grant(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'oracle-owner@example.test');
        $coworker = $this->createUser('Coworker User', 'oracle-coworker@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Oracle Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Oracle Page',
            description: null,
            content: '# Oracle Page',
        ));

        $unknown = $this->actingAs($owner)
            ->post("/pages/{$page->uid}/access", [
                'subject_type' => PageAccessSubjectType::User->value,
                'user_email' => 'missing-oracle@example.test',
                'role' => WorkspaceRole::Reader->value,
            ])
            ->assertRedirect("/pages/{$page->uid}")
            ->assertSessionHas('status', self::GRANT_STATUS_FLASH)
            ->assertSessionDoesntHaveErrors();

        $coworkerResponse = $this->actingAs($owner)
            ->post("/pages/{$page->uid}/access", [
                'subject_type' => PageAccessSubjectType::User->value,
                'user_email' => $coworker->email,
                'role' => WorkspaceRole::Reader->value,
            ])
            ->assertRedirect("/pages/{$page->uid}")
            ->assertSessionHas('status', self::GRANT_STATUS_FLASH)
            ->assertSessionDoesntHaveErrors();

        $this->assertSame($unknown->getStatusCode(), $coworkerResponse->getStatusCode());

        $this->assertSame(1, PageAccessGrant::query()
            ->where('page_uid', $page->uid)
            ->where('subject_uid', $coworker->uid)
            ->count());
    }

    public function test_restricted_page_is_hidden_from_workspace_members_until_explicitly_granted(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Security Team');
        $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Restricted Search Result',
            description: null,
            content: '# Restricted Search Result',
        ));

        app(UpdatePageAccessMode::class)->handle($owner, new UpdatePageAccessModeCommand(
            pageUid: $page->uid,
            accessMode: PageAccessMode::Restricted,
        ));

        $this->actingAs($reader)
            ->get("/pages/{$page->uid}")
            ->assertNotFound();

        $this->actingAs($reader)
            ->get("/pages?workspace_uid={$workspace->uid}&q=Restricted%20Search")
            ->assertOk()
            ->assertDontSee('Restricted Search Result');

        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $reader->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->actingAs($reader)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Restricted Search Result');

        $this->actingAs($reader)
            ->get("/pages?workspace_uid={$workspace->uid}&q=Restricted%20Search")
            ->assertOk()
            ->assertSee('Restricted Search Result');
    }

    public function test_system_admin_content_access_requires_a_workspace_or_page_grant(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $sharedReader = $this->createUser('Shared Reader', 'shared-reader@example.test');
        $systemAdmin = $this->createUser('System Admin', 'system-admin@example.test');
        $systemAdmin->forceFill([
            'is_system_admin' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [Hash::make('ABCD2-EFGH3')],
        ])->save();
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Security Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($sharedReader, 'Operations Team');
        $this->addMember($targetWorkspace->uid, $owner, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Workspace Granted Page',
            description: null,
            content: '# Workspace Granted Page',
        ));

        app(UpdatePageAccessMode::class)->handle($owner, new UpdatePageAccessModeCommand(
            pageUid: $page->uid,
            accessMode: PageAccessMode::Restricted,
        ));
        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::Workspace,
            subjectUid: $targetWorkspace->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->assertTrue(app(PageAccess::class)->canView($sharedReader, $page->refresh()));

        $this->actingAs($sharedReader)
            ->get("/pages/{$page->uid}")
            ->assertOk();

        $this->assertFalse(app(PageAccess::class)->canView($systemAdmin, $page->refresh()));
        $this->actingAs($systemAdmin)
            ->get("/pages/{$page->uid}")
            ->assertNotFound();
        $this->actingAs($systemAdmin)
            ->get('/pages?workspace_uid=all&q=Workspace%20Granted')
            ->assertOk()
            ->assertDontSee('Security Team')
            ->assertDontSee('Workspace Granted Page');

        $this->addMember($targetWorkspace->uid, $systemAdmin, WorkspaceRole::Reader);
        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $systemAdmin->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->actingAs($systemAdmin)
            ->get('/pages?workspace_uid=all&q=Workspace%20Granted')
            ->assertOk()
            ->assertSee('Security Team')
            ->assertSee('Page access')
            ->assertSee('Workspace Granted Page');
    }

    public function test_owner_can_revoke_page_access_with_traceability_and_idempotency(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $target = $this->createUser('Target User', 'target@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Security Team');
        // The grant target stays outside the page workspace, so the page grant is
        // their only path to this page and revoking it removes access.
        $sharedWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Shared Team');
        $this->addMember($sharedWorkspace->uid, $target, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Revocable Page',
            description: null,
            content: '# Revocable Page',
        ));
        $grant = app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $target->uid,
            role: WorkspaceRole::Reader,
        ));
        $command = new RevokePageAccessCommand(
            pageUid: $page->uid,
            subjectType: $grant->subject_type,
            subjectUid: $grant->subject_uid,
        );

        $this->assertTrue(app(PageAccess::class)->canView($target, $page));
        $this->assertTrue(app(RevokePageAccess::class)->handle($owner, $command));
        $this->assertFalse(app(PageAccess::class)->canView($target, $page));
        $this->assertSame(0, PageAccessGrant::query()->where('uid', $grant->uid)->count());

        $event = DomainEvent::query()
            ->where('event_type', 'page.access_grant.revoked')
            ->sole();
        $this->assertSame($grant->uid, $event->payload['page_access_grant_uid']);
        $this->assertSame($target->uid, $event->payload['subject_uid']);
        $this->assertSame(WorkspaceRole::Reader->value, $event->payload['role']);
        $this->assertSame($owner->uid, $event->payload['revoked_by_user_uid']);

        $auditEntry = AuditEntry::query()
            ->where('action', 'page.access_grant.revoked')
            ->sole();
        $this->assertSame($event->uid, $auditEntry->event_uid);
        $this->assertSame($grant->uid, $auditEntry->auditable_uid);
        $this->assertArrayNotHasKey('page_access_grant_uid', $auditEntry->metadata);
        $this->assertArrayNotHasKey('revoked_by_user_uid', $auditEntry->metadata);

        $this->assertFalse(app(RevokePageAccess::class)->handle($owner, $command));
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.access_grant.revoked')->count());
    }

    public function test_system_admin_with_reader_page_access_cannot_revoke_their_own_grant(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner-self-revoke@example.test');
        $systemAdmin = $this->createUser('System Admin', 'admin-self-revoke@example.test');
        $systemAdmin->forceFill([
            'is_system_admin' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [Hash::make('ABCD2-EFGH3')],
        ])->save();
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Page Workspace');
        $sharedWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Shared Team');
        $this->addMember($sharedWorkspace->uid, $systemAdmin, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Granted Admin Page',
            description: null,
            content: '# Globally Visible Admin Page',
        ));
        $grant = app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $systemAdmin->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->actingAs($systemAdmin)
            ->delete("/pages/{$page->uid}/access/{$grant->uid}")
            ->assertForbidden();

        $this->assertDatabaseHas('page_access_grants', ['uid' => $grant->uid]);
        $this->actingAs($systemAdmin)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Granted Admin Page')
            ->assertDontSee('Grant user access');
    }

    public function test_editor_owner_with_sharing_cannot_revoke_page_admin_grants(): void
    {
        Storage::fake('artifacts');

        $workspaceAdmin = $this->createUser('Workspace Admin', 'workspace-admin@example.test');
        $owner = $this->createUser('Owner User', 'owner@example.test');
        $pageAdmin = $this->createUser('Page Admin', 'page-admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($workspaceAdmin, 'Security Team');
        $workspace->forceFill(['allow_editor_page_sharing' => true])->save();
        $this->addMember($workspace->uid, $owner, WorkspaceRole::Editor);
        $this->addMember($workspace->uid, $pageAdmin, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Admin Revoke Boundary',
            description: null,
            content: '# Admin Revoke Boundary',
        ));
        $adminGrant = app(GrantPageAccess::class)->handle($workspaceAdmin, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $pageAdmin->uid,
            role: WorkspaceRole::Admin,
        ));

        $this->assertTrue(app(PageAccess::class)->canManageAccess($owner, $page));
        $this->assertFalse(app(PageAccess::class)->canHardDelete($owner, $page));

        try {
            app(RevokePageAccess::class)->handle($owner, new RevokePageAccessCommand(
                pageUid: $page->uid,
                subjectType: $adminGrant->subject_type,
                subjectUid: $adminGrant->subject_uid,
            ));
            $this->fail('Expected delegated editor to be unable to revoke page Admin grants.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Editors cannot revoke page Admin access.', $exception->getMessage());
        }

        $this->assertDatabaseHas('page_access_grants', ['uid' => $adminGrant->uid]);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.access_grant.revoked')->count());
        $this->assertTrue(app(RevokePageAccess::class)->handle($workspaceAdmin, new RevokePageAccessCommand(
            pageUid: $page->uid,
            subjectType: $adminGrant->subject_type,
            subjectUid: $adminGrant->subject_uid,
        )));
        $this->assertDatabaseMissing('page_access_grants', ['uid' => $adminGrant->uid]);
    }

    public function test_http_access_controls_validate_authorize_restrict_and_revoke(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $target = $this->createUser('Target User', 'target@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Security Team');
        $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);
        // The target is a page-workspace member and can receive elevated roles.
        $this->addMember($workspace->uid, $target, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'HTTP Access Page',
            description: null,
            content: '# HTTP Access Page',
        ));
        $grant = app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $target->uid,
            role: WorkspaceRole::Reader,
        ));
        $otherPage = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Other HTTP Access Page',
            description: null,
            content: '# Other HTTP Access Page',
        ));
        $otherGrant = app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $otherPage->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $target->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Workspace inheritance')
            ->assertSee('Restrict to explicit access')
            ->assertSee('Target User')
            ->assertSee('target@example.test')
            ->assertDontSee($target->uid)
            ->assertSee("pages/{$page->uid}/access/{$grant->uid}", false);

        $this->actingAs($reader)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertDontSee('Target User')
            ->assertDontSee('target@example.test')
            ->assertDontSee($target->uid)
            ->assertDontSee("pages/{$page->uid}/access/{$grant->uid}", false);

        $this->actingAs($owner)
            ->put("/pages/{$page->uid}/access-mode", ['access_mode' => 'invalid'])
            ->assertSessionHasErrors('access_mode');

        $this->actingAs($reader)
            ->put("/pages/{$page->uid}/access-mode", ['access_mode' => 'restricted'])
            ->assertForbidden();

        $this->actingAs($owner)
            ->put("/pages/{$page->uid}/access-mode", ['access_mode' => 'restricted'])
            ->assertRedirect("/pages/{$page->uid}");

        $this->actingAs($reader)
            ->get("/pages/{$page->uid}")
            ->assertNotFound();

        $this->actingAs($reader)
            ->delete("/pages/{$page->uid}/access/{$grant->uid}")
            ->assertNotFound();

        $this->actingAs($owner)
            ->delete("/pages/{$page->uid}/access/{$otherGrant->uid}")
            ->assertNotFound();

        $this->assertSame(1, PageAccessGrant::query()->where('uid', $otherGrant->uid)->count());

        $this->actingAs($owner)
            ->delete("/pages/{$page->uid}/access/{$grant->uid}")
            ->assertRedirect("/pages/{$page->uid}");

        $this->assertSame(0, PageAccessGrant::query()->where('uid', $grant->uid)->count());
    }

    public function test_workspace_uid_cache_is_not_shared_across_authority_switches_on_one_instance(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $pageWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Page Home Team');
        $grantingWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Granting Team');
        $this->addMember($grantingWorkspace->uid, $member, WorkspaceRole::Reader);

        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $pageWorkspace->uid,
            type: PageType::Markdown,
            title: 'Shared Via Workspace Grant',
            description: null,
            content: '# Shared Via Workspace Grant',
        ));
        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::Workspace,
            subjectUid: $grantingWorkspace->uid,
            role: WorkspaceRole::Reader,
        ));

        $member->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [Hash::make('ABCD2-EFGH3')],
        ])->save();
        $token = app(McpAccessTokenIssuer::class)->issue(
            principal: $member,
            name: 'Scoped token',
            scopes: ['mcp:read'],
            expiresAt: now()->addHour(),
            workspaceUids: [$pageWorkspace->uid],
        );

        // Same scoped PageAccess instance serves an MCP request and later
        // browser-authority work: per-authority caches must never bleed over.
        $access = app(PageAccess::class);
        $context = app(McpRequestContext::class);

        $context->activate($token->accessToken, null);
        $this->assertFalse(
            $access->canView($member, $page),
            'The granting workspace sits outside the token scope, so MCP must not see the page.',
        );

        $context->clear();
        $this->assertTrue(
            $access->canView($member, $page),
            'Browser authority must not inherit the MCP-filtered workspace list from the shared cache.',
        );
    }

    /**
     * @param array<int|string, array{query: string, bindings: array<int|string, mixed>, time: float|null}> $queries
     */
    private function countQueriesContaining(array $queries, string $needle): int
    {
        return count(array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['query'], $needle),
        ));
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
