<?php

declare(strict_types=1);

namespace Tests\Feature\PassageRetrieval;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Application\PassageRetrieval\PassageMaintenance;
use App\Domain\PageCatalog\PageType;
use App\Jobs\BackfillPassageIndex;
use App\Jobs\PurgePassageGeneration;
use App\Models\InstallationSettings;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class PassageMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private string $workspaceUid;
    private InstallationSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
        $this->actor = User::factory()->create();
        $this->workspaceUid = app(CreateSharedWorkspace::class)->handle($this->actor, 'Index fixtures')->uid;
        $this->settings = InstallationSettings::query()->forceCreate(
            app(InstallationLimitSettings::class)->current()->toPersistenceArray(),
        );
    }

    public function test_scheduler_is_idle_while_off_and_dispatches_only_existing_work_on_the_normal_queue(): void
    {
        Queue::fake();
        $this->page('Existing rule');
        $this->assertReconcileSucceeds();
        Queue::assertNothingPushed();
        $this->policy(1, 1);
        $this->assertReconcileSucceeds();
        Queue::assertPushed(BackfillPassageIndex::class, static fn (BackfillPassageIndex $job): bool => $job->generation === 1 && $job->revision === 1);
        app(PassageMaintenance::class)->backfill(1, 1);
        Queue::fake();
        $this->assertReconcileSucceeds();
        Queue::assertNothingPushed();
    }

    public function test_backfill_checks_retained_hash_retries_missing_bytes_and_allows_other_pages_to_progress(): void
    {
        $bad = $this->page('Recorded rule');
        $missing = $this->page('Restored file');
        $good = $this->page('Available rule');
        $badVersion = PageVersion::query()->where('page_uid', $bad->uid)->sole();
        $missingVersion = PageVersion::query()->where('page_uid', $missing->uid)->sole();
        Storage::disk('artifacts')->put($badVersion->content_storage_path, 'Changed bytes');
        Storage::disk('artifacts')->delete($missingVersion->content_storage_path);
        $this->policy(1, 1);
        $maintenance = app(PassageMaintenance::class);
        $maintenance->backfill(1, 1);
        $this->assertDatabaseHas('passage_sets', ['page_version_uid' => $good->current_version_uid]);
        $this->assertDatabaseCount('passage_sets', 1);
        $this->assertDatabaseHas('passage_index_failures', ['page_version_uid' => $badVersion->uid, 'reason' => 'hash_mismatch', 'attempts' => 1]);
        $this->assertDatabaseHas('passage_index_failures', ['page_version_uid' => $missingVersion->uid, 'reason' => 'source_unavailable']);

        Storage::disk('artifacts')->put($badVersion->content_storage_path, 'Recorded rule');
        Storage::disk('artifacts')->put($missingVersion->content_storage_path, 'Restored file');
        $maintenance->backfill(1, 1);
        $this->assertDatabaseCount('passage_sets', 1);
        $this->travel(2)->hours();
        $maintenance->backfill(1, 1);
        $maintenance->backfill(1, 1);
        $this->assertDatabaseCount('passage_sets', 3);
        $this->assertDatabaseCount('passages', 3);
        $this->assertDatabaseCount('passage_index_failures', 0);
    }

    public function test_batches_are_bounded_and_delayed_builders_and_purgers_preserve_the_new_generation(): void
    {
        $pages = [];
        for ($i = 0; $i < 10; $i++) {
            $pages[] = $this->page('Rule ' . $i);
        }
        $maintenance = app(PassageMaintenance::class);
        $this->policy(1, 1);
        $maintenance->backfill(1, 2);
        $this->assertDatabaseCount('passage_sets', 0);
        $maintenance->backfill(1, 1);
        $this->assertDatabaseCount('passage_sets', 8);
        $maintenance->backfill(1, 1);
        $this->assertDatabaseCount('passage_sets', 10);
        $this->policy(2, null);
        $changed = app(UpdatePageContent::class)->handle($this->actor, new UpdatePageContentCommand(
            $pages[0]->uid,
            'Edited while disabled',
            baseVersionUid: $pages[0]->current_version_uid,
        ));
        $this->policy(3, 1);
        $maintenance->backfill(1, 1);
        $maintenance->backfill(3, 1);
        $maintenance->backfill(3, 1);
        Queue::fake();
        $this->assertReconcileSucceeds();
        Queue::assertPushed(PurgePassageGeneration::class, static fn (PurgePassageGeneration $job): bool => $job->generation === 1);
        $maintenance->purge(3);
        $job = new PurgePassageGeneration(1);
        self::assertSame('1', $job->uniqueId());
        $job->handle($maintenance);
        $job->handle($maintenance);
        self::assertSame(10, DB::table('passage_sets')->where('generation', 3)->count());
        $this->assertDatabaseCount('passage_sets', 10);
        $this->assertDatabaseHas('passage_sets', ['page_version_uid' => $changed->uid, 'generation' => 3]);
    }

    public function test_reconciliation_retires_superseded_sets_and_retries_failed_publication(): void
    {
        $this->policy(1, 1);
        $page = $this->page('Previous accounting rule');
        $initialUid = $page->current_version_uid;
        $this->assertDatabaseHas('passage_sets', ['page_version_uid' => $initialUid]);

        DB::statement('ALTER TABLE passages ADD CONSTRAINT test_block_passage_write CHECK (ordinal < 0) NOT VALID');
        $current = app(UpdatePageContent::class)->handle($this->actor, new UpdatePageContentCommand(
            $page->uid,
            'Replace the accounting rule',
            baseVersionUid: $initialUid,
        ));
        $this->assertDatabaseHas('passage_sets', ['page_version_uid' => $initialUid]);
        $job = new BackfillPassageIndex(1, 1);
        self::assertSame('1:1', $job->uniqueId());

        $maintenance = app(PassageMaintenance::class);
        $job->handle($maintenance);
        $this->assertDatabaseCount('passage_sets', 0);
        $this->assertDatabaseHas('passage_index_failures', [
            'page_version_uid' => $current->uid, 'reason' => 'projection_failed', 'attempts' => 1,
        ]);

        DB::statement('ALTER TABLE passages DROP CONSTRAINT test_block_passage_write');
        $job->handle($maintenance);
        $this->assertDatabaseCount('passage_sets', 0);
        $this->travel(2)->minutes();
        $job->handle($maintenance);
        $this->assertDatabaseHas('passage_sets', ['page_version_uid' => $current->uid]);
        $this->assertDatabaseCount('passage_index_failures', 0);
    }

    public function test_worker_failures_omit_private_storage_and_query_details(): void
    {
        $this->page('Pending rule');
        $this->policy(1, 1);
        Storage::shouldReceive('disk')->with('artifacts')->andThrow(new RuntimeException('private path'));
        $maintenance = app(PassageMaintenance::class);
        $this->assertSafeJobFailure(static fn () => (new BackfillPassageIndex(1, 1))->handle($maintenance));
        $this->assertDatabaseCount('passage_sets', 0);

        $this->policy(2, null);
        DB::connection()->beforeExecuting(static function (string $sql): void {
            if (str_contains($sql, 'from "passage_sets"')) {
                throw new RuntimeException('private database details');
            }
        });
        $this->assertSafeJobFailure(static fn () => (new PurgePassageGeneration(1))->handle($maintenance));
    }

    public function test_worker_rejects_a_source_that_grew_after_the_storage_size_check(): void
    {
        $page = $this->page('Eight bytes and more');
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $this->policy(1, 1);
        $this->settings->forceFill(['max_markdown_bytes' => 8, 'max_html_bytes' => 8, 'artifact_max_bytes' => 8])->save();
        app(InstallationLimitSettings::class)->forgetCachedValues();
        $disk = $this->createMock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->method('size')->with($version->content_storage_path)->willReturn(8);
        $disk->method('readStream')->willReturnCallback(static function () {
            $stream = fopen('php://memory', 'w+b');
            self::assertIsResource($stream);
            fwrite($stream, 'Eight bytes and more');
            rewind($stream);

            return $stream;
        });
        Storage::shouldReceive('disk')->with('artifacts')->andReturn($disk);
        (new BackfillPassageIndex(1, 1))->handle(app(PassageMaintenance::class));
        $this->assertDatabaseCount('passage_sets', 0);
        $this->assertDatabaseHas('passage_index_failures', ['page_version_uid' => $version->uid, 'reason' => 'source_unavailable']);
    }

    /** @param callable(): void $job */
    private function assertSafeJobFailure(callable $job): void
    {
        try {
            $job();
            self::fail('Expected the job to surface a retryable failure.');
        } catch (RuntimeException $failure) {
            self::assertSame('Passage maintenance was interrupted.', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    private function assertReconcileSucceeds(): void
    {
        $command = $this->artisan('artifactflow:reconcile-passages');
        self::assertInstanceOf(\Illuminate\Testing\PendingCommand::class, $command);
        $command->assertSuccessful();
    }

    private function policy(int $generation, ?int $revision): void
    {
        $this->settings->forceFill([
            'passage_retrieval_enabled' => $revision !== null,
            'passage_retrieval_generation' => $generation,
            'passage_retrieval_extractor_revision' => $revision,
        ])->save();
    }

    private function page(string $source): Page
    {
        return app(CreatePage::class)->handle($this->actor, new CreatePageCommand(
            workspaceUid: $this->workspaceUid,
            type: PageType::Markdown,
            title: $source,
            description: null,
            content: $source,
        ));
    }
}
