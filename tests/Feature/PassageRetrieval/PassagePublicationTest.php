<?php

declare(strict_types=1);

namespace Tests\Feature\PassageRetrieval;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\RestorePageVersion;
use App\Application\PageCatalog\RestorePageVersionCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Application\PassageRetrieval\PagePassageIndex;
use App\Application\PassageRetrieval\PassageExtractor;
use App\Domain\PageCatalog\PageType;
use App\Models\InstallationSettings;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class PassagePublicationTest extends TestCase
{
    use RefreshDatabase;

    private User $author;
    private string $workspaceUid;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
        $this->author = User::factory()->create();
        $this->workspaceUid = app(CreateSharedWorkspace::class)->handle($this->author, 'Workflow team')->uid;
    }

    public function test_missing_or_default_off_settings_create_no_extracted_copy(): void
    {
        $page = $this->createPage('# Original');
        $this->assertDatabaseCount('passage_sets', 0);
        $settings = $this->createSettings()->refresh();
        self::assertFalse($settings->passage_retrieval_enabled);
        self::assertSame(0, $settings->passage_retrieval_generation);
        self::assertNull($settings->passage_retrieval_extractor_revision);
        $updated = $this->updatePage($page, '# New revision');
        self::assertSame('New revision', $updated->extracted_text);
        $this->assertDatabaseCount('passages', 0);
        $this->assertDatabaseCount('passage_sets', 0);
    }

    public function test_create_edit_and_restore_publish_only_the_current_version_with_a_positional_search_vector(): void
    {
        $this->enable();
        $source = "# VAT\n\nCheck the invoice total.";
        $page = $this->createPage($source);
        $first = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $this->assertDatabaseHas('passage_sets', [
            'page_version_uid' => $first->uid, 'generation' => 1, 'extractor_revision' => 1,
            'source_hash' => hash('sha256', $source), 'outcome' => 'complete',
        ]);
        self::assertSame(1, DB::table('passages')
            ->whereRaw("search_vector @@ websearch_to_tsquery('simple', ?)", ['VAT "invoice total"'])->count());

        $second = $this->updatePage($page, "# Revised\n\nCompare the amount due.");
        $this->assertDatabaseMissing('passage_sets', ['page_version_uid' => $first->uid]);
        $this->assertDatabaseHas('passage_sets', ['page_version_uid' => $second->uid]);
        $restored = app(RestorePageVersion::class)->handle($this->author, new RestorePageVersionCommand(
            $page->uid,
            $first->uid,
            $second->uid,
        ));
        self::assertNotSame($first->uid, $restored->uid);
        $this->assertDatabaseCount('passage_sets', 1);
        $this->assertDatabaseHas('passage_sets', ['page_version_uid' => $restored->uid, 'source_hash' => $first->content_hash]);
        $this->assertDatabaseHas('passages', ['ordinal' => 0, 'excerpt' => 'Check the invoice total.']);

        $page->delete();
        $this->assertDatabaseCount('passage_sets', 0);
        $this->assertDatabaseCount('passages', 0);
    }

    public function test_html_without_static_evidence_has_a_completed_empty_set(): void
    {
        $this->enable();
        $page = $this->createPage('<!doctype html><html><body></body></html>', PageType::HtmlArtifact);
        $this->assertDatabaseHas('passage_sets', ['page_version_uid' => $page->current_version_uid, 'outcome' => 'empty']);
        $this->assertDatabaseCount('passages', 0);
    }

    public function test_stale_generation_revision_source_and_current_pointer_cannot_publish(): void
    {
        $settings = $this->enable();
        $index = app(PagePassageIndex::class);
        $source = '# Current source';
        $page = $this->createPage($source);
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $prepared = $index->prepare(PageType::Markdown, $source);
        self::assertNotNull($prepared);

        DB::table('passage_sets')->delete();
        $wrongContent = $index->prepare(PageType::Markdown, '# Other source');
        self::assertNotNull($wrongContent);
        $index->publish($page->uid, $version->uid, $wrongContent);
        $this->assertDatabaseCount('passage_sets', 0);
        $settings->forceFill(['passage_retrieval_enabled' => false, 'passage_retrieval_generation' => 2, 'passage_retrieval_extractor_revision' => null])->save();
        $index->publish($page->uid, $version->uid, $prepared);
        $this->assertDatabaseCount('passage_sets', 0);
        $settings->forceFill(['passage_retrieval_enabled' => true, 'passage_retrieval_generation' => 3, 'passage_retrieval_extractor_revision' => 2])->save();
        self::assertNull($index->prepare(PageType::Markdown, $source));
        $settings->forceFill(['passage_retrieval_extractor_revision' => 1])->save();
        $index->publish($page->uid, $version->uid, $prepared);
        $this->assertDatabaseCount('passage_sets', 0);

        $current = $index->prepare(PageType::Markdown, $source);
        self::assertNotNull($current);
        $index->publish($page->uid, $version->uid, $current);
        $index->publish($page->uid, $version->uid, $current);
        $this->assertDatabaseCount('passages', 1);
        $this->updatePage($page, '# Different current version');
        $index->publish($page->uid, $version->uid, $current);
        $this->assertDatabaseMissing('passage_sets', ['page_version_uid' => $version->uid]);
    }

    public function test_optional_sql_failure_keeps_source_and_page_search_and_logs_no_private_text(): void
    {
        $this->enable();
        DB::statement('ALTER TABLE passages ADD CONSTRAINT test_reject_publication CHECK (ordinal < 0)');
        Log::spy();
        Log::shouldReceive('warning')->once()->with('passage_retrieval.publication_failed', ['reason' => 'projection_write']);
        $source = "# Accounting\n\nThe invoice is held for reconciliation.";
        $page = $this->createPage($source);
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        self::assertSame($source, Storage::disk('artifacts')->get($version->content_storage_path));
        self::assertSame(1, DB::table('pages')->where('uid', $page->uid)
            ->whereRaw("search_vector @@ plainto_tsquery('simple', ?)", ['reconciliation'])->count());
        $this->assertDatabaseCount('passage_sets', 0);
        $this->assertDatabaseCount('passages', 0);
    }

    public function test_projection_preparation_failure_keeps_the_source_and_ordinary_search(): void
    {
        $this->enable();
        $failed = false;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$failed): void {
            if (!$failed && str_starts_with($sql, 'select "passage_retrieval_generation"')) {
                $failed = true;
                throw new RuntimeException('private database details');
            }
        });
        Log::shouldReceive('warning')->once()->with('passage_retrieval.preparation_failed', ['reason' => 'extraction']);

        $source = 'Review the tax reconciliation.';
        $page = $this->createPage($source);

        self::assertTrue($failed);
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        self::assertSame($source, Storage::disk('artifacts')->get($version->content_storage_path));
        self::assertSame(1, DB::table('pages')->where('uid', $page->uid)
            ->whereRaw("search_vector @@ plainto_tsquery('simple', ?)", ['reconciliation'])->count());
        $this->assertDatabaseCount('passage_sets', 0);
    }

    public function test_successful_publication_restores_the_callers_lock_timeout_and_honors_a_stricter_limit(): void
    {
        $this->enable();
        DB::statement("SET LOCAL lock_timeout TO '0'");
        $page = $this->createPage('First rule');
        $this->assertDatabaseHas('passage_sets', ['page_version_uid' => $page->current_version_uid]);
        self::assertSame('0', DB::scalar('SHOW lock_timeout'));

        DB::statement("SET LOCAL lock_timeout TO '50ms'");
        DB::statement("ALTER TABLE passages ADD CONSTRAINT test_stricter_lock_timeout CHECK (current_setting('lock_timeout') = '50ms') NOT VALID");
        $version = $this->updatePage($page, 'Updated rule');
        $this->assertDatabaseHas('passage_sets', ['page_version_uid' => $version->uid]);
        self::assertSame('50ms', DB::scalar('SHOW lock_timeout'));
    }

    public function test_source_saves_bound_optional_lock_waits_even_if_postgres_has_no_lock_timeout(): void
    {
        $this->enable();
        config(['database.connections.passage_write_competitor' => DB::connection()->getConfig()]);
        $competitor = DB::connection('passage_write_competitor');
        $competitor->beginTransaction();
        try {
            $competitor->statement('LOCK TABLE passages IN ACCESS EXCLUSIVE MODE');
            DB::statement("SET LOCAL lock_timeout TO '0'");
            // Bound the regression test itself if publication waits indefinitely.
            DB::statement("SET LOCAL statement_timeout TO '4s'");
            $started = hrtime(true);
            $page = $this->createPage('An authoritative rule');
            self::assertLessThan(3_000_000_000, hrtime(true) - $started);
            self::assertSame('0', DB::scalar('SHOW lock_timeout'));
        } finally {
            $competitor->rollBack();
            DB::purge('passage_write_competitor');
        }
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        self::assertSame('An authoritative rule', Storage::disk('artifacts')->get($version->content_storage_path));
        $this->assertDatabaseCount('passage_sets', 0);
        $this->assertDatabaseCount('passages', 0);
    }

    public function test_unauthorized_append_and_outer_rollback_preserve_the_prior_evidence(): void
    {
        $this->enable();
        $page = $this->createPage('# Original evidence');
        $other = User::factory()->create();
        try {
            app(UpdatePageContent::class)->handle($other, new UpdatePageContentCommand(
                $page->uid,
                '# Unauthorized update',
                baseVersionUid: $page->current_version_uid,
            ));
            self::fail('The non-member append must be denied.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('passages', 1);
        }

        try {
            DB::transaction(function () use ($page): void {
                $this->updatePage($page, '# Uncommitted revision');
                $this->assertDatabaseHas('passages', ['excerpt' => '# Uncommitted revision']);
                throw new RuntimeException('roll back the outer test transaction');
            });
        } catch (RuntimeException $error) {
            self::assertSame('roll back the outer test transaction', $error->getMessage());
        }
        $this->assertDatabaseHas('passage_sets', ['page_version_uid' => $page->refresh()->current_version_uid]);
        $this->assertDatabaseHas('passages', ['excerpt' => '# Original evidence']);
        $this->assertDatabaseCount('passages', 1);
    }

    private function createSettings(): InstallationSettings
    {
        return InstallationSettings::query()->forceCreate(
            app(InstallationLimitSettings::class)->current()->toPersistenceArray() + ['scope' => InstallationSettings::SCOPE_INSTALLATION],
        );
    }

    private function enable(): InstallationSettings
    {
        $settings = $this->createSettings();
        $settings->forceFill([
            'passage_retrieval_enabled' => true,
            'passage_retrieval_generation' => 1,
            'passage_retrieval_extractor_revision' => PassageExtractor::REVISION,
        ])->save();

        return $settings;
    }

    private function createPage(string $source, PageType $type = PageType::Markdown): Page
    {
        return app(CreatePage::class)->handle($this->author, new CreatePageCommand(
            workspaceUid: $this->workspaceUid,
            type: $type,
            title: 'Workflow',
            description: null,
            content: $source,
        ));
    }

    private function updatePage(Page $page, string $source): PageVersion
    {
        return app(UpdatePageContent::class)->handle($this->author, new UpdatePageContentCommand(
            $page->uid,
            $source,
            baseVersionUid: $page->refresh()->current_version_uid,
        ));
    }
}
