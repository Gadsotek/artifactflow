<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\PruneOrphanArtifacts;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class OrphanArtifactPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_aged_orphans_while_sparing_referenced_and_recent_files(): void
    {
        Storage::fake('artifacts');
        $referencedPath = $this->createReferencedArtifact();

        $agedOrphan = 'pages/ghost-old/versions/1-aaaa/index.html';
        $recentOrphan = 'pages/ghost-new/versions/1-bbbb/index.html';
        $this->writeOrphan($agedOrphan, ageHours: 48);
        $this->writeOrphan($recentOrphan, ageHours: 0);

        $result = app(PruneOrphanArtifacts::class)->handle(delete: true, minAgeSeconds: 24 * 3600);

        $this->assertSame(1, $result->orphansFound);
        $this->assertSame(1, $result->orphansDeleted);
        $this->assertSame(1, $result->recentSkipped);
        $this->assertSame([$agedOrphan], $result->sampleOrphanPaths);

        // The in-flight-write safety window and the referenced blob are untouched;
        // only the aged, unreferenced file is gone.
        Storage::disk('artifacts')->assertMissing($agedOrphan);
        Storage::disk('artifacts')->assertExists($recentOrphan);
        Storage::disk('artifacts')->assertExists($referencedPath);
    }

    public function test_report_only_pass_counts_orphans_without_deleting(): void
    {
        Storage::fake('artifacts');
        $this->createReferencedArtifact();
        $agedOrphan = 'pages/ghost/versions/1-cccc/index.html';
        $this->writeOrphan($agedOrphan, ageHours: 48);

        $result = app(PruneOrphanArtifacts::class)->handle(delete: false, minAgeSeconds: 24 * 3600);

        $this->assertSame(1, $result->orphansFound);
        $this->assertSame(0, $result->orphansDeleted);
        $this->assertFalse($result->deleteRequested);
        Storage::disk('artifacts')->assertExists($agedOrphan);
    }

    public function test_command_reports_orphans_and_hints_at_delete_flag(): void
    {
        Storage::fake('artifacts');
        $this->createReferencedArtifact();
        $this->writeOrphan('pages/ghost/versions/1-dddd/index.html', ageHours: 48);

        $this->runConsoleCommand('artifactflow:prune-orphan-artifacts')
            ->expectsOutputToContain('orphans=1, deleted=0')
            ->expectsOutputToContain('Re-run with --delete to remove them.')
            ->assertExitCode(0);

        Storage::disk('artifacts')->assertExists('pages/ghost/versions/1-dddd/index.html');
    }

    public function test_command_rejects_a_non_numeric_min_age(): void
    {
        $this->runConsoleCommand('artifactflow:prune-orphan-artifacts', [
            '--min-age-hours' => 'soon',
        ])
            ->expectsOutputToContain('Minimum age (--min-age-hours) must be a positive whole number of hours.')
            ->assertExitCode(1);
    }

    public function test_command_rejects_a_zero_min_age_to_preserve_the_in_flight_write_window(): void
    {
        // A zero-hour window would let the reaper delete a blob whose version row is
        // still mid-commit -- PageVersionWriter puts the blob before inserting the
        // row -- stranding that committed row on a missing file. The safety window
        // must stay positive, even when an operator also passes --delete.
        Storage::fake('artifacts');

        $this->runConsoleCommand('artifactflow:prune-orphan-artifacts', [
            '--min-age-hours' => 0,
            '--delete' => true,
        ])
            ->expectsOutputToContain('Minimum age (--min-age-hours) must be a positive whole number of hours.')
            ->assertExitCode(1);
    }

    private function createReferencedArtifact(): string
    {
        $editor = app(CreateUser::class)->handle('Orphan Owner', 'orphan-owner@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Orphan Team');
        $page = $this->createMarkdownPage($editor, $workspace->uid, 'Kept Page', 'kept content');

        return PageVersion::query()
            ->where('page_uid', $page->uid)
            ->sole()
            ->content_storage_path;
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

    private function writeOrphan(string $path, int $ageHours): void
    {
        $disk = Storage::disk('artifacts');
        $disk->put($path, 'orphaned bytes');

        if ($ageHours > 0) {
            touch($disk->path($path), Carbon::now()->subHours($ageHours)->getTimestamp());
        }
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
