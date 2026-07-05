<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\HardDeletePage;
use App\Application\PageCatalog\HardDeletePageCommand;
use App\Application\PageCatalog\UpdatePageMetadata;
use App\Application\PageCatalog\UpdatePageMetadataCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Guards the page-row lock ordering that keeps concurrent reparents and hard
 * deletes from deadlocking (SQLSTATE 40P01 -> 500).
 */
final class PageLockOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_reparenting_locks_both_page_rows_in_ascending_uid_order(): void
    {
        $owner = $this->createUser('Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Team');
        $pageA = $this->page($owner, $workspace->uid, 'Page A');
        $pageB = $this->page($owner, $workspace->uid, 'Page B');

        // Deterministic order = ascending uid, regardless of which is self/parent.
        $expectedOrder = [$pageA->uid, $pageB->uid];
        sort($expectedOrder);

        $lockedUids = [];
        DB::listen(function (QueryExecuted $query) use (&$lockedUids, $pageA, $pageB): void {
            $sql = strtolower($query->sql);

            if (!str_contains($sql, 'for update') || !str_contains($sql, '"pages"')) {
                return;
            }

            foreach ($query->bindings as $binding) {
                if ($binding === $pageA->uid || $binding === $pageB->uid) {
                    $lockedUids[] = $binding;
                }
            }
        });

        app(UpdatePageMetadata::class)->handle($owner, $this->reparentCommand($pageA, $owner, $pageB->uid));

        $this->assertSame($expectedOrder, array_values(array_unique($lockedUids)));
        $this->assertSame($pageB->uid, $pageA->refresh()->parent_page_uid);
    }

    public function test_reparenting_a_page_under_its_own_descendant_is_rejected_as_a_cycle(): void
    {
        $owner = $this->createUser('Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Team');
        $ancestor = $this->page($owner, $workspace->uid, 'Ancestor');
        $descendant = $this->page($owner, $workspace->uid, 'Descendant');

        app(UpdatePageMetadata::class)->handle($owner, $this->reparentCommand($descendant, $owner, $ancestor->uid));

        // Reparenting the ancestor under its descendant would form a cycle; the check
        // now runs under the {self, new-parent} locks and must still reject it.
        $rejected = false;

        try {
            app(UpdatePageMetadata::class)->handle($owner, $this->reparentCommand($ancestor, $owner, $descendant->uid));
        } catch (DomainRuleViolation $exception) {
            $rejected = true;
            $this->assertSame('A page cannot be its own parent or descendant.', $exception->getMessage());
        }

        $this->assertTrue($rejected);
        $this->assertNull($ancestor->refresh()->parent_page_uid);
    }

    public function test_reparenting_locks_the_workspace_after_the_page_rows(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Team');
        $pageA = $this->page($owner, $workspace->uid, 'Page A');
        $pageB = $this->page($owner, $workspace->uid, 'Page B');

        $lockedTables = $this->captureLockedTables(function () use ($owner, $pageA, $pageB): void {
            app(UpdatePageMetadata::class)->handle($owner, $this->reparentCommand($pageA, $owner, $pageB->uid));
        });

        // Two reparents on disjoint pairs (A under B while C under D) share no page
        // lock, and the ancestor walk reads without FOR UPDATE. Under READ COMMITTED
        // both then validate a clean chain and commit A->B->C->D->A between them, so
        // the workspace row is the only thing that can serialise them.
        $this->assertContains('workspaces', $lockedTables);

        $pageLockIndexes = array_keys($lockedTables, 'pages', true);
        $workspaceLockIndex = array_search('workspaces', $lockedTables, true);

        if ($pageLockIndexes === [] || !is_int($workspaceLockIndex)) {
            self::fail('Expected both the page rows and the workspace row to be locked FOR UPDATE.');
        }

        // Pages first, workspace second -- the order CreatePage already takes. Locking
        // the workspace first would invert it and deadlock against a concurrent create.
        $this->assertGreaterThan(max($pageLockIndexes), $workspaceLockIndex);
    }

    public function test_clearing_a_parent_takes_no_workspace_lock(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Team');
        $parent = $this->page($owner, $workspace->uid, 'Parent');
        $child = $this->page($owner, $workspace->uid, 'Child');
        app(UpdatePageMetadata::class)->handle($owner, $this->reparentCommand($child, $owner, $parent->uid));

        $lockedTables = $this->captureLockedTables(function () use ($owner, $child): void {
            app(UpdatePageMetadata::class)->handle($owner, $this->reparentCommand($child, $owner, null));
        });

        // Detaching only removes an edge, which cannot close a cycle. Taking the
        // workspace lock here would serialise every rename in the workspace for nothing.
        $this->assertNotContains('workspaces', $lockedTables);
        $this->assertNull($child->refresh()->parent_page_uid);
    }

    /**
     * @return list<string>
     */
    private function captureLockedTables(callable $operation): array
    {
        $lockedTables = [];

        DB::listen(function (QueryExecuted $query) use (&$lockedTables): void {
            $sql = strtolower($query->sql);

            if (!str_contains($sql, 'for update')) {
                return;
            }

            foreach (['pages', 'workspaces'] as $table) {
                if (str_contains($sql, '"' . $table . '"')) {
                    $lockedTables[] = $table;

                    return;
                }
            }
        });

        $operation();

        return $lockedTables;
    }

    public function test_hard_deleting_a_page_with_children_is_rejected(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Team');
        $parent = $this->page($owner, $workspace->uid, 'Parent');
        $child = $this->page($owner, $workspace->uid, 'Child');
        $child->forceFill(['parent_page_uid' => $parent->uid])->save();

        $rejected = false;

        try {
            app(HardDeletePage::class)->handle($owner, new HardDeletePageCommand($parent->uid, $parent->title));
        } catch (DomainRuleViolation $exception) {
            $rejected = true;
            $this->assertSame(
                'Delete or detach child pages before permanently deleting this page.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue($rejected);
        $this->assertTrue(Page::query()->whereKey($parent->uid)->exists());
        $this->assertTrue(Page::query()->whereKey($child->uid)->exists());
    }

    public function test_hard_deleting_a_childless_page_still_succeeds(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Team');
        $page = $this->page($owner, $workspace->uid, 'Solo');

        app(HardDeletePage::class)->handle($owner, new HardDeletePageCommand($page->uid, $page->title));

        $this->assertFalse(Page::query()->whereKey($page->uid)->exists());
    }

    public function test_hard_delete_snapshots_version_blobs_under_the_page_lock(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Team');
        $page = $this->page($owner, $workspace->uid, 'Doomed');

        // The blob cleanup list must be read AFTER the page row is locked. The append
        // path locks the same row first, so a version that commits just before the delete
        // acquires the lock is only captured if the snapshot runs under the lock; reading
        // it earlier (as this once did) would orphan that version's private blob.
        $events = [];
        DB::listen(function (QueryExecuted $query) use (&$events, $page): void {
            $sql = strtolower($query->sql);

            if (!in_array($page->uid, $query->bindings, true)) {
                return;
            }

            if (str_contains($sql, 'for update') && str_contains($sql, '"pages"')) {
                $events[] = 'lock';
            } elseif (str_contains($sql, 'select') && str_contains($sql, '"page_versions"')) {
                $events[] = 'versions';
            }
        });

        app(HardDeletePage::class)->handle($owner, new HardDeletePageCommand($page->uid, $page->title));

        $lockIndex = array_search('lock', $events, true);
        $versionsIndex = array_search('versions', $events, true);

        $this->assertNotFalse($lockIndex, 'The page row must be locked FOR UPDATE.');
        $this->assertNotFalse($versionsIndex, 'The version blobs must be read for cleanup.');
        $this->assertLessThan(
            $versionsIndex,
            $lockIndex,
            'The page must be locked before any version read, so the blob snapshot cannot miss a concurrently-appended version.',
        );
    }

    private function reparentCommand(Page $page, User $owner, ?string $parentPageUid): UpdatePageMetadataCommand
    {
        return new UpdatePageMetadataCommand(
            pageUid: $page->uid,
            title: $page->title,
            description: null,
            categoryUid: null,
            parentPageUid: $parentPageUid,
            ownerUserUid: $owner->uid,
            tagNames: [],
        );
    }

    private function page(User $owner, string $workspaceUid, string $title): Page
    {
        return app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspaceUid,
            type: PageType::Markdown,
            title: $title,
            description: null,
            content: '# ' . $title,
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
}
