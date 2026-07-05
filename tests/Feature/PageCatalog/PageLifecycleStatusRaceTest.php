<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\DeprecatePage;
use App\Application\PageCatalog\DeprecatePageCommand;
use App\Application\PageCatalog\MarkPageApproved;
use App\Application\PageCatalog\MarkPageApprovedCommand;
use App\Domain\PageCatalog\InvalidPageStatusTransition;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lifecycle status handlers check their source-status precondition before the
 * transaction, then transition under a page row lock. These tests pin the
 * authoritative re-check: a concurrent archive that commits after the pre-lock
 * read (modelled by mutating the row after the handler's initial page read) must
 * be caught under the lock, never silently overwritten (e.g. archived -> approved).
 */
final class PageLifecycleStatusRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_approved_refuses_a_page_archived_between_the_check_and_the_lock(): void
    {
        Storage::fake('artifacts');

        [$editor, $page] = $this->draftPage('lifecycle-approve@example.test');

        $this->archivePageAfterItsInitialRead($page->uid);

        $threw = false;

        try {
            app(MarkPageApproved::class)->handle($editor, new MarkPageApprovedCommand($page->uid));
        } catch (InvalidPageStatusTransition $exception) {
            $threw = true;
            $this->assertSame('Only draft pages can be marked approved.', $exception->getMessage());
        }

        $this->assertTrue($threw, 'Approving a concurrently-archived page must be refused under the lock.');
        $this->assertSame(PageStatus::Archived, $page->refresh()->status);
    }

    public function test_deprecate_refuses_a_page_archived_between_the_check_and_the_lock(): void
    {
        Storage::fake('artifacts');

        [$editor, $page] = $this->draftPage('lifecycle-deprecate@example.test');
        app(MarkPageApproved::class)->handle($editor, new MarkPageApprovedCommand($page->uid));
        $this->assertSame(PageStatus::Approved, $page->refresh()->status);

        $this->archivePageAfterItsInitialRead($page->uid);

        $threw = false;

        try {
            app(DeprecatePage::class)->handle($editor, new DeprecatePageCommand($page->uid));
        } catch (InvalidPageStatusTransition $exception) {
            $threw = true;
            $this->assertSame('Only approved pages can be deprecated.', $exception->getMessage());
        }

        $this->assertTrue($threw, 'Deprecating a concurrently-archived page must be refused under the lock.');
        $this->assertSame(PageStatus::Archived, $page->refresh()->status);
    }

    /**
     * Archive the page the first time a query reads it WITHOUT a lock (the handler's
     * pre-transaction read). The update runs on the same connection ahead of the
     * FOR UPDATE lock, so the locked read observes the committed archive -- a
     * deterministic stand-in for an archive that committed against the lock a moment
     * earlier.
     */
    private function archivePageAfterItsInitialRead(string $pageUid): void
    {
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired, $pageUid): void {
            if ($fired) {
                return;
            }

            $sql = strtolower($query->sql);
            if (!str_contains($sql, '"pages"') || str_contains($sql, 'for update')) {
                return;
            }

            if (!in_array($pageUid, $query->bindings, true)) {
                return;
            }

            $fired = true;
            DB::table('pages')->where('uid', $pageUid)->update(['status' => PageStatus::Archived->value]);
        });
    }

    /**
     * @return array{0: User, 1: Page}
     */
    private function draftPage(string $email): array
    {
        $editor = app(CreateUser::class)->handle('Lifecycle User', $email, 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Lifecycle Team ' . $email);
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Lifecycle Page',
            description: null,
            content: '# Body',
        ));

        return [$editor, $page];
    }
}
