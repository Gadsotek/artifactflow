<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\HardDeletePage;
use App\Application\PageCatalog\HardDeletePageCommand;
use App\Application\PageCatalog\PruneOrphanArtifacts;
use App\Application\PageCatalog\ReprocessXlsxArtifact;
use App\Application\PageCatalog\ReprocessXlsxArtifactCommand;
use App\Application\PageCatalog\RestorePageVersion;
use App\Application\PageCatalog\RestorePageVersionCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Application\PageCatalog\XlsxProcessingResult;
use App\Application\PageCatalog\XlsxProcessorProtocol;
use App\Domain\PageCatalog\ArtifactDerivativeKind;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use App\Models\User;
use App\Models\Workspace;
use App\Models\XlsxVersionFact;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PDOException;
use Tests\Support\ThrowAfterCommitTransaction;
use Tests\TestCase;

final class XlsxArtifactLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const string PROCESSOR_SECRET = 'test-xlsx-lifecycle-secret-000000001';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        config([
            'xlsx_processor.enabled' => true,
            'xlsx_processor.url' => 'http://xlsx-processor.test',
            'xlsx_processor.socket_path' => null,
            'xlsx_processor.shared_secret' => self::PROCESSOR_SECRET,
            'xlsx_processor.connect_timeout_seconds' => 2,
            'xlsx_processor.timeout_seconds' => 15,
        ]);
    }

    public function test_xlsx_create_persists_exact_original_canonical_manifest_facts_search_and_quota(): void
    {
        $this->fakeProcessor(['Quarterly café']);
        $editor = $this->user('Workbook Editor', 'workbook-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Workbook Team');
        $xlsx = "PK\x03\x04quarterly-workbook";

        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Xlsx,
            title: 'Quarterly Workbook',
            description: null,
            content: $xlsx,
            sourceFilename: 'quarterly.xlsx',
            source: PageVersionSource::Upload,
        ));

        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $derivative = PageVersionDerivative::query()->where('page_version_uid', $version->uid)->sole();
        $facts = XlsxVersionFact::query()->whereKey($version->uid)->sole();
        $manifest = Storage::disk('artifacts')->get($derivative->storage_path);
        $this->assertIsString($manifest);

        $this->assertSame($xlsx, Storage::disk('artifacts')->get($version->content_storage_path));
        $this->assertSame(hash('sha256', $xlsx), $version->content_hash);
        $this->assertSame(strlen($xlsx), $version->byte_size);
        $this->assertSame(ArtifactDerivativeKind::XlsxManifest, $derivative->kind);
        $this->assertSame(hash('sha256', $manifest), $derivative->content_hash);
        $this->assertSame(strlen($manifest), $derivative->byte_size);
        $this->assertSame(
            'Quarterly café',
            data_get(json_decode($manifest, true, flags: JSON_THROW_ON_ERROR), 'sheets.0.cells.0.display'),
        );
        $this->assertStringContainsString('[Visible] A1 Quarterly café', (string) $version->extracted_text);
        $this->assertSame(1, $facts->visible_sheet_count);
        $this->assertSame(1, $facts->cell_count);
        $this->assertFalse($facts->truncated);
        $this->assertSame(strlen($xlsx) + strlen($manifest), $workspace->refresh()->used_storage_bytes);
    }

    public function test_xlsx_rejects_stale_and_quota_doomed_work_before_processor_dispatch(): void
    {
        $this->fakeProcessor(['Initial workbook', 'Processor must not run', str_repeat('x', 1_024)]);
        $editor = $this->user('Workbook Admission Editor', 'workbook-admission@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Workbook Admission');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Xlsx,
            title: 'Admission Workbook',
            description: null,
            content: "PK\x03\x04initial-admission-workbook",
            sourceFilename: 'admission.xlsx',
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        Http::assertSentCount(1);

        try {
            app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: "PK\x03\x04stale-admission-workbook",
                source: PageVersionSource::Upload,
                baseVersionUid: null,
            ));
            $this->fail('A missing base version must fail before XLSX processing.');
        } catch (\App\Domain\PageCatalog\StalePageVersionException) {
            $this->addToAssertionCount(1);
        }
        Http::assertSentCount(1);

        $usedBytes = $workspace->refresh()->used_storage_bytes;
        $currentDerivativeBytes = PageVersionDerivative::query()
            ->where('page_version_uid', $version->uid)
            ->where('kind', ArtifactDerivativeKind::XlsxManifest)
            ->sole()
            ->byte_size;
        config(['pages.max_workspace_storage_bytes' => $usedBytes - $currentDerivativeBytes]);

        try {
            app(ReprocessXlsxArtifact::class)->handle($editor, new ReprocessXlsxArtifactCommand(
                pageUid: $page->uid,
                expectedCurrentVersionUid: $version->uid,
            ));
            $this->fail('A quota-doomed reprocess must fail before XLSX processing.');
        } catch (\App\Domain\DomainRuleViolation $exception) {
            $this->assertSame('Workspace page storage quota exceeded.', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_xlsx_reprocess_checks_page_quota_against_the_complete_retained_version_graph(): void
    {
        config(['pages.max_page_versions' => 2]);
        $replacementValue = str_repeat('replacement cell ', 64);
        $this->fakeProcessor(['Historical workbook', 'Current workbook', $replacementValue]);
        $editor = $this->user('Workbook Quota Editor', 'workbook-quota@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Workbook Quota');
        $firstBytes = "PK\x03\x04historical-workbook";
        $secondBytes = "PK\x03\x04current-workbook";
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Xlsx,
            title: 'Quota Workbook',
            description: null,
            content: $firstBytes,
            sourceFilename: 'historical.xlsx',
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $currentVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: $secondBytes,
            source: PageVersionSource::Upload,
            baseVersionUid: $firstVersion->uid,
        ));
        $currentDerivative = PageVersionDerivative::query()
            ->where('page_version_uid', $currentVersion->uid)
            ->sole();
        $replacementBytes = strlen(XlsxProcessingResult::fromJson(
            $this->processorResponse($secondBytes, $replacementValue),
            strlen($secondBytes),
            hash('sha256', $secondBytes),
        )->manifestJson);
        $byteDelta = $replacementBytes - $currentDerivative->byte_size;
        $this->assertGreaterThan(0, $byteDelta);
        $usedBytesBefore = $workspace->refresh()->used_storage_bytes;
        config(['pages.max_page_storage_bytes' => $usedBytesBefore + $byteDelta - 1]);
        $filesBefore = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($filesBefore);
        $derivativePathBefore = $currentDerivative->storage_path;
        $derivativeHashBefore = $currentDerivative->content_hash;
        $extractedTextBefore = $currentVersion->extracted_text;
        $factsBefore = XlsxVersionFact::query()->whereKey($currentVersion->uid)->sole()->getAttributes();
        $searchVectorBefore = Page::query()->whereKey($page->uid)->sole()->getRawOriginal('search_vector');

        try {
            app(ReprocessXlsxArtifact::class)->handle($editor, new ReprocessXlsxArtifactCommand(
                pageUid: $page->uid,
                expectedCurrentVersionUid: $currentVersion->uid,
            ));
            $this->fail('A replacement that exceeds the complete retained page graph must be rejected.');
        } catch (\App\Domain\DomainRuleViolation $exception) {
            $this->assertSame('Page storage quota exceeded.', $exception->getMessage());
        }

        $currentDerivative->refresh();
        $currentVersion->refresh();
        $filesAfter = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($filesAfter);
        $this->assertSame($derivativePathBefore, $currentDerivative->storage_path);
        $this->assertSame($derivativeHashBefore, $currentDerivative->content_hash);
        $this->assertSame($extractedTextBefore, $currentVersion->extracted_text);
        $this->assertSame(
            $factsBefore,
            XlsxVersionFact::query()->whereKey($currentVersion->uid)->sole()->getAttributes(),
        );
        $this->assertSame(
            $searchVectorBefore,
            Page::query()->whereKey($page->uid)->sole()->getRawOriginal('search_vector'),
        );
        $this->assertSame($usedBytesBefore, $workspace->refresh()->used_storage_bytes);
        $this->assertSame($filesBefore, $filesAfter);
        Http::assertSentCount(3);
    }

    public function test_xlsx_restore_reprocesses_the_original_and_retention_prunes_complete_blob_graphs(): void
    {
        config(['pages.max_page_versions' => 2]);
        $this->fakeProcessor(['First', 'Second', 'First restored']);
        $editor = $this->user('Workbook History Editor', 'workbook-history@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Workbook History');
        $firstBytes = "PK\x03\x04first-workbook";
        $secondBytes = "PK\x03\x04second-workbook";
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Xlsx,
            title: 'Workbook History',
            description: null,
            content: $firstBytes,
            sourceFilename: 'first.xlsx',
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $firstPaths = [
            $firstVersion->content_storage_path,
            PageVersionDerivative::query()->where('page_version_uid', $firstVersion->uid)->sole()->storage_path,
        ];
        $second = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: $secondBytes,
            source: PageVersionSource::Upload,
            baseVersionUid: $firstVersion->uid,
        ));

        $restored = app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
            pageUid: $page->uid,
            versionUid: $firstVersion->uid,
            expectedCurrentVersionUid: $second->uid,
        ));

        $this->assertSame($firstBytes, Storage::disk('artifacts')->get($restored->content_storage_path));
        $this->assertStringContainsString('First restored', (string) $restored->extracted_text);
        $this->assertNull(PageVersion::query()->find($firstVersion->uid));
        $this->assertNull(XlsxVersionFact::query()->find($firstVersion->uid));
        foreach ($firstPaths as $path) {
            Storage::disk('artifacts')->assertMissing($path);
        }
        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(2, PageVersionDerivative::query()->count());
        $this->assertSame(2, XlsxVersionFact::query()->count());
    }

    public function test_xlsx_reprocess_replaces_only_the_manifest_and_refreshes_search_and_facts(): void
    {
        $this->fakeProcessor(['Before reprocess', 'After reprocess']);
        $editor = $this->user('Workbook Reprocess Editor', 'workbook-reprocess@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Workbook Reprocess');
        $xlsx = "PK\x03\x04reprocess-workbook";
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Xlsx,
            title: 'Reprocess Workbook',
            description: null,
            content: $xlsx,
            sourceFilename: 'reprocess.xlsx',
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $originalPath = $version->content_storage_path;
        $firstDerivative = PageVersionDerivative::query()
            ->where('page_version_uid', $version->uid)
            ->sole();

        $reprocessed = app(ReprocessXlsxArtifact::class)->handle($editor, new ReprocessXlsxArtifactCommand(
            pageUid: $page->uid,
            expectedCurrentVersionUid: $version->uid,
        ));
        $reprocessedDerivative = PageVersionDerivative::query()
            ->where('page_version_uid', $version->uid)
            ->sole();
        $facts = XlsxVersionFact::query()->whereKey($version->uid)->sole();

        $this->assertSame($version->uid, $reprocessed->uid);
        $this->assertSame($originalPath, $reprocessed->content_storage_path);
        $this->assertSame($xlsx, Storage::disk('artifacts')->get($originalPath));
        $this->assertNotSame($firstDerivative->storage_path, $reprocessedDerivative->storage_path);
        Storage::disk('artifacts')->assertMissing($firstDerivative->storage_path);
        $this->assertStringContainsString('After reprocess', (string) $reprocessed->extracted_text);
        $this->assertSame(XlsxProcessorProtocol::ENGINE_VERSION, $facts->engine_version);
        $this->assertSame(
            strlen($xlsx) + $reprocessedDerivative->byte_size,
            $workspace->refresh()->used_storage_bytes,
        );
    }

    public function test_xlsx_reprocess_post_callback_rollback_preserves_then_reaps_the_unreferenced_manifest(): void
    {
        $this->fakeProcessor(['Before failed commit', 'After failed commit']);
        $editor = $this->user('Workbook Commit Editor', 'workbook-commit@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Workbook Commit');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Xlsx,
            title: 'Commit Workbook',
            description: null,
            content: "PK\x03\x04commit-workbook",
            sourceFilename: 'commit.xlsx',
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $derivative = PageVersionDerivative::query()->where('page_version_uid', $version->uid)->sole();
        $filesBefore = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($filesBefore);
        $this->rollBackAfterNextTransactionCallback('Forced XLSX post-callback rollback.');

        try {
            app(ReprocessXlsxArtifact::class)->handle($editor, new ReprocessXlsxArtifactCommand(
                pageUid: $page->uid,
                expectedCurrentVersionUid: $version->uid,
            ));
            $this->fail('The simulated post-callback rollback must fail XLSX reprocessing.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced XLSX post-callback rollback.', $exception->getMessage());
        }

        $filesAfter = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($filesAfter);
        $this->assertCount(count($filesBefore) + 1, $filesAfter);
        Storage::disk('artifacts')->assertExists($derivative->storage_path);
        $this->assertSame(
            $derivative->storage_path,
            PageVersionDerivative::query()->whereKey($derivative->uid)->sole()->storage_path,
        );

        $result = app(PruneOrphanArtifacts::class)->handle(delete: true, minAgeSeconds: 0);
        $this->assertSame(1, $result->orphansFound);
        $this->assertSame(1, $result->orphansDeleted);
        $remainingFiles = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($remainingFiles);
        $this->assertSame($filesBefore, $remainingFiles);
    }

    public function test_xlsx_reprocess_rejects_missing_original_and_missing_facts_without_leaking_staging(): void
    {
        $this->fakeProcessor(['Initial workbook', 'Reprocessed workbook']);
        $editor = $this->user('Workbook Integrity Editor', 'workbook-integrity@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Workbook Integrity');
        $xlsx = "PK\x03\x04integrity-workbook";
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Xlsx,
            title: 'Integrity Workbook',
            description: null,
            content: $xlsx,
            sourceFilename: 'integrity.xlsx',
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $command = new ReprocessXlsxArtifactCommand($page->uid, $version->uid);

        Storage::disk('artifacts')->delete($version->content_storage_path);
        try {
            app(ReprocessXlsxArtifact::class)->handle($editor, $command);
            $this->fail('A missing retained original must stop XLSX reprocessing before dispatch.');
        } catch (\App\Domain\DomainRuleViolation $exception) {
            $this->assertStringContainsString('failed integrity verification', $exception->getMessage());
        }
        Http::assertSentCount(1);

        Storage::disk('artifacts')->put($version->content_storage_path, $xlsx);
        $filesBefore = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($filesBefore);
        XlsxVersionFact::query()->whereKey($version->uid)->delete();
        try {
            app(ReprocessXlsxArtifact::class)->handle($editor, $command);
            $this->fail('Missing XLSX facts must fail closed after processor dispatch.');
        } catch (\App\Domain\DomainRuleViolation $exception) {
            $this->assertSame('XLSX processing facts are unavailable.', $exception->getMessage());
        }

        $filesAfter = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($filesAfter);
        $this->assertSame($filesBefore, $filesAfter);
        Http::assertSentCount(2);
    }

    public function test_xlsx_reprocess_records_advisory_findings_from_projected_content(): void
    {
        $this->fakeProcessor(['Initial workbook', 'document.cookie']);
        $editor = $this->user('Workbook Warning Editor', 'workbook-warning@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Workbook Warning');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Xlsx,
            title: 'Warning Workbook',
            description: null,
            content: "PK\x03\x04warning-workbook",
            sourceFilename: 'warning.xlsx',
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();

        $reprocessed = app(ReprocessXlsxArtifact::class)->handle($editor, new ReprocessXlsxArtifactCommand(
            pageUid: $page->uid,
            expectedCurrentVersionUid: $version->uid,
        ));

        $this->assertSame('warnings', $reprocessed->scan_status->value);
        $this->assertSame('document_cookie', $reprocessed->scan_findings[0]['code'] ?? null);
    }

    public function test_xlsx_create_post_callback_rollback_preserves_then_reaps_the_unreferenced_blob_graph(): void
    {
        $this->fakeProcessor(['Failed create']);
        $editor = $this->user('Workbook Create Commit Editor', 'workbook-create-commit@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Workbook Create Commit');
        $this->rollBackAfterNextTransactionCallback('Forced XLSX create post-callback rollback.');

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Xlsx,
                title: 'Failed Create Workbook',
                description: null,
                content: "PK\x03\x04failed-create-workbook",
                sourceFilename: 'failed-create.xlsx',
                source: PageVersionSource::Upload,
            ));
            $this->fail('The simulated post-callback rollback must fail XLSX creation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced XLSX create post-callback rollback.', $exception->getMessage());
        }

        $this->assertSame(0, Page::query()->where('workspace_uid', $workspace->uid)->count());
        $this->assertCount(2, Storage::disk('artifacts')->allFiles());
        $this->assertSame(0, $workspace->refresh()->used_storage_bytes);

        $result = app(PruneOrphanArtifacts::class)->handle(delete: true, minAgeSeconds: 0);
        $this->assertSame(2, $result->orphansFound);
        $this->assertSame(2, $result->orphansDeleted);
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_xlsx_update_post_callback_rollback_preserves_then_reaps_the_unreferenced_blob_graph(): void
    {
        $this->fakeProcessor(['Initial', 'Failed update']);
        $editor = $this->user('Workbook Update Commit Editor', 'workbook-update-commit@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Workbook Update Commit');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Xlsx,
            title: 'Update Commit Workbook',
            description: null,
            content: "PK\x03\x04initial-update-workbook",
            sourceFilename: 'update-commit.xlsx',
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $filesBefore = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($filesBefore);
        $bytesBefore = $workspace->refresh()->used_storage_bytes;
        $this->rollBackAfterNextTransactionCallback('Forced XLSX update post-callback rollback.');

        try {
            app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: "PK\x03\x04failed-update-workbook",
                source: PageVersionSource::Upload,
                baseVersionUid: $version->uid,
            ));
            $this->fail('The simulated post-callback rollback must fail XLSX update.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced XLSX update post-callback rollback.', $exception->getMessage());
        }

        $filesAfter = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($filesAfter);
        $this->assertCount(count($filesBefore) + 2, $filesAfter);
        $this->assertSame($version->uid, $page->refresh()->current_version_uid);
        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame($bytesBefore, $workspace->refresh()->used_storage_bytes);

        $result = app(PruneOrphanArtifacts::class)->handle(delete: true, minAgeSeconds: 0);
        $this->assertSame(2, $result->orphansFound);
        $this->assertSame(2, $result->orphansDeleted);
        $remainingFiles = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($remainingFiles);
        $this->assertSame($filesBefore, $remainingFiles);
    }

    public function test_xlsx_restore_post_callback_rollback_preserves_then_reaps_the_unreferenced_blob_graph(): void
    {
        $this->fakeProcessor(['First', 'Second', 'Failed restore']);
        $editor = $this->user('Workbook Restore Commit Editor', 'workbook-restore-commit@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Workbook Restore Commit');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Xlsx,
            title: 'Restore Commit Workbook',
            description: null,
            content: "PK\x03\x04first-restore-workbook",
            sourceFilename: 'restore-commit.xlsx',
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: "PK\x03\x04second-restore-workbook",
            source: PageVersionSource::Upload,
            baseVersionUid: $firstVersion->uid,
        ));
        $filesBefore = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($filesBefore);
        $bytesBefore = $workspace->refresh()->used_storage_bytes;
        $this->rollBackAfterNextTransactionCallback('Forced XLSX restore post-callback rollback.');

        try {
            app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
                pageUid: $page->uid,
                versionUid: $firstVersion->uid,
                expectedCurrentVersionUid: $secondVersion->uid,
            ));
            $this->fail('The simulated post-callback rollback must fail XLSX restore.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced XLSX restore post-callback rollback.', $exception->getMessage());
        }

        $filesAfter = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($filesAfter);
        $this->assertCount(count($filesBefore) + 2, $filesAfter);
        $this->assertSame($secondVersion->uid, $page->refresh()->current_version_uid);
        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame($bytesBefore, $workspace->refresh()->used_storage_bytes);

        $result = app(PruneOrphanArtifacts::class)->handle(delete: true, minAgeSeconds: 0);
        $this->assertSame(2, $result->orphansFound);
        $this->assertSame(2, $result->orphansDeleted);
        $remainingFiles = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($remainingFiles);
        $this->assertSame($filesBefore, $remainingFiles);
    }

    public function test_unknown_commit_outcomes_preserve_committed_xlsx_blobs_across_all_write_paths(): void
    {
        $this->fakeProcessor(['Committed create', 'Committed update', 'Committed restore', 'Committed reprocess']);
        $editor = $this->user('Workbook Ambiguous Commit Editor', 'workbook-ambiguous-commit@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Workbook Ambiguous Commit');

        $this->expectUnknownCommitOutcome(fn (): Page => app(CreatePage::class)->handle(
            $editor,
            new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Xlsx,
                title: 'Committed Workbook',
                description: null,
                content: "PK\x03\x04committed-create-workbook",
                sourceFilename: 'committed-create.xlsx',
                source: PageVersionSource::Upload,
            ),
        ));

        $page = Page::query()->where('workspace_uid', $workspace->uid)->sole();
        $firstVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $firstDerivative = PageVersionDerivative::query()->where('page_version_uid', $firstVersion->uid)->sole();
        Storage::disk('artifacts')->assertExists($firstVersion->content_storage_path);
        Storage::disk('artifacts')->assertExists($firstDerivative->storage_path);

        $this->expectUnknownCommitOutcome(fn (): PageVersion => app(UpdatePageContent::class)->handle(
            $editor,
            new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: "PK\x03\x04committed-update-workbook",
                source: PageVersionSource::Upload,
                baseVersionUid: $firstVersion->uid,
            ),
        ));

        $page->refresh();
        $secondVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $secondDerivative = PageVersionDerivative::query()->where('page_version_uid', $secondVersion->uid)->sole();
        $this->assertNotSame($firstVersion->uid, $secondVersion->uid);
        Storage::disk('artifacts')->assertExists($secondVersion->content_storage_path);
        Storage::disk('artifacts')->assertExists($secondDerivative->storage_path);

        $this->expectUnknownCommitOutcome(fn (): PageVersion => app(RestorePageVersion::class)->handle(
            $editor,
            new RestorePageVersionCommand(
                pageUid: $page->uid,
                versionUid: $firstVersion->uid,
                expectedCurrentVersionUid: $secondVersion->uid,
            ),
        ));

        $page->refresh();
        $restoredVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $restoredDerivative = PageVersionDerivative::query()->where('page_version_uid', $restoredVersion->uid)->sole();
        $this->assertSame(3, PageVersion::query()->where('page_uid', $page->uid)->count());
        Storage::disk('artifacts')->assertExists($restoredVersion->content_storage_path);
        Storage::disk('artifacts')->assertExists($restoredDerivative->storage_path);

        $oldManifestPath = $restoredDerivative->storage_path;
        $this->expectUnknownCommitOutcome(fn (): PageVersion => app(ReprocessXlsxArtifact::class)->handle(
            $editor,
            new ReprocessXlsxArtifactCommand(
                pageUid: $page->uid,
                expectedCurrentVersionUid: $restoredVersion->uid,
            ),
        ));

        $reprocessedDerivative = PageVersionDerivative::query()->whereKey($restoredDerivative->uid)->sole();
        $this->assertNotSame($oldManifestPath, $reprocessedDerivative->storage_path);
        Storage::disk('artifacts')->assertExists($restoredVersion->content_storage_path);
        Storage::disk('artifacts')->assertExists($oldManifestPath);
        Storage::disk('artifacts')->assertExists($reprocessedDerivative->storage_path);
        $this->assertStringContainsString(
            'Committed reprocess',
            (string) PageVersion::query()->whereKey($restoredVersion->uid)->sole()->extracted_text,
        );
    }

    public function test_xlsx_integrity_and_hard_delete_cover_originals_and_manifests(): void
    {
        $this->fakeProcessor(['Delete me']);
        $editor = $this->user('Workbook Delete Editor', 'workbook-delete@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Workbook Delete');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Xlsx,
            title: 'Delete Workbook',
            description: null,
            content: "PK\x03\x04delete-workbook",
            sourceFilename: 'delete.xlsx',
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $derivative = PageVersionDerivative::query()->where('page_version_uid', $version->uid)->sole();

        $this->assertSame(0, Artisan::call('artifactflow:verify-artifacts', [
            '--all' => true,
            '--json' => true,
        ]));
        $this->assertSame(
            2,
            data_get(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR), 'checked'),
        );

        app(HardDeletePage::class)->handle($editor, new HardDeletePageCommand(
            pageUid: $page->uid,
            confirmation: $page->title,
        ));

        $this->assertNull(Page::query()->find($page->uid));
        Storage::disk('artifacts')->assertMissing($version->content_storage_path);
        Storage::disk('artifacts')->assertMissing($derivative->storage_path);
        $this->assertSame(0, Workspace::query()->findOrFail($workspace->uid)->used_storage_bytes);
    }

    /** @param list<string> $values */
    private function fakeProcessor(array $values): void
    {
        Http::fake(function (Request $request) use (&$values): \GuzzleHttp\Promise\PromiseInterface {
            $value = array_shift($values);
            $this->assertIsString($value);
            $xlsx = $request->body();
            $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $this->assertIsString($nonce);
            $body = $this->processorResponse($xlsx, $value);
            $inputHash = hash('sha256', $xlsx);

            return Http::response($body, 200, [
                'Cache-Control' => 'no-store',
                'Content-Type' => XlsxProcessorProtocol::MANIFEST_MEDIA_TYPE,
                'Content-Length' => (string) strlen($body),
                'X-ArtifactFlow-Processor-Nonce' => $nonce,
                'X-ArtifactFlow-Input-Bytes' => (string) strlen($xlsx),
                'X-ArtifactFlow-Input-SHA256' => $inputHash,
                'X-ArtifactFlow-Response-SHA256' => hash('sha256', $body),
                'X-ArtifactFlow-Processor-Profile' => XlsxProcessorProtocol::PROCESSOR_PROFILE,
                'X-ArtifactFlow-Processor-Schema' => XlsxProcessorProtocol::RESPONSE_SCHEMA,
                'X-ArtifactFlow-Processor-Engine' => XlsxProcessorProtocol::ENGINE_NAME,
                'X-ArtifactFlow-Processor-Engine-Version' => XlsxProcessorProtocol::ENGINE_VERSION,
                'X-ArtifactFlow-Processor-Signature' => XlsxProcessorProtocol::responseSignature(
                    $nonce,
                    strlen($xlsx),
                    $inputHash,
                    $body,
                    self::PROCESSOR_SECRET,
                ),
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    private function rollBackAfterNextTransactionCallback(string $message): void
    {
        $manager = DB::getFacadeRoot();
        $this->assertInstanceOf(DatabaseManager::class, $manager);
        $connection = $manager->connection();
        $proxy = Mockery::mock($manager);
        $proxy->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(function (callable $callback) use ($connection, $manager, $message): never {
                $connection->beginTransaction();

                try {
                    $callback();
                } catch (\Throwable $exception) {
                    $connection->rollBack();
                    DB::swap($manager);

                    throw $exception;
                }

                $connection->rollBack();
                DB::swap($manager);

                throw new \RuntimeException($message);
            });
        DB::swap($proxy);
    }

    /** @param callable(): mixed $operation */
    private function expectUnknownCommitOutcome(callable $operation): void
    {
        $message = 'SQLSTATE[08007] transaction_resolution_unknown';
        ThrowAfterCommitTransaction::install($message);

        try {
            $operation();
            $this->fail('The simulated unknown commit outcome must propagate.');
        } catch (PDOException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertSame('08007', $exception->errorInfo[0] ?? null);
        }
    }

    private function processorResponse(string $xlsx, string $value): string
    {
        return json_encode([
            'schema' => XlsxProcessorProtocol::RESPONSE_SCHEMA,
            'profile' => XlsxProcessorProtocol::PROCESSOR_PROFILE,
            'engine' => ['name' => XlsxProcessorProtocol::ENGINE_NAME, 'version' => XlsxProcessorProtocol::ENGINE_VERSION],
            'input' => ['bytes' => strlen($xlsx), 'sha256' => hash('sha256', $xlsx)],
            'package' => ['entryCount' => 8, 'expandedBytes' => 1_024],
            'manifest' => [
                'schema' => 'xlsx-view-manifest-v1',
                'profile' => XlsxProcessorProtocol::PROCESSOR_PROFILE,
                'workbook' => [
                    'visibleSheetCount' => 1,
                    'omittedHiddenSheetCount' => 0,
                    'cellCount' => 1,
                    'formulaCount' => 0,
                    'formulasWithoutCachedResultCount' => 0,
                    'linkCount' => 0,
                    'mergeCount' => 0,
                    'truncated' => false,
                ],
                'sheets' => [[
                    'name' => 'Visible',
                    'rowExtent' => 1,
                    'columnExtent' => 1,
                    'omittedHiddenRowCount' => 0,
                    'omittedHiddenColumnCount' => 0,
                    'merges' => [],
                    'cells' => [[
                        'coordinate' => 'A1',
                        'kind' => 'string',
                        'display' => $value,
                        'value' => $value,
                    ]],
                ]],
                'searchText' => '[Visible] A1 ' . $value,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function user(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }
}
