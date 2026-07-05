<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\HardDeletePage;
use App\Application\PageCatalog\HardDeletePageCommand;
use App\Application\PageCatalog\MovePageToWorkspace;
use App\Application\PageCatalog\MovePageToWorkspaceCommand;
use App\Application\PageCatalog\PageVersionPruner;
use App\Application\PageCatalog\RestorePageVersion;
use App\Application\PageCatalog\RestorePageVersionCommand;
use App\Application\PageCatalog\RevertToPreviousVersion;
use App\Application\PageCatalog\RevertToPreviousVersionCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class WorkspaceStorageCounterTest extends TestCase
{
    use RefreshDatabase;

    public function test_counter_tracks_page_creation_and_content_updates(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createEditor('counter-create@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Counter Team');

        $this->assertSame(0, $this->usedStorageBytes($workspace->uid));

        $page = $this->createMarkdownPage($editor, $workspace->uid, 'Counter Page', '123456789');

        $this->assertSame(9, $this->usedStorageBytes($workspace->uid));

        app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: 'abcdefghij',
            baseVersionUid: $page->current_version_uid,
        ));

        $this->assertSame(19, $this->usedStorageBytes($workspace->uid));
        $this->assertCounterMatchesVersionSum($workspace->uid);
    }

    public function test_counter_returns_to_zero_after_page_hard_delete(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createEditor('counter-delete@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Delete Team');
        $page = $this->createMarkdownPage($editor, $workspace->uid, 'Doomed Page', '123456789');
        app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: 'abcdefghij',
            baseVersionUid: $page->current_version_uid,
        ));
        $keptPage = $this->createMarkdownPage($editor, $workspace->uid, 'Kept Page', '12345');

        $this->assertSame(24, $this->usedStorageBytes($workspace->uid));

        app(HardDeletePage::class)->handle($editor, new HardDeletePageCommand(
            pageUid: $page->uid,
            confirmation: 'Doomed Page',
        ));

        $this->assertSame(5, $this->usedStorageBytes($workspace->uid));
        $this->assertNotNull(Page::query()->find($keptPage->uid));
        $this->assertCounterMatchesVersionSum($workspace->uid);
    }

    public function test_counter_tracks_restore_and_revert_versions(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createEditor('counter-restore@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Restore Team');
        $page = $this->createMarkdownPage($editor, $workspace->uid, 'Restore Page', '123456789');
        $firstVersionUid = $page->current_version_uid;
        $this->assertNotNull($firstVersionUid);
        app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: 'abcdefghij',
            baseVersionUid: $firstVersionUid,
        ));

        $this->assertSame(19, $this->usedStorageBytes($workspace->uid));

        app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
            pageUid: $page->uid,
            versionUid: $firstVersionUid,
        ));

        $this->assertSame(28, $this->usedStorageBytes($workspace->uid));

        $page->refresh();
        $this->assertNotNull($page->current_version_uid);
        app(RevertToPreviousVersion::class)->handle($editor, new RevertToPreviousVersionCommand(
            pageUid: $page->uid,
            baseVersionUid: $page->current_version_uid,
        ));

        $this->assertSame(38, $this->usedStorageBytes($workspace->uid));
        $this->assertCounterMatchesVersionSum($workspace->uid);
    }

    public function test_counter_transfers_between_workspaces_on_page_move(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createEditor('counter-move@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Move Source');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Move Target');
        $movingPage = $this->createMarkdownPage($admin, $sourceWorkspace->uid, 'Moving Page', '123456789');
        app(UpdatePageContent::class)->handle($admin, new UpdatePageContentCommand(
            pageUid: $movingPage->uid,
            content: 'abcdefghij',
            baseVersionUid: $movingPage->current_version_uid,
        ));
        $this->createMarkdownPage($admin, $sourceWorkspace->uid, 'Staying Page', '12345');
        $this->createMarkdownPage($admin, $targetWorkspace->uid, 'Target Page', '1234567');

        $this->assertSame(24, $this->usedStorageBytes($sourceWorkspace->uid));
        $this->assertSame(7, $this->usedStorageBytes($targetWorkspace->uid));

        app(MovePageToWorkspace::class)->handle($admin, new MovePageToWorkspaceCommand(
            pageUid: $movingPage->uid,
            targetWorkspaceUid: $targetWorkspace->uid,
            targetOwnerUserUid: $admin->uid,
            confirmed: true,
        ));

        $this->assertSame(5, $this->usedStorageBytes($sourceWorkspace->uid));
        $this->assertSame(26, $this->usedStorageBytes($targetWorkspace->uid));
        $this->assertCounterMatchesVersionSum($sourceWorkspace->uid);
        $this->assertCounterMatchesVersionSum($targetWorkspace->uid);
    }

    public function test_pruning_releases_bytes_from_the_pages_live_workspace_not_a_stale_one(): void
    {
        // Regression: PageVersionPruner must resolve the workspace under the page lock,
        // not trust the caller's page instance. If a workspace move commits between the
        // unlocked load and the append lock, releasing bytes against the pre-move
        // workspace decrements a workspace this transaction never locked -- drifting both
        // counters (or tripping the used_storage_bytes >= 0 CHECK).
        Storage::fake('artifacts');
        config(['pages.max_page_versions' => 10]);

        $admin = $this->createEditor('counter-prune-stale@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Prune Source');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Prune Target');

        $page = $this->createMarkdownPage($admin, $sourceWorkspace->uid, 'Pruning Page', '123456789'); // v1 = 9 bytes
        app(UpdatePageContent::class)->handle($admin, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: 'abcdefghij', // v2 = 10 bytes
            baseVersionUid: $page->current_version_uid,
        ));

        // Capture a page instance while it still lives in the source workspace, then
        // move the row to the target workspace out from under it -- exactly the stale
        // instance the append path would carry past a concurrent move.
        $stalePage = Page::query()->findOrFail($page->uid);
        app(MovePageToWorkspace::class)->handle($admin, new MovePageToWorkspaceCommand(
            pageUid: $page->uid,
            targetWorkspaceUid: $targetWorkspace->uid,
            targetOwnerUserUid: $admin->uid,
            confirmed: true,
        ));

        // Baseline both counters so we can see which one the prune releases against.
        Workspace::query()->whereKey($sourceWorkspace->uid)->update(['used_storage_bytes' => 19]);
        Workspace::query()->whereKey($targetWorkspace->uid)->update(['used_storage_bytes' => 19]);

        // Lower the cap so the oldest (9-byte) version is surplus, then prune using the
        // stale page instance, as UpdatePageContent/RestorePageVersion pass it in.
        config(['pages.max_page_versions' => 1]);
        app(PageVersionPruner::class)->pruneToCap($stalePage, $admin->uid);

        // Bytes released from the page's live (target) workspace, not the stale source.
        $this->assertSame(19, $this->usedStorageBytes($sourceWorkspace->uid));
        $this->assertSame(10, $this->usedStorageBytes($targetWorkspace->uid));
    }

    public function test_quota_enforcement_reads_the_maintained_counter(): void
    {
        Storage::fake('artifacts');
        config([
            'pages.max_markdown_bytes' => 100,
            'pages.max_workspace_storage_bytes' => 20,
        ]);

        $editor = $this->createEditor('counter-quota@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Quota Counter Team');
        $page = $this->createMarkdownPage($editor, $workspace->uid, 'Quota Counter Page', '123456789');

        // Drift the counter above the quota; the real SUM (9 bytes) would allow
        // this write, so a rejection proves the counter is what gets enforced.
        Workspace::query()->whereKey($workspace->uid)->update(['used_storage_bytes' => 15]);

        try {
            app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: 'abcdef',
                baseVersionUid: $page->current_version_uid,
            ));
            $this->fail('Expected the workspace storage quota to reject the write.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Workspace page storage quota exceeded.', $exception->getMessage());
        }

        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_recount_storage_command_fixes_a_drifted_counter(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createEditor('counter-recount@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Recount Team');
        $this->createMarkdownPage($editor, $workspace->uid, 'Recount Page', '123456789');

        Workspace::query()->whereKey($workspace->uid)->update(['used_storage_bytes' => 999999]);

        $this->runConsoleCommand('artifactflow:recount-storage')
            ->expectsOutputToContain('corrected=1')
            ->assertExitCode(0);

        $this->assertSame(9, $this->usedStorageBytes($workspace->uid));

        $this->runConsoleCommand('artifactflow:recount-storage')
            ->expectsOutputToContain('corrected=0')
            ->assertExitCode(0);

        $this->assertSame(9, $this->usedStorageBytes($workspace->uid));
    }

    private function createEditor(string $email): User
    {
        return app(CreateUser::class)->handle('Counter User', $email, 'correct horse battery staple');
    }

    private function createMarkdownPage(User $actor, string $workspaceUid, string $title, string $content): Page
    {
        return app(CreatePage::class)->handle($actor, new CreatePageCommand(
            workspaceUid: $workspaceUid,
            type: PageType::Markdown,
            title: $title,
            description: null,
            content: $content,
        ));
    }

    private function usedStorageBytes(string $workspaceUid): int
    {
        $workspace = Workspace::query()->whereKey($workspaceUid)->sole();

        return $workspace->used_storage_bytes;
    }

    private function assertCounterMatchesVersionSum(string $workspaceUid): void
    {
        $actualBytes = (int) PageVersion::query()
            ->join('pages', 'page_versions.page_uid', '=', 'pages.uid')
            ->where('pages.workspace_uid', $workspaceUid)
            ->sum('page_versions.byte_size');

        $this->assertSame($actualBytes, $this->usedStorageBytes($workspaceUid));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runConsoleCommand(string $command, array $parameters = []): PendingCommand
    {
        $pendingCommand = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pendingCommand);

        return $pendingCommand;
    }
}
