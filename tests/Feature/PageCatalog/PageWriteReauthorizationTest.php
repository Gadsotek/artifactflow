<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreateCategory;
use App\Application\PageCatalog\CreateCategoryCommand;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\CreateTag;
use App\Application\PageCatalog\CreateTagCommand;
use App\Application\PageCatalog\HardDeletePage;
use App\Application\PageCatalog\HardDeletePageCommand;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Application\PageCatalog\UpdatePageMetadata;
use App\Application\PageCatalog\UpdatePageMetadataCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageType;
use App\Models\AuditEntry;
use App\Models\Category;
use App\Models\DomainEvent;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\PageVersion;
use App\Models\Tag;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Locked create, update, and hard-delete handlers authorize optimistically before
 * their transaction and again from fresh state at the coordinating row lock. These
 * tests pin the second check: the pre-lock authority is served from PageAccess's
 * request-scoped cache, so a revocation that lands after that read (modelled here by
 * mutating authority at the lock boundary) must still be caught under the lock.
 */
final class PageWriteReauthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_creation_is_refused_when_editor_access_is_revoked_before_the_workspace_lock(): void
    {
        $admin = app(CreateUser::class)->handle('Admin', 'category-reauth-admin@example.test', 'correct horse battery staple');
        $editor = app(CreateUser::class)->handle('Editor', 'category-reauth-editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);

        $this->downgradeMembershipWhenWorkspaceLocks($workspace->uid, $membership->uid);

        $threw = false;

        try {
            app(CreateCategory::class)->handle($editor, new CreateCategoryCommand(
                workspaceUid: $workspace->uid,
                name: 'Revoked Category',
            ));
        } catch (AuthorizationException $exception) {
            $threw = true;
            $this->assertSame('You cannot create categories in this workspace.', $exception->getMessage());
        }

        $this->assertTrue($threw, 'Category creation must reauthorize after taking the workspace lock.');
        $this->assertSame(0, Category::query()->where('workspace_uid', $workspace->uid)->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'category.created')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'category.created')->count());
    }

    public function test_standalone_tag_creation_is_refused_when_cached_editor_access_has_been_revoked(): void
    {
        $admin = app(CreateUser::class)->handle('Admin', 'tag-reauth-admin@example.test', 'correct horse battery staple');
        $editor = app(CreateUser::class)->handle('Editor', 'tag-reauth-editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);

        $this->assertTrue(app(PageAccess::class)->canCreateInWorkspace($editor, $workspace->uid));
        $membership->forceFill(['role' => WorkspaceRole::Reader])->save();

        $threw = false;

        try {
            app(CreateTag::class)->handle($editor, new CreateTagCommand(
                workspaceUid: $workspace->uid,
                name: 'Revoked Tag',
            ));
        } catch (AuthorizationException $exception) {
            $threw = true;
            $this->assertSame('You cannot create pages in this workspace.', $exception->getMessage());
        }

        $this->assertTrue($threw, 'Standalone tag creation must discard cached workspace authority under its lock.');
        $this->assertSame(0, Tag::query()->where('slug', 'revoked-tag')->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'tag.created')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'tag.created')->count());
    }

    public function test_page_creation_refuses_a_parent_grant_revoked_before_the_parent_lock(): void
    {
        Storage::fake('artifacts');

        $admin = app(CreateUser::class)->handle('Admin', 'parent-reauth-admin@example.test', 'correct horse battery staple');
        $editor = app(CreateUser::class)->handle('Editor', 'parent-reauth-editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);
        $parent = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Restricted Parent',
            description: null,
            content: '# Parent',
        ));
        $parent->forceFill(['access_mode' => PageAccessMode::Restricted])->save();
        $grant = PageAccessGrant::query()->forceCreate([
            'page_uid' => $parent->uid,
            'subject_type' => PageAccessSubjectType::User,
            'subject_uid' => $editor->uid,
            'role' => WorkspaceRole::Reader,
            'granted_by_user_uid' => $admin->uid,
        ]);
        $eventCount = DomainEvent::query()->count();
        $auditCount = AuditEntry::query()->count();
        $storedFiles = Storage::disk('artifacts')->allFiles();

        $this->revokeGrantWhenPageLocks($parent->uid, $grant->uid);

        $threw = false;

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Markdown,
                title: 'Unauthorized Child',
                description: null,
                content: '# Child',
                parentPageUid: $parent->uid,
            ));
        } catch (DomainRuleViolation $exception) {
            $threw = true;
            $this->assertSame('Parent page must belong to the selected workspace.', $exception->getMessage());
        }

        $this->assertTrue($threw, 'Creation must reauthorize the parent after taking its row lock.');
        $this->assertSame(0, Page::query()->where('title', 'Unauthorized Child')->count());
        $this->assertSame(1, PageVersion::query()->where('page_uid', $parent->uid)->count());
        $this->assertSame($eventCount, DomainEvent::query()->count());
        $this->assertSame($auditCount, AuditEntry::query()->count());
        $this->assertSame($storedFiles, Storage::disk('artifacts')->allFiles());
    }

    public function test_a_content_write_is_refused_when_edit_access_was_revoked_after_the_cached_check(): void
    {
        Storage::fake('artifacts');

        $admin = app(CreateUser::class)->handle('Admin', 'reauth-admin@example.test', 'correct horse battery staple');
        $editor = app(CreateUser::class)->handle('Editor', 'reauth-editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);

        $page = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Shared Runbook',
            description: null,
            content: '# Original',
        ));

        // Prime the request-scoped authority cache the way an earlier authorization in
        // the same request would: the editor may edit right now.
        $this->assertTrue(app(PageAccess::class)->canEdit($editor, $page));

        // A concurrent removal has committed; the cache is now stale.
        WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $editor->uid)
            ->delete();

        $threw = false;

        try {
            app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: '# Written after revocation',
                baseVersionUid: $page->current_version_uid,
            ));
        } catch (AuthorizationException $exception) {
            $threw = true;
            $this->assertSame('You cannot edit this page.', $exception->getMessage());
        }

        $this->assertTrue($threw, 'The revoked editor must be refused under the lock.');
        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame('# Original', Storage::disk('artifacts')->get(
            PageVersion::query()->findOrFail($page->refresh()->current_version_uid)->content_storage_path,
        ));
    }

    public function test_a_hard_delete_is_refused_when_admin_access_was_revoked_after_the_cached_check(): void
    {
        Storage::fake('artifacts');

        $owner = app(CreateUser::class)->handle('Owner', 'reauth-owner@example.test', 'correct horse battery staple');
        $secondAdmin = app(CreateUser::class)->handle('Second Admin', 'reauth-second-admin@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $membership = $this->addMember($workspace->uid, $secondAdmin, WorkspaceRole::Admin);

        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Doomed Page',
            description: null,
            content: '# Doomed',
        ));

        // The second admin may hard-delete right now; prime the cache with that read.
        $this->assertTrue(app(PageAccess::class)->canHardDelete($secondAdmin, $page));

        // Their admin role has since been reduced to reader (a committed role change);
        // the cached admin authority is now stale.
        $membership->forceFill(['role' => WorkspaceRole::Reader])->save();

        $threw = false;

        try {
            app(HardDeletePage::class)->handle($secondAdmin, new HardDeletePageCommand($page->uid, $page->title));
        } catch (AuthorizationException $exception) {
            $threw = true;
            $this->assertSame('You cannot permanently delete this page.', $exception->getMessage());
        }

        $this->assertTrue($threw, 'The demoted admin must be refused under the lock.');
        $this->assertTrue(Page::query()->whereKey($page->uid)->exists(), 'The page must survive the refused delete.');
    }

    public function test_page_creation_refuses_an_owner_downgraded_between_the_pre_check_and_the_workspace_lock(): void
    {
        Storage::fake('artifacts');

        $admin = app(CreateUser::class)->handle('Admin', 'owner-race-admin@example.test', 'correct horse battery staple');
        $editor = app(CreateUser::class)->handle('Editor', 'owner-race-editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);

        // The optimistic owner-eligibility check runs before the transaction while the editor
        // is still eligible. Model a concurrent role change committing after that check but
        // before creation takes the workspace lock (F7): downgrade the editor to Reader the
        // first time creation locks the workspace row FOR UPDATE. The authoritative recheck
        // under that lock must then refuse -- no page may commit owned by a Reader.
        $this->downgradeMembershipWhenWorkspaceLocks($workspace->uid, $membership->uid);

        $threw = false;

        try {
            app(CreatePage::class)->handle($admin, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Markdown,
                title: 'Owned By A Soon-To-Be Reader',
                description: null,
                content: '# Body',
                ownerUserUid: $editor->uid,
            ));
        } catch (DomainRuleViolation $exception) {
            $threw = true;
            $this->assertSame('Page owner must be a workspace editor or admin.', $exception->getMessage());
        }

        $this->assertTrue($threw, 'Creation must refuse an owner that lost eligibility under the workspace lock.');
        $this->assertSame(0, Page::query()->where('workspace_uid', $workspace->uid)->count());
    }

    public function test_metadata_owner_reassignment_refuses_an_owner_downgraded_under_the_page_lock(): void
    {
        Storage::fake('artifacts');

        $admin = app(CreateUser::class)->handle('Admin', 'meta-race-admin@example.test', 'correct horse battery staple');
        $editor = app(CreateUser::class)->handle('Editor', 'meta-race-editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);

        $page = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Reassignable Page',
            description: null,
            content: '# Body',
        ));

        // The metadata handler locks the page row before it rechecks owner eligibility; the
        // F6 role-change handler locks every workspace page (including this one) ahead of its
        // membership write, so the two serialise on the page. Model that concurrency by
        // downgrading the editor the moment the metadata handler locks the page: the recheck
        // under the lock must refuse the now-ineligible owner rather than assign the page.
        $this->downgradeMembershipWhenPageLocks($page->uid, $membership->uid);

        $threw = false;

        try {
            app(UpdatePageMetadata::class)->handle($admin, new UpdatePageMetadataCommand(
                pageUid: $page->uid,
                title: 'Reassignable Page',
                description: null,
                categoryUid: null,
                parentPageUid: null,
                ownerUserUid: $editor->uid,
                tagNames: [],
            ));
        } catch (DomainRuleViolation $exception) {
            $threw = true;
            $this->assertSame('Page owner must be a workspace editor or admin.', $exception->getMessage());
        }

        $this->assertTrue($threw, 'Reassignment must refuse an owner that lost eligibility under the page lock.');
        $this->assertSame($admin->uid, $page->refresh()->owner_user_uid, 'Ownership must not transfer to the ineligible member.');
    }

    /**
     * Downgrade the membership to Reader the first time a query locks the given workspace
     * row FOR UPDATE. The update runs on the same connection, inside the handler's
     * transaction, so the handler's post-lock recheck observes it -- a deterministic stand-in
     * for a role change that committed against the workspace lock a moment earlier.
     */
    private function downgradeMembershipWhenWorkspaceLocks(string $workspaceUid, string $membershipUid): void
    {
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired, $workspaceUid, $membershipUid): void {
            if ($fired) {
                return;
            }

            $sql = strtolower($query->sql);
            if (!str_contains($sql, 'for update') || !str_contains($sql, '"workspaces"')) {
                return;
            }

            if (!in_array($workspaceUid, $query->bindings, true)) {
                return;
            }

            $fired = true;
            DB::table('workspace_memberships')
                ->where('uid', $membershipUid)
                ->update(['role' => WorkspaceRole::Reader->value]);
        });
    }

    /**
     * Downgrade the membership to Reader the first time a query locks the given page row
     * FOR UPDATE, mirroring downgradeMembershipWhenWorkspaceLocks for the page-lock path.
     */
    private function downgradeMembershipWhenPageLocks(string $pageUid, string $membershipUid): void
    {
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired, $pageUid, $membershipUid): void {
            if ($fired) {
                return;
            }

            $sql = strtolower($query->sql);
            if (!str_contains($sql, 'for update') || !str_contains($sql, '"pages"')) {
                return;
            }

            if (!in_array($pageUid, $query->bindings, true)) {
                return;
            }

            $fired = true;
            DB::table('workspace_memberships')
                ->where('uid', $membershipUid)
                ->update(['role' => WorkspaceRole::Reader->value]);
        });
    }

    private function revokeGrantWhenPageLocks(string $pageUid, string $grantUid): void
    {
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired, $pageUid, $grantUid): void {
            if ($fired) {
                return;
            }

            $sql = strtolower($query->sql);
            if (!str_contains($sql, 'for update') || !str_contains($sql, '"pages"')) {
                return;
            }

            if (!in_array($pageUid, $query->bindings, true)) {
                return;
            }

            $fired = true;
            DB::table('page_access_grants')->where('uid', $grantUid)->delete();
        });
    }

    private function addMember(string $workspaceUid, User $user, WorkspaceRole $role): WorkspaceMembership
    {
        return WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspaceUid,
            'user_uid' => $user->uid,
            'role' => $role,
            'accepted_at' => now(),
        ]);
    }
}
