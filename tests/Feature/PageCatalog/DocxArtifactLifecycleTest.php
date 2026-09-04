<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\DocxProcessorProtocol;
use App\Application\PageCatalog\HardDeletePage;
use App\Application\PageCatalog\HardDeletePageCommand;
use App\Application\PageCatalog\PdfProcessingResult;
use App\Application\PageCatalog\PdfProcessorProtocol;
use App\Application\PageCatalog\PruneOrphanArtifacts;
use App\Application\PageCatalog\ReprocessDocxArtifact;
use App\Application\PageCatalog\ReprocessDocxArtifactCommand;
use App\Application\PageCatalog\RestorePageVersion;
use App\Application\PageCatalog\RestorePageVersionCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\PageCatalog\ArtifactDerivativeKind;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\PdfExtractionState;
use App\Models\DocxVersionFact;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use App\Models\User;
use App\Models\Workspace;
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

final class DocxArtifactLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const string DOCX_SECRET = 'test-docx-lifecycle-secret-000000001';
    private const string PDF_SECRET = 'test-pdf-docx-lifecycle-secret-000001';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        config([
            'docx_processor.enabled' => true,
            'docx_processor.url' => 'http://docx-processor.test',
            'docx_processor.socket_path' => null,
            'docx_processor.shared_secret' => self::DOCX_SECRET,
            'docx_processor.connect_timeout_seconds' => 2,
            'docx_processor.timeout_seconds' => 35,
            'pdf_processor.enabled' => true,
            'pdf_processor.url' => 'http://pdf-processor.test',
            'pdf_processor.socket_path' => null,
            'pdf_processor.shared_secret' => self::PDF_SECRET,
            'pdf_processor.connect_timeout_seconds' => 2,
            'pdf_processor.timeout_seconds' => 15,
        ]);
    }

    public function test_docx_create_persists_original_validated_pdf_facts_search_and_quota(): void
    {
        $pdf = "%PDF-1.7\nfirst preview\n%%EOF\n";
        $this->fakePipeline([$pdf], ['Searchable café contract']);
        $editor = $this->user('Word Editor', 'word-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Word Team');
        $docx = "PK\x03\x04quarterly-document";

        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Docx,
            title: 'Quarterly Contract',
            description: null,
            content: $docx,
            sourceFilename: 'contract.docx',
            source: PageVersionSource::Upload,
        ));

        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $derivative = PageVersionDerivative::query()->where('page_version_uid', $version->uid)->sole();
        $facts = DocxVersionFact::query()->whereKey($version->uid)->sole();
        $this->assertSame($docx, Storage::disk('artifacts')->get($version->content_storage_path));
        $this->assertSame($pdf, Storage::disk('artifacts')->get($derivative->storage_path));
        $this->assertSame(ArtifactDerivativeKind::DocxPreviewPdf, $derivative->kind);
        $this->assertSame('Searchable café contract', $version->extracted_text);
        $this->assertSame(PdfExtractionState::Indexed, $facts->extraction_state);
        $this->assertSame(PdfProcessingResult::DOCX_PREVIEW_PROCESSOR_PROFILE, $facts->pdf_processor_profile);
        $this->assertSame(strlen($docx) + strlen($pdf), $workspace->refresh()->used_storage_bytes);
    }

    public function test_docx_rejects_stale_and_quota_doomed_work_before_processor_dispatch(): void
    {
        $initialPdf = "%PDF-1.7\ninitial admission preview\n%%EOF\n";
        $this->fakePipeline(
            [$initialPdf, "%PDF-1.7\nprocessor must not run" . str_repeat('x', 1_024) . "\n%%EOF\n"],
            ['Initial admission text', 'Processor must not run'],
        );
        $editor = $this->user('Word Admission Editor', 'word-admission@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Word Admission');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Docx,
            title: 'Admission Document',
            description: null,
            content: "PK\x03\x04initial-admission-document",
            sourceFilename: 'admission.docx',
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        Http::assertSentCount(2);

        try {
            app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: "PK\x03\x04stale-admission-document",
                source: PageVersionSource::Upload,
                baseVersionUid: null,
            ));
            $this->fail('A missing base version must fail before DOCX processing.');
        } catch (\App\Domain\PageCatalog\StalePageVersionException) {
            $this->addToAssertionCount(1);
        }
        Http::assertSentCount(2);

        $usedBytes = $workspace->refresh()->used_storage_bytes;
        $currentDerivativeBytes = PageVersionDerivative::query()
            ->where('page_version_uid', $version->uid)
            ->where('kind', ArtifactDerivativeKind::DocxPreviewPdf)
            ->sole()
            ->byte_size;
        config(['pages.max_workspace_storage_bytes' => $usedBytes - $currentDerivativeBytes]);

        try {
            app(ReprocessDocxArtifact::class)->handle($editor, new ReprocessDocxArtifactCommand(
                pageUid: $page->uid,
                expectedCurrentVersionUid: $version->uid,
            ));
            $this->fail('A quota-doomed reprocess must fail before DOCX processing.');
        } catch (\App\Domain\DomainRuleViolation $exception) {
            $this->assertSame('Workspace page storage quota exceeded.', $exception->getMessage());
        }
        Http::assertSentCount(2);
    }

    public function test_docx_reprocess_checks_page_quota_against_the_complete_retained_version_graph(): void
    {
        config(['pages.max_page_versions' => 2]);
        $firstPdf = "%PDF-1.7\nhistorical preview\n%%EOF\n";
        $secondPdf = "%PDF-1.7\ncurrent preview\n%%EOF\n";
        $replacementPdf = "%PDF-1.7\n" . str_repeat('replacement preview ', 64) . "\n%%EOF\n";
        $this->fakePipeline(
            [$firstPdf, $secondPdf, $replacementPdf],
            ['Historical text', 'Current text', 'Replacement text'],
        );
        $editor = $this->user('Word Quota Editor', 'word-quota@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Word Quota');
        $firstBytes = "PK\x03\x04historical-document";
        $secondBytes = "PK\x03\x04current-document";
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Docx,
            title: 'Quota Document',
            description: null,
            content: $firstBytes,
            sourceFilename: 'historical.docx',
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
        $byteDelta = strlen($replacementPdf) - $currentDerivative->byte_size;
        $this->assertGreaterThan(0, $byteDelta);
        $usedBytesBefore = $workspace->refresh()->used_storage_bytes;
        config(['pages.max_page_storage_bytes' => $usedBytesBefore + $byteDelta - 1]);
        $filesBefore = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($filesBefore);
        $derivativePathBefore = $currentDerivative->storage_path;
        $derivativeHashBefore = $currentDerivative->content_hash;
        $extractedTextBefore = $currentVersion->extracted_text;
        $factsBefore = DocxVersionFact::query()->whereKey($currentVersion->uid)->sole()->getAttributes();
        $searchVectorBefore = Page::query()->whereKey($page->uid)->sole()->getRawOriginal('search_vector');

        try {
            app(ReprocessDocxArtifact::class)->handle($editor, new ReprocessDocxArtifactCommand(
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
            DocxVersionFact::query()->whereKey($currentVersion->uid)->sole()->getAttributes(),
        );
        $this->assertSame(
            $searchVectorBefore,
            Page::query()->whereKey($page->uid)->sole()->getRawOriginal('search_vector'),
        );
        $this->assertSame($usedBytesBefore, $workspace->refresh()->used_storage_bytes);
        $this->assertSame($filesBefore, $filesAfter);
        Http::assertSentCount(6);
    }

    public function test_docx_accepts_signed_processor_facts_for_many_bounded_media_parts(): void
    {
        $pdf = "%PDF-1.7\nmany images\n%%EOF\n";
        $this->fakePipeline([$pdf], ['Searchable image-heavy document'], mediaCount: 101);
        $editor = $this->user('Word Images Editor', 'word-images@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Word Images');

        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Docx,
            title: 'Image-heavy Document',
            description: null,
            content: "PK\x03\x04image-heavy-document",
            sourceFilename: 'image-heavy.docx',
            source: PageVersionSource::Upload,
        ));

        $facts = DocxVersionFact::query()->whereKey($page->current_version_uid)->sole();
        $this->assertSame(101, $facts->media_count);
        $this->assertSame('Searchable image-heavy document', $page->currentVersion?->extracted_text);
        Http::assertSentCount(2);
    }

    public function test_docx_reprocess_replaces_only_the_derivative_and_restore_reconverts_original(): void
    {
        $firstPdf = "%PDF-1.7\nfirst\n%%EOF\n";
        $reprocessedPdf = "%PDF-1.7\nreprocessed preview\n%%EOF\n";
        $secondPdf = "%PDF-1.7\nsecond\n%%EOF\n";
        $restoredPdf = "%PDF-1.7\nrestored\n%%EOF\n";
        $this->fakePipeline(
            [$firstPdf, $reprocessedPdf, $secondPdf, $restoredPdf],
            ['First text', 'Refreshed first text', 'Second text', 'Restored first text'],
        );
        $editor = $this->user('Word History Editor', 'word-history@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Word History');
        $firstBytes = "PK\x03\x04first-document";
        $secondBytes = "PK\x03\x04second-document";
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Docx,
            title: 'Document History',
            description: null,
            content: $firstBytes,
            sourceFilename: 'first.docx',
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $firstDerivative = PageVersionDerivative::query()->where('page_version_uid', $firstVersion->uid)->sole();

        $reprocessed = app(ReprocessDocxArtifact::class)->handle($editor, new ReprocessDocxArtifactCommand(
            pageUid: $page->uid,
            expectedCurrentVersionUid: $firstVersion->uid,
        ));
        $reprocessedDerivative = PageVersionDerivative::query()
            ->where('page_version_uid', $firstVersion->uid)
            ->sole();
        $this->assertSame($firstVersion->uid, $reprocessed->uid);
        $this->assertSame($firstBytes, Storage::disk('artifacts')->get($firstVersion->content_storage_path));
        $this->assertSame($reprocessedPdf, Storage::disk('artifacts')->get($reprocessedDerivative->storage_path));
        $this->assertNotSame($firstDerivative->storage_path, $reprocessedDerivative->storage_path);
        Storage::disk('artifacts')->assertMissing($firstDerivative->storage_path);
        $this->assertSame('Refreshed first text', $reprocessed->extracted_text);

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
        $this->assertSame('Restored first text', $restored->extracted_text);
        $this->assertSame($restoredPdf, Storage::disk('artifacts')->get(
            PageVersionDerivative::query()->where('page_version_uid', $restored->uid)->sole()->storage_path,
        ));
    }

    public function test_docx_reprocess_post_callback_rollback_preserves_then_reaps_the_unreferenced_preview(): void
    {
        $firstPdf = "%PDF-1.7\nfirst\n%%EOF\n";
        $replacementPdf = "%PDF-1.7\nreplacement\n%%EOF\n";
        $this->fakePipeline([$firstPdf, $replacementPdf], ['Before failed commit', 'After failed commit']);
        $editor = $this->user('Word Commit Editor', 'word-commit@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Word Commit');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Docx,
            title: 'Commit Document',
            description: null,
            content: "PK\x03\x04commit-document",
            sourceFilename: 'commit.docx',
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $derivative = PageVersionDerivative::query()->where('page_version_uid', $version->uid)->sole();
        $filesBefore = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($filesBefore);
        $manager = DB::getFacadeRoot();
        $this->assertInstanceOf(DatabaseManager::class, $manager);
        $connection = $manager->connection();
        $proxy = Mockery::mock($manager);
        $proxy->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(function (callable $callback) use ($connection, $manager): never {
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

                throw new \RuntimeException('Forced DOCX post-callback rollback.');
            });
        DB::swap($proxy);

        try {
            app(ReprocessDocxArtifact::class)->handle($editor, new ReprocessDocxArtifactCommand(
                pageUid: $page->uid,
                expectedCurrentVersionUid: $version->uid,
            ));
            $this->fail('The simulated post-callback rollback must fail DOCX reprocessing.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced DOCX post-callback rollback.', $exception->getMessage());
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

    public function test_docx_reprocess_unknown_commit_outcome_preserves_the_committed_preview(): void
    {
        $initialPdf = "%PDF-1.7\ninitial preview\n%%EOF\n";
        $replacementPdf = "%PDF-1.7\ncommitted replacement preview\n%%EOF\n";
        $this->fakePipeline([$initialPdf, $replacementPdf], ['Initial text', 'Committed replacement text']);
        $editor = $this->user('Word Ambiguous Commit Editor', 'word-ambiguous-commit@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Word Ambiguous Commit');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Docx,
            title: 'Committed Document',
            description: null,
            content: "PK\x03\x04committed-document",
            sourceFilename: 'committed.docx',
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $derivative = PageVersionDerivative::query()->where('page_version_uid', $version->uid)->sole();
        $oldPreviewPath = $derivative->storage_path;

        $message = 'SQLSTATE[08007] transaction_resolution_unknown';
        ThrowAfterCommitTransaction::install($message);
        try {
            app(ReprocessDocxArtifact::class)->handle($editor, new ReprocessDocxArtifactCommand(
                pageUid: $page->uid,
                expectedCurrentVersionUid: $version->uid,
            ));
            $this->fail('The simulated unknown DOCX reprocess outcome must propagate.');
        } catch (PDOException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertSame('08007', $exception->errorInfo[0] ?? null);
        }

        $reprocessedVersion = PageVersion::query()->whereKey($version->uid)->sole();
        $reprocessedDerivative = PageVersionDerivative::query()->whereKey($derivative->uid)->sole();
        $this->assertSame('Committed replacement text', $reprocessedVersion->extracted_text);
        $this->assertNotSame($oldPreviewPath, $reprocessedDerivative->storage_path);
        Storage::disk('artifacts')->assertExists($reprocessedVersion->content_storage_path);
        Storage::disk('artifacts')->assertExists($oldPreviewPath);
        Storage::disk('artifacts')->assertExists($reprocessedDerivative->storage_path);
    }

    public function test_docx_reprocess_rejects_missing_original_and_missing_facts_without_leaking_staging(): void
    {
        $initialPdf = "%PDF-1.7\ninitial integrity preview\n%%EOF\n";
        $replacementPdf = "%PDF-1.7\nreplacement integrity preview\n%%EOF\n";
        $this->fakePipeline([$initialPdf, $replacementPdf], ['Initial text', 'Replacement text']);
        $editor = $this->user('Word Integrity Editor', 'word-integrity@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Word Integrity');
        $docx = "PK\x03\x04integrity-document";
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Docx,
            title: 'Integrity Document',
            description: null,
            content: $docx,
            sourceFilename: 'integrity.docx',
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $command = new ReprocessDocxArtifactCommand($page->uid, $version->uid);

        Storage::disk('artifacts')->delete($version->content_storage_path);
        try {
            app(ReprocessDocxArtifact::class)->handle($editor, $command);
            $this->fail('A missing retained original must stop DOCX reprocessing before dispatch.');
        } catch (\App\Domain\DomainRuleViolation $exception) {
            $this->assertStringContainsString('failed integrity verification', $exception->getMessage());
        }
        Http::assertSentCount(2);

        Storage::disk('artifacts')->put($version->content_storage_path, $docx);
        $filesBefore = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($filesBefore);
        DocxVersionFact::query()->whereKey($version->uid)->delete();
        try {
            app(ReprocessDocxArtifact::class)->handle($editor, $command);
            $this->fail('Missing DOCX facts must fail closed after processor dispatch.');
        } catch (\App\Domain\DomainRuleViolation $exception) {
            $this->assertSame('DOCX processing facts are unavailable.', $exception->getMessage());
        }

        $filesAfter = Storage::disk('artifacts')->allFiles("pages/{$page->uid}");
        sort($filesAfter);
        $this->assertSame($filesBefore, $filesAfter);
        Http::assertSentCount(4);
    }

    public function test_docx_reprocess_records_advisory_findings_from_extracted_text(): void
    {
        $initialPdf = "%PDF-1.7\ninitial warning preview\n%%EOF\n";
        $replacementPdf = "%PDF-1.7\nreplacement warning preview\n%%EOF\n";
        $this->fakePipeline([$initialPdf, $replacementPdf], ['Initial text', 'document.cookie']);
        $editor = $this->user('Word Warning Editor', 'word-warning@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Word Warning');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Docx,
            title: 'Warning Document',
            description: null,
            content: "PK\x03\x04warning-document",
            sourceFilename: 'warning.docx',
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();

        $reprocessed = app(ReprocessDocxArtifact::class)->handle($editor, new ReprocessDocxArtifactCommand(
            pageUid: $page->uid,
            expectedCurrentVersionUid: $version->uid,
        ));

        $this->assertSame('warnings', $reprocessed->scan_status->value);
        $this->assertSame('document_cookie', $reprocessed->scan_findings[0]['code'] ?? null);
    }

    public function test_docx_rejects_a_conversion_without_searchable_text(): void
    {
        $blankPdf = "%PDF-1.7\nblank\n%%EOF\n";
        $this->fakePipeline([$blankPdf], [''], PdfExtractionState::NoEmbeddedText);
        $editor = $this->user('Word Boundary Editor', 'word-boundary@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Word Boundary');

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Docx,
                title: 'Image Only',
                description: null,
                content: "PK\x03\x04image-only",
                sourceFilename: 'image-only.docx',
                source: PageVersionSource::Upload,
            ));
            $this->fail('A non-searchable DOCX conversion must be rejected.');
        } catch (\App\Domain\DomainRuleViolation $exception) {
            $this->assertStringContainsString('searchable selectable text', $exception->getMessage());
        }
        $this->assertSame(0, Page::query()->count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_docx_hard_delete_removes_the_complete_blob_graph(): void
    {
        $pdf = "%PDF-1.7\ndelete\n%%EOF\n";
        $this->fakePipeline([$pdf], ['Delete me']);
        $editor = $this->user('Word Delete Editor', 'word-delete@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Word Delete');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Docx,
            title: 'Delete Document',
            description: null,
            content: "PK\x03\x04delete-document",
            sourceFilename: 'delete.docx',
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $derivative = PageVersionDerivative::query()->where('page_version_uid', $version->uid)->sole();
        $this->assertSame(0, Artisan::call('artifactflow:verify-artifacts', ['--all' => true, '--json' => true]));

        app(HardDeletePage::class)->handle($editor, new HardDeletePageCommand(
            pageUid: $page->uid,
            confirmation: $page->title,
        ));
        Storage::disk('artifacts')->assertMissing($version->content_storage_path);
        Storage::disk('artifacts')->assertMissing($derivative->storage_path);
        $this->assertNull(DocxVersionFact::query()->find($version->uid));
        $this->assertSame(0, Workspace::query()->findOrFail($workspace->uid)->used_storage_bytes);
    }

    public function test_docx_rejects_a_pdf_derivative_larger_than_the_configured_artifact_read_boundary(): void
    {
        config([
            'pages.artifact_max_bytes' => 64,
            'pages.max_html_bytes' => 64,
            'pages.max_markdown_bytes' => 64,
        ]);
        $oversizedPdf = "%PDF-1.7\n" . str_repeat('x', 64) . "\n%%EOF\n";
        $this->fakePipeline([$oversizedPdf], []);
        $editor = $this->user('Word Size Editor', 'word-size@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Word Size Boundary');

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Docx,
                title: 'Oversized Preview',
                description: null,
                content: "PK\x03\x04small-docx",
                sourceFilename: 'oversized-preview.docx',
                source: PageVersionSource::Upload,
            ));
            $this->fail('A DOCX derivative outside the artifact read boundary must be rejected.');
        } catch (\App\Domain\DomainRuleViolation $exception) {
            $this->assertStringContainsString('preview exceeds', $exception->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertSame(0, Page::query()->count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    /**
     * @param list<string> $previews
     * @param list<string> $texts
     */
    private function fakePipeline(
        array $previews,
        array $texts,
        PdfExtractionState $state = PdfExtractionState::Indexed,
        int $mediaCount = 0,
    ): void {
        Http::fake(function (Request $request) use (&$previews, &$texts, $state, $mediaCount): \GuzzleHttp\Promise\PromiseInterface {
            if (str_ends_with($request->url(), '/v1/docx/previews')) {
                $pdf = array_shift($previews);
                $this->assertIsString($pdf);
                $docx = $request->body();
                $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
                $this->assertIsString($nonce);
                $inputHash = hash('sha256', $docx);

                return Http::response($pdf, 200, [
                    'Cache-Control' => 'no-store',
                    'Content-Type' => DocxProcessorProtocol::OUTPUT_MEDIA_TYPE,
                    'X-Content-Type-Options' => 'nosniff',
                    'X-ArtifactFlow-Processor-Nonce' => $nonce,
                    'X-ArtifactFlow-Input-Bytes' => (string) strlen($docx),
                    'X-ArtifactFlow-Input-SHA256' => $inputHash,
                    'X-ArtifactFlow-Response-SHA256' => hash('sha256', $pdf),
                    'X-ArtifactFlow-Processor-Profile' => DocxProcessorProtocol::PROCESSOR_PROFILE,
                    'X-ArtifactFlow-Processor-Schema' => DocxProcessorProtocol::RESPONSE_SCHEMA,
                    'X-ArtifactFlow-Processor-Engine' => DocxProcessorProtocol::ENGINE_NAME,
                    'X-ArtifactFlow-Processor-Engine-Version' => DocxProcessorProtocol::ENGINE_VERSION,
                    'X-ArtifactFlow-Package-Entry-Count' => '7',
                    'X-ArtifactFlow-Package-Expanded-Bytes' => '2048',
                    'X-ArtifactFlow-Package-Relationship-Count' => '2',
                    'X-ArtifactFlow-Package-Media-Count' => (string) $mediaCount,
                    'X-ArtifactFlow-Package-External-Hyperlink-Count' => '1',
                    'X-ArtifactFlow-Processor-Signature' => DocxProcessorProtocol::responseSignature(
                        $nonce,
                        strlen($docx),
                        $inputHash,
                        $pdf,
                        7,
                        2_048,
                        2,
                        $mediaCount,
                        1,
                        self::DOCX_SECRET,
                    ),
                ]);
            }

            $text = array_shift($texts);
            $this->assertIsString($text);
            $pdf = $request->body();
            $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $this->assertIsString($nonce);
            $this->assertStringEndsWith('/v1/inspect-docx-preview', $request->url());
            $this->assertSame(PdfProcessorProtocol::DOCX_PREVIEW_PROFILE, $request->header('X-ArtifactFlow-Processor-Profile')[0] ?? null);
            $body = json_encode([
                'page_count' => 1,
                'pdf_version' => '1.7',
                'extraction_state' => $state->value,
                'processor_profile' => PdfProcessingResult::DOCX_PREVIEW_PROCESSOR_PROFILE,
                'text' => $text,
            ], JSON_THROW_ON_ERROR);

            return Http::response($body, 200, [
                'Content-Type' => 'application/json',
                'X-ArtifactFlow-Processor-Signature' => PdfProcessorProtocol::responseSignature(
                    $nonce,
                    hash('sha256', $pdf),
                    $body,
                    self::PDF_SECRET,
                    PdfProcessorProtocol::DOCX_PREVIEW_PROFILE,
                ),
            ]);
        });
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
