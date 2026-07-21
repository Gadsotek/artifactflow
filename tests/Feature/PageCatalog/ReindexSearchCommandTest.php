<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class ReindexSearchCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reindex_repopulates_empty_search_text_and_makes_a_stale_page_findable(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Reindex Owner', 'reindex-owner@example.test');
        $page = $this->createHtmlArtifactWithScriptNeedle($owner, 'legacyexenoded');
        $version = $this->currentVersion($page->current_version_uid);
        $this->makeVersionSearchTextStale($version);
        $this->blankSearchVector($page->uid);

        $this->actingAs($owner)
            ->get('/pages?workspace_uid=all&q=legacyexenoded')
            ->assertOk()
            ->assertDontSee($page->title)
            ->assertSee('No pages found');

        $this->runConsoleCommand('artifactflow:reindex-search')
            ->expectsOutput('Search reindex complete: pages=1, versions=1, changed=1, skipped=0, dry_run=no.')
            ->assertExitCode(0);

        $version->refresh();
        $this->assertStringContainsString('Runtime', (string) $version->extracted_text);
        $this->assertStringContainsString('legacyexenoded', (string) $version->source_text);

        $this->actingAs($owner)
            ->get('/pages?workspace_uid=all&q=legacyexenoded')
            ->assertOk()
            ->assertSee($page->title);
    }

    public function test_reindex_is_idempotent(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Idempotent Owner', 'reindex-idempotent@example.test');
        $page = $this->createHtmlArtifactWithScriptNeedle($owner, 'idempotentneedle');
        $version = $this->currentVersion($page->current_version_uid);
        $this->makeVersionSearchTextStale($version);

        $this->runConsoleCommand('artifactflow:reindex-search')
            ->expectsOutput('Search reindex complete: pages=1, versions=1, changed=1, skipped=0, dry_run=no.')
            ->assertExitCode(0);

        $version->refresh();
        $extractedText = $version->extracted_text;
        $sourceText = $version->source_text;

        $this->runConsoleCommand('artifactflow:reindex-search')
            ->expectsOutput('Search reindex complete: pages=1, versions=1, changed=0, skipped=0, dry_run=no.')
            ->assertExitCode(0);

        $version->refresh();
        $this->assertSame($extractedText, $version->extracted_text);
        $this->assertSame($sourceText, $version->source_text);
    }

    public function test_dry_run_writes_nothing(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Dry Run Owner', 'reindex-dry-run@example.test');
        $page = $this->createHtmlArtifactWithScriptNeedle($owner, 'dryrunneedle');
        $version = $this->currentVersion($page->current_version_uid);
        $this->makeVersionSearchTextStale($version);
        $this->blankSearchVector($page->uid);

        $this->runConsoleCommand('artifactflow:reindex-search --dry-run')
            ->expectsOutput('Search reindex complete: pages=1, versions=1, changed=1, skipped=0, dry_run=yes.')
            ->assertExitCode(0);

        $version->refresh();
        $this->assertNull($version->extracted_text);
        $this->assertNull($version->source_text);

        $this->actingAs($owner)
            ->get('/pages?workspace_uid=all&q=dryrunneedle')
            ->assertOk()
            ->assertDontSee($page->title)
            ->assertSee('No pages found');
    }

    public function test_default_reindexes_only_current_version_and_all_versions_reindexes_history(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('History Owner', 'reindex-history@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'History Workspace');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Historical Reindex Page',
            description: null,
            content: '# First Body' . PHP_EOL . PHP_EOL . 'firstneedle',
        ));
        app(UpdatePageContent::class)->handle($owner, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Second Body' . PHP_EOL . PHP_EOL . 'secondneedle',
            baseVersionUid: $page->current_version_uid,
        ));

        $versions = PageVersion::query()
            ->where('page_uid', $page->uid)
            ->orderBy('version_number')
            ->get();
        $firstVersion = $versions->first();
        $currentVersion = $versions->last();
        $this->assertInstanceOf(PageVersion::class, $firstVersion);
        $this->assertInstanceOf(PageVersion::class, $currentVersion);
        $this->makeVersionSearchTextStale($firstVersion);
        $this->makeVersionSearchTextStale($currentVersion);

        $this->runConsoleCommand('artifactflow:reindex-search')
            ->expectsOutput('Search reindex complete: pages=1, versions=1, changed=1, skipped=0, dry_run=no.')
            ->assertExitCode(0);

        $firstVersion->refresh();
        $currentVersion->refresh();
        $this->assertNull($firstVersion->extracted_text);
        $this->assertStringContainsString('Second Body', (string) $currentVersion->extracted_text);

        $this->runConsoleCommand('artifactflow:reindex-search --all-versions')
            ->expectsOutput('Search reindex complete: pages=1, versions=2, changed=1, skipped=0, dry_run=no.')
            ->assertExitCode(0);

        // Historic versions keep only bounded source_text; their extracted_text
        // stays cleared by design because only the current version feeds search.
        $firstVersion->refresh();
        $this->assertNull($firstVersion->extracted_text);
        $this->assertStringContainsString('firstneedle', (string) $firstVersion->source_text);
    }

    public function test_reindex_reloads_and_locks_the_page_before_classifying_current_and_historical_versions(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Concurrent Reindex Owner', 'concurrent-reindex@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Concurrent Reindex Workspace');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Concurrent Reindex Page',
            description: null,
            content: '# Version One' . PHP_EOL . PHP_EOL . 'firstconcurrentneedle',
        ));
        $firstVersionUid = $page->current_version_uid;
        $this->assertNotNull($firstVersionUid);
        $appendedVersionUid = null;
        $appendTriggered = false;
        $retrievedEvent = 'eloquent.retrieved: ' . Page::class;

        Event::listen($retrievedEvent, function (Page $loadedPage) use (
            &$appendTriggered,
            &$appendedVersionUid,
            $owner,
            $page,
        ): void {
            if ($appendTriggered || $loadedPage->uid !== $page->uid) {
                return;
            }

            // The chunk has hydrated V1 as current. Commit V2 before that stale model
            // reaches processPage(), matching a live append during a long reindex.
            $appendTriggered = true;
            $appended = app(UpdatePageContent::class)->handle($owner, new UpdatePageContentCommand(
                pageUid: $loadedPage->uid,
                content: '# Version Two' . PHP_EOL . PHP_EOL . 'secondconcurrentneedle',
                baseVersionUid: $loadedPage->current_version_uid,
            ));
            $appendedVersionUid = $appended->uid;
        });

        try {
            $this->runConsoleCommand("artifactflow:reindex-search --all-versions --page={$page->uid}")
                ->assertExitCode(0);
        } finally {
            Event::forget($retrievedEvent);
        }

        $this->assertTrue($appendTriggered);
        $this->assertIsString($appendedVersionUid);
        $firstVersion = PageVersion::query()->whereKey($firstVersionUid)->sole();
        $currentVersion = PageVersion::query()->whereKey($appendedVersionUid)->sole();

        $this->assertNull($firstVersion->extracted_text);
        $this->assertStringContainsString('secondconcurrentneedle', (string) $currentVersion->extracted_text);
    }

    public function test_page_flag_scopes_reindex_to_a_single_page(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Scoped Owner', 'reindex-scope@example.test');
        $targetPage = $this->createHtmlArtifactWithScriptNeedle($owner, 'targetscopeneedle');
        $otherPage = $this->createHtmlArtifactWithScriptNeedle($owner, 'otherscopeneedle');
        $targetVersion = $this->currentVersion($targetPage->current_version_uid);
        $otherVersion = $this->currentVersion($otherPage->current_version_uid);
        $this->makeVersionSearchTextStale($targetVersion);
        $this->makeVersionSearchTextStale($otherVersion);

        $this->runConsoleCommand("artifactflow:reindex-search --page={$targetPage->uid}")
            ->expectsOutput('Search reindex complete: pages=1, versions=1, changed=1, skipped=0, dry_run=no.')
            ->assertExitCode(0);

        $targetVersion->refresh();
        $otherVersion->refresh();
        $this->assertStringContainsString('targetscopeneedle', (string) $targetVersion->source_text);
        $this->assertNull($otherVersion->source_text);
    }

    public function test_missing_artifact_file_is_skipped_without_failing(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Missing Owner', 'reindex-missing@example.test');
        $missingPage = $this->createHtmlArtifactWithScriptNeedle($owner, 'missingneedle');
        $healthyPage = $this->createHtmlArtifactWithScriptNeedle($owner, 'healthyneedle');
        $missingVersion = $this->currentVersion($missingPage->current_version_uid);
        $healthyVersion = $this->currentVersion($healthyPage->current_version_uid);
        $this->makeVersionSearchTextStale($missingVersion);
        $this->makeVersionSearchTextStale($healthyVersion);
        Storage::disk('artifacts')->delete($missingVersion->content_storage_path);

        $this->runConsoleCommand('artifactflow:reindex-search')
            ->expectsOutput('Search reindex complete: pages=2, versions=2, changed=1, skipped=1, dry_run=no.')
            ->assertExitCode(0);

        $missingVersion->refresh();
        $healthyVersion->refresh();
        $this->assertNull($missingVersion->source_text);
        $this->assertStringContainsString('healthyneedle', (string) $healthyVersion->source_text);
    }

    public function test_command_never_writes_or_deletes_content_files(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Disk Owner', 'reindex-disk@example.test');
        $page = $this->createHtmlArtifactWithScriptNeedle($owner, 'diskneedle');
        $version = $this->currentVersion($page->current_version_uid);
        $this->makeVersionSearchTextStale($version);
        $before = $this->artifactDiskSnapshot();

        $this->runConsoleCommand('artifactflow:reindex-search')
            ->assertExitCode(0);

        $this->assertSame($before, $this->artifactDiskSnapshot());
    }

    public function test_page_option_requires_an_existing_page(): void
    {
        Storage::fake('artifacts');

        $this->runConsoleCommand('artifactflow:reindex-search --page=01K00000000000000000000000')
            ->expectsOutput('Page does not exist.')
            ->assertExitCode(1);
    }

    public function test_batch_size_must_be_positive(): void
    {
        Storage::fake('artifacts');

        $this->runConsoleCommand('artifactflow:reindex-search --batch-size=0')
            ->expectsOutput('Search reindex batch size must be positive.')
            ->assertExitCode(1);
    }

    public function test_command_output_does_not_disclose_private_artifact_content(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Output Owner', 'reindex-output@example.test');
        $page = $this->createHtmlArtifactWithScriptNeedle($owner, 'privateoutputneedle');
        $version = $this->currentVersion($page->current_version_uid);
        $this->makeVersionSearchTextStale($version);

        $this->runConsoleCommand('artifactflow:reindex-search')
            ->doesntExpectOutputToContain('privateoutputneedle')
            ->doesntExpectOutputToContain('<script>')
            ->expectsOutput('Search reindex complete: pages=1, versions=1, changed=1, skipped=0, dry_run=no.')
            ->assertExitCode(0);
    }

    private function createHtmlArtifactWithScriptNeedle(User $owner, string $needle): Page
    {
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Reindex Workspace ' . $needle);

        return app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Reindex Artifact ' . $needle,
            description: null,
            content: sprintf(
                '<!doctype html><html><body><h1>Runtime</h1><script>const token="%s";</script></body></html>',
                $needle,
            ),
        ));
    }

    private function currentVersion(?string $versionUid): PageVersion
    {
        $this->assertNotNull($versionUid);
        $version = PageVersion::query()->whereKey($versionUid)->sole();
        $this->assertInstanceOf(PageVersion::class, $version);

        return $version;
    }

    private function makeVersionSearchTextStale(PageVersion $version): void
    {
        $version->forceFill([
            'extracted_text' => null,
            'source_text' => null,
        ])->save();
    }

    private function blankSearchVector(string $pageUid): void
    {
        DB::table('pages')
            ->where('uid', $pageUid)
            ->update(['search_vector' => DB::raw("''::tsvector")]);
    }

    /**
     * @return array<string, string>
     */
    private function artifactDiskSnapshot(): array
    {
        $snapshot = [];

        foreach (Storage::disk('artifacts')->allFiles() as $path) {
            if (!is_string($path)) {
                continue;
            }

            $content = Storage::disk('artifacts')->get($path);
            $snapshot[$path] = is_string($content) ? $content : '';
        }

        ksort($snapshot);

        return $snapshot;
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function runConsoleCommand(string $command): PendingCommand
    {
        $pendingCommand = $this->artisan($command);
        $this->assertInstanceOf(PendingCommand::class, $pendingCommand);

        return $pendingCommand;
    }
}
