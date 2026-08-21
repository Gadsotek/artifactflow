<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpReadTool;
use App\Application\Mcp\McpSearchTool;
use App\Application\Mcp\McpToolArguments;
use App\Application\PageCatalog\ArtifactPreviewUrl;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\PageSearchVectorUpdater;
use App\Application\PageCatalog\ReindexSearchText;
use App\Application\PageCatalog\RestorePageVersion;
use App\Application\PageCatalog\RestorePageVersionCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\PdfProcessingUnavailable;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PageVersionIngest;
use App\Models\PdfVersionFact;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class PdfArtifactWriteTest extends TestCase
{
    use RefreshDatabase;

    private const string SHARED_SECRET = 'test-pdf-processor-shared-secret-0001';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        config([
            'pdf_processor.enabled' => true,
            'pdf_processor.url' => 'http://pdf-processor.test',
            'pdf_processor.shared_secret' => self::SHARED_SECRET,
            'pdf_processor.connect_timeout_seconds' => 2,
            'pdf_processor.timeout_seconds' => 15,
        ]);
    }

    public function test_editor_creates_pdf_with_original_text_facts_and_traceability_atomically(): void
    {
        $pdf = "%PDF-1.7\noriginal-pdf-bytes\n%%EOF";
        $transactionLevels = [];
        $this->fakeProcessor(
            text: "Quarterly café report\n<script>alert(1)</script>",
            pageCount: 2,
            transactionLevels: $transactionLevels,
        );
        $editor = $this->user('PDF Editor', 'pdf-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Team');
        $baselineTransactionLevel = DB::transactionLevel();

        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Quarterly Report',
            description: null,
            content: $pdf,
            sourceFilename: 'quarterly-report.pdf',
            source: PageVersionSource::Upload,
        ));

        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $facts = PdfVersionFact::query()->whereKey($version->uid)->sole();

        $this->assertSame([$baselineTransactionLevel], $transactionLevels);
        $this->assertSame(PageType::Pdf, $page->type);
        $this->assertSame(hash('sha256', $pdf), $version->content_hash);
        $this->assertSame(strlen($pdf), $version->byte_size);
        $this->assertSame($pdf, Storage::disk('artifacts')->get($version->content_storage_path));
        $this->assertStringContainsString('Quarterly café report', (string) $version->extracted_text);
        $this->assertNull($version->source_text);
        $this->assertSame(2, $facts->page_count);
        $this->assertSame('1.7', $facts->pdf_version);
        $this->assertSame('indexed', $facts->extraction_state->value);
        $this->assertSame('pdfbox-3.0.8-native-text-v1', $facts->processor_profile);
        $this->assertSame('warnings', $version->scan_status->value);
        $this->assertSame(strlen($pdf), $workspace->refresh()->used_storage_bytes);

        $event = DomainEvent::query()->where('event_type', 'page.version.created')->sole();
        $this->assertSame('indexed', $event->payload['pdf_extraction_state'] ?? null);
        $this->assertArrayNotHasKey('pdf_page_count', $event->payload);
        $this->assertArrayNotHasKey('pdf_processor_profile', $event->payload);
        $this->assertArrayNotHasKey('pdf_version', $event->payload);
        $audit = AuditEntry::query()->where('action', 'page.version.created')->sole();
        $this->assertSame('indexed', $audit->metadata['pdf_extraction_state'] ?? null);
        $this->assertArrayNotHasKey('pdf_page_count', $audit->metadata);
        $this->assertArrayNotHasKey('pdf_processor_profile', $audit->metadata);
        $this->assertArrayNotHasKey('pdf_version', $audit->metadata);
    }

    public function test_pdf_original_is_privately_staged_before_the_transaction_acquires_catalog_locks(): void
    {
        $this->fakeProcessor('Staged PDF text');
        $editor = $this->user('PDF Staging Editor', 'pdf-staging@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Staging Team');
        $baselineTransactionLevel = DB::transactionLevel();
        $stagingObservedBeforeFirstTransactionalQuery = null;

        DB::listen(function () use (
            $baselineTransactionLevel,
            &$stagingObservedBeforeFirstTransactionalQuery,
        ): void {
            if (
                $stagingObservedBeforeFirstTransactionalQuery !== null
                || DB::transactionLevel() <= $baselineTransactionLevel
            ) {
                return;
            }

            $stagingObservedBeforeFirstTransactionalQuery = collect(Storage::disk('artifacts')->allFiles())
                ->contains(static fn (string $path): bool => str_starts_with($path, 'staging/pdf/'));
        });

        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Staged PDF',
            description: null,
            content: "%PDF-1.7\nstaged\n%%EOF",
            sourceFilename: 'staged.pdf',
            source: PageVersionSource::Upload,
        ));

        $this->assertTrue($stagingObservedBeforeFirstTransactionalQuery);
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        Storage::disk('artifacts')->assertExists($version->content_storage_path);
        $this->assertSame(
            [],
            array_values(array_filter(
                Storage::disk('artifacts')->allFiles(),
                static fn (string $path): bool => str_starts_with($path, 'staging/pdf/'),
            )),
        );
    }

    public function test_application_text_cap_downgrades_indexed_pdf_to_partially_indexed(): void
    {
        $text = str_repeat('x', PageSearchVectorUpdater::MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS + 1);
        $this->fakeProcessor($text, state: 'indexed');
        $editor = $this->user('PDF Truncation Editor', 'pdf-truncation@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Truncation Team');

        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Truncated PDF',
            description: null,
            content: "%PDF-1.7\ntruncated\n%%EOF",
            sourceFilename: 'truncated.pdf',
            source: PageVersionSource::Upload,
        ));

        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $this->assertSame(
            PageSearchVectorUpdater::MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS,
            mb_strlen((string) $version->extracted_text),
        );
        $this->assertSame(
            'partially_indexed',
            PdfVersionFact::query()->whereKey($version->uid)->sole()->extraction_state->value,
        );
    }

    public function test_application_pdf_writes_cannot_exceed_the_artifact_read_ceiling(): void
    {
        config([
            'pages.artifact_max_bytes' => 32,
            'pages.max_html_bytes' => 32,
            'pages.max_markdown_bytes' => 32,
        ]);
        Http::fake();
        $editor = $this->user('PDF Application Ceiling Editor', 'pdf-application-ceiling@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Application Ceiling Team');

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Pdf,
                title: 'Oversized Application PDF',
                description: null,
                content: "%PDF-1.7\n" . str_repeat('x', 40) . "\n%%EOF",
                sourceFilename: 'oversized.pdf',
                source: PageVersionSource::Mcp,
            ));
            $this->fail('PDF writes above the read ceiling must be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('PDF exceeds the configured size limit.', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, Page::query()->count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_replace_preserves_historical_facts_and_updates_only_current_search_text(): void
    {
        $firstPdf = "%PDF-1.4\nscan\n%%EOF";
        $secondPdf = "%PDF-2.0\nreplacement\n%%EOF";
        $responses = [
            ['text' => '', 'pages' => 1, 'version' => '1.4', 'state' => 'no_embedded_text'],
            ['text' => 'Replacement searchable text', 'pages' => 3, 'version' => '2.0', 'state' => 'partially_indexed'],
        ];
        $this->fakeProcessorSequence($responses);
        $editor = $this->user('PDF Replacer', 'pdf-replacer@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Replace Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Scanned Contract',
            description: null,
            content: $firstPdf,
            sourceFilename: 'contract.pdf',
            source: PageVersionSource::Upload,
        ));
        $firstVersionUid = (string) $page->current_version_uid;

        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: $secondPdf,
            baseVersionUid: $firstVersionUid,
            source: PageVersionSource::Upload,
        ));

        $firstVersion = PageVersion::query()->findOrFail($firstVersionUid);
        $this->assertNull($firstVersion->extracted_text);
        $this->assertSame('Replacement searchable text', $secondVersion->extracted_text);
        $this->assertSame(2, PdfVersionFact::query()->count());
        $this->assertSame(
            'no_embedded_text',
            PdfVersionFact::query()->findOrFail($firstVersionUid)->extraction_state->value,
        );
        $this->assertSame(
            'partially_indexed',
            PdfVersionFact::query()->findOrFail($secondVersion->uid)->extraction_state->value,
        );
        $this->assertSame($secondPdf, Storage::disk('artifacts')->get($secondVersion->content_storage_path));
    }

    public function test_restore_reprocesses_the_historical_original_outside_the_transaction(): void
    {
        $transactionLevels = [];
        $this->fakeProcessorSequence([
            ['text' => 'Original extraction', 'pages' => 1, 'version' => '1.4', 'state' => 'indexed'],
            ['text' => 'Replacement extraction', 'pages' => 2, 'version' => '1.7', 'state' => 'indexed'],
            ['text' => 'Restored with the current profile', 'pages' => 3, 'version' => '1.4', 'state' => 'partially_indexed'],
        ], $transactionLevels);
        $editor = $this->user('PDF Restore Editor', 'pdf-restore@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Restore Team');
        $baselineTransactionLevel = DB::transactionLevel();
        $originalPdf = "%PDF-1.4\noriginal\n%%EOF";
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Restorable PDF',
            description: null,
            content: $originalPdf,
            sourceFilename: 'restorable.pdf',
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: "%PDF-1.7\nreplacement\n%%EOF",
            baseVersionUid: $firstVersion->uid,
            source: PageVersionSource::Upload,
        ));

        $restored = app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
            pageUid: $page->uid,
            versionUid: $firstVersion->uid,
            expectedCurrentVersionUid: $secondVersion->uid,
        ));

        $this->assertSame([
            $baselineTransactionLevel,
            $baselineTransactionLevel,
            $baselineTransactionLevel,
        ], $transactionLevels);
        $this->assertSame(3, $restored->version_number);
        $this->assertSame(PageVersionSource::Restore, $restored->source);
        $this->assertSame($firstVersion->content_hash, $restored->content_hash);
        $this->assertSame($originalPdf, Storage::disk('artifacts')->get($restored->content_storage_path));
        $this->assertSame('Restored with the current profile', $restored->extracted_text);
        $this->assertSame($restored->uid, $page->refresh()->current_version_uid);
        $this->assertSame(3, PdfVersionFact::query()->count());
        $restoredFacts = PdfVersionFact::query()->findOrFail($restored->uid);
        $this->assertSame(3, $restoredFacts->page_count);
        $this->assertSame('partially_indexed', $restoredFacts->extraction_state->value);
        $this->assertSame('pdfbox-3.0.8-native-text-v1', $restoredFacts->processor_profile);
        $restoredIngest = PageVersionIngest::query()->where('page_version_uid', $restored->uid)->sole();
        $this->assertSame('restore', $restoredIngest->operation->value);
        $this->assertSame($firstVersion->uid, $restoredIngest->derived_from_version_uid);
        $this->assertSame($firstVersion->uid, $restoredIngest->content_equivalent_to_version_uid);
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.version.restored')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'page.version.restored')->count());
    }

    public function test_pdf_restore_authorizes_before_processing_and_rescans_current_extraction(): void
    {
        $this->fakeProcessorSequence([
            ['text' => 'Previously accepted extraction', 'pages' => 1, 'version' => '1.4', 'state' => 'indexed'],
            ['text' => 'Current extraction', 'pages' => 1, 'version' => '1.7', 'state' => 'indexed'],
            ['text' => 'api_key=abcdefghijklmnopqrstuvwx', 'pages' => 1, 'version' => '1.4', 'state' => 'indexed'],
        ]);
        $editor = $this->user('PDF Secure Restore Editor', 'pdf-secure-restore@example.test');
        $outsider = $this->user('PDF Restore Outsider', 'pdf-restore-outsider@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Secure Restore Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Secure Restorable PDF',
            description: null,
            content: "%PDF-1.4\nfirst\n%%EOF",
            sourceFilename: 'secure-restorable.pdf',
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: "%PDF-1.7\nsecond\n%%EOF",
            baseVersionUid: $firstVersion->uid,
            source: PageVersionSource::Upload,
        ));
        $storedFiles = Storage::disk('artifacts')->allFiles();

        try {
            app(RestorePageVersion::class)->handle($outsider, new RestorePageVersionCommand(
                pageUid: $page->uid,
                versionUid: $firstVersion->uid,
                expectedCurrentVersionUid: $secondVersion->uid,
            ));
            $this->fail('An unauthorized actor must not dispatch PDF restore processing.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        Http::assertSentCount(2);

        try {
            app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
                pageUid: $page->uid,
                versionUid: $firstVersion->uid,
                expectedCurrentVersionUid: $secondVersion->uid,
            ));
            $this->fail('A newly blocked extraction must stop a PDF restore.');
        } catch (BlockedPageContentException $exception) {
            $this->assertContains('credential_assignment', $exception->findingCodes());
        }

        Http::assertSentCount(3);
        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(2, PdfVersionFact::query()->count());
        $this->assertSame($storedFiles, Storage::disk('artifacts')->allFiles());
        $this->assertSame($secondVersion->uid, $page->refresh()->current_version_uid);
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.secret_scan.blocked')->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.version.restored')->count());
    }

    public function test_pdf_restore_rejects_a_corrupted_historical_original_before_processing(): void
    {
        $this->fakeProcessorSequence([
            ['text' => 'Original extraction', 'pages' => 1, 'version' => '1.4', 'state' => 'indexed'],
            ['text' => 'Current extraction', 'pages' => 1, 'version' => '1.7', 'state' => 'indexed'],
        ]);
        $editor = $this->user('PDF Integrity Restore Editor', 'pdf-integrity-restore@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Integrity Restore Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Integrity Restorable PDF',
            description: null,
            content: "%PDF-1.4\nfirst\n%%EOF",
            sourceFilename: 'integrity-restorable.pdf',
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: "%PDF-1.7\nsecond\n%%EOF",
            baseVersionUid: $firstVersion->uid,
            source: PageVersionSource::Upload,
        ));
        Storage::disk('artifacts')->put($firstVersion->content_storage_path, "%PDF-1.4\ntampered\n%%EOF");

        try {
            app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
                pageUid: $page->uid,
                versionUid: $firstVersion->uid,
                expectedCurrentVersionUid: $secondVersion->uid,
            ));
            $this->fail('A corrupted historical original must not be restored.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Version content failed integrity verification.', $exception->getMessage());
        }

        Http::assertSentCount(2);
        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(2, PdfVersionFact::query()->count());
        $this->assertSame($secondVersion->uid, $page->refresh()->current_version_uid);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.version.restored')->count());
    }

    public function test_stale_pdf_restore_is_rejected_before_processor_dispatch_without_partial_state(): void
    {
        $this->fakeProcessorSequence([
            ['text' => 'First extraction', 'pages' => 1, 'version' => '1.4', 'state' => 'indexed'],
            ['text' => 'Second extraction', 'pages' => 1, 'version' => '1.7', 'state' => 'indexed'],
        ]);
        $editor = $this->user('PDF Stale Restore Editor', 'pdf-stale-restore@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Stale Restore Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Stale Restorable PDF',
            description: null,
            content: "%PDF-1.4\nfirst\n%%EOF",
            sourceFilename: 'stale-restorable.pdf',
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: "%PDF-1.7\nsecond\n%%EOF",
            baseVersionUid: $firstVersion->uid,
            source: PageVersionSource::Upload,
        ));
        $storedFiles = Storage::disk('artifacts')->allFiles();

        try {
            app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
                pageUid: $page->uid,
                versionUid: $firstVersion->uid,
                expectedCurrentVersionUid: $firstVersion->uid,
            ));
            $this->fail('A stale PDF restore must be rejected.');
        } catch (StalePageVersionException $exception) {
            $this->assertSame($secondVersion->uid, $exception->currentVersionUid);
        }

        Http::assertSentCount(2);
        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(2, PdfVersionFact::query()->count());
        $this->assertSame($storedFiles, Storage::disk('artifacts')->allFiles());
        $this->assertSame($secondVersion->uid, $page->refresh()->current_version_uid);
    }

    public function test_failed_pdf_restore_processing_leaves_the_current_version_and_storage_unchanged(): void
    {
        $this->fakeProcessorSequence([
            ['text' => 'First extraction', 'pages' => 1, 'version' => '1.4', 'state' => 'indexed'],
            ['text' => 'Second extraction', 'pages' => 1, 'version' => '1.7', 'state' => 'indexed'],
        ]);
        $editor = $this->user('PDF Failed Restore Editor', 'pdf-failed-restore@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Failed Restore Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Failed Restorable PDF',
            description: null,
            content: "%PDF-1.4\nfirst\n%%EOF",
            sourceFilename: 'failed-restorable.pdf',
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: "%PDF-1.7\nsecond\n%%EOF",
            baseVersionUid: $firstVersion->uid,
            source: PageVersionSource::Upload,
        ));
        $storedFiles = Storage::disk('artifacts')->allFiles();
        Http::fake([
            '*' => Http::response(['error' => 'service_unavailable'], 503),
        ]);

        try {
            app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
                pageUid: $page->uid,
                versionUid: $firstVersion->uid,
                expectedCurrentVersionUid: $secondVersion->uid,
            ));
            $this->fail('An unavailable processor must fail the PDF restore.');
        } catch (PdfProcessingUnavailable) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(2, PdfVersionFact::query()->count());
        $this->assertSame($storedFiles, Storage::disk('artifacts')->allFiles());
        $this->assertSame($secondVersion->uid, $page->refresh()->current_version_uid);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.version.restored')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'page.version.restored')->count());
    }

    public function test_extracted_secret_blocks_create_without_partial_page_version_fact_or_blob(): void
    {
        $this->fakeProcessor('api_key=abcdefghijklmnopqrstuvwx');
        $editor = $this->user('PDF Security Editor', 'pdf-security@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Security Team');

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Pdf,
                title: 'Leaking PDF',
                description: null,
                content: "%PDF-1.7\n%%EOF",
                sourceFilename: 'leak.pdf',
                source: PageVersionSource::Upload,
            ));
            $this->fail('Extracted credentials must block the PDF write.');
        } catch (BlockedPageContentException $exception) {
            $this->assertContains('credential_assignment', $exception->findingCodes());
        }

        $this->assertSame(0, Page::query()->count());
        $this->assertSame(0, PageVersion::query()->count());
        $this->assertSame(0, PdfVersionFact::query()->count());
        Storage::disk('artifacts')->assertDirectoryEmpty('pages');
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.secret_scan.blocked')->count());
    }

    public function test_authorization_and_filename_checks_happen_before_processor_dispatch(): void
    {
        Http::fake();
        $owner = $this->user('PDF Owner', 'pdf-owner@example.test');
        $reader = $this->user('PDF Reader', 'pdf-reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'PDF Authorization Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        try {
            app(CreatePage::class)->handle($reader, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Pdf,
                title: 'Unauthorized PDF',
                description: null,
                content: "%PDF-1.7\n%%EOF",
                sourceFilename: 'document.pdf',
                source: PageVersionSource::Upload,
            ));
            $this->fail('A workspace reader must not create a PDF.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        try {
            app(CreatePage::class)->handle($owner, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Pdf,
                title: 'Wrong Extension',
                description: null,
                content: "%PDF-1.7\n%%EOF",
                sourceFilename: 'document.html',
                source: PageVersionSource::Upload,
            ));
            $this->fail('A PDF upload must use the PDF extension.');
        } catch (\App\Domain\DomainRuleViolation $exception) {
            $this->assertSame('PDF uploads must use a .pdf file.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_html_with_javascript_renamed_to_pdf_is_rejected_before_processor_dispatch(): void
    {
        Http::fake();
        $editor = $this->user('PDF Confusion Editor', 'pdf-confusion@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Confusion Team');
        $renamedHtml = '<!doctype html><script>fetch("https://attacker.invalid")</script>';

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Pdf,
                title: 'Renamed HTML',
                description: null,
                content: $renamedHtml,
                sourceFilename: 'renamed-attack.pdf',
                source: PageVersionSource::Upload,
            ));
            $this->fail('HTML renamed to PDF must not reach the processor or storage.');
        } catch (\App\Domain\DomainRuleViolation $exception) {
            $this->assertSame(
                'PDF content must start with a PDF document header.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
        $this->assertSame(0, Page::query()->count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_pdf_web_preview_mcp_and_legacy_preview_surfaces_obey_their_distinct_boundaries(): void
    {
        $this->assertTrue(PageType::Pdf->usesArtifactHostPreview());
        $editor = $this->user('PDF Boundary Editor', 'pdf-boundary@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Boundary Team');

        $this->fakeProcessor('Internal PDF text');
        $pdf = "%PDF-1.7\nprivate-pdf-body\n%%EOF";
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Internal PDF Boundary',
            description: null,
            content: $pdf,
            sourceFilename: 'internal.pdf',
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('PDF artifact')
            ->assertSee('Replace PDF')
            ->assertSee('title="PDF preview"', false)
            ->assertSee('data-external-share-management', false)
            ->assertDontSee($pdf, false);

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}/versions/{$version->uid}")
            ->assertOk()
            ->assertSee('title="Historical PDF preview"', false)
            ->assertSee('Binary PDF versions do not have a source diff.')
            ->assertDontSee($pdf, false);

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}/artifact-preview-url")
            ->assertOk()
            ->assertJsonPath('url', fn (string $url): bool => str_contains($url, '/pdf-artifacts/'));
        $this->actingAs($editor)
            ->get("/pages/{$page->uid}/versions/{$version->uid}/artifact-preview-url")
            ->assertOk()
            ->assertJsonPath('url', fn (string $url): bool => str_contains($url, '/pdf-artifacts/'));
        $mcp = app(McpReadTool::class)->handle(
            $editor,
            McpToolArguments::fromValue(['page_uid' => $page->uid], 'arguments'),
        );
        $this->assertFalse($mcp->isError);
        $this->assertSame('Internal PDF text', data_get($mcp->payload, 'content.data'));
        $this->assertSame('indexed', data_get($mcp->payload, 'pdf.extraction_state'));
        $this->assertFalse((bool) data_get($mcp->payload, 'pdf.ocr_indexed'));
        $mcpJson = json_encode($mcp->payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($pdf, $mcpJson);
        $this->assertStringNotContainsString('content_storage_path', $mcpJson);
        $this->assertStringNotContainsString('processor_profile', $mcpJson);

        $service = $this->user('PDF Boundary Agent', 'pdf-boundary-agent@example.test');
        $service->forceFill(['is_service_account' => true])->save();
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $service->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);
        $token = app(McpAccessTokenIssuer::class)->issue(
            principal: $service,
            name: 'PDF boundary search token',
            scopes: ['mcp:search', 'mcp:read'],
            expiresAt: Carbon::now()->addHour(),
        )->accessToken;
        $search = app(McpSearchTool::class)->handle(
            $service,
            $token,
            McpToolArguments::fromValue([
                'query' => 'Internal PDF text',
                'include_snippet' => true,
            ], 'arguments'),
        );
        $this->assertFalse($search->isError);
        $this->assertSame($page->uid, data_get($search->payload, 'results.0.uid'));
        $this->assertSame('Internal PDF text', data_get($search->payload, 'results.0.snippet.data'));

        $typedSearch = app(McpSearchTool::class)->handle(
            $service,
            $token,
            McpToolArguments::fromValue(['type' => PageType::Pdf->value], 'arguments'),
        );
        $this->assertFalse($typedSearch->isError);
        $this->assertSame($page->uid, data_get($typedSearch->payload, 'results.0.uid'));

        config([
            'app.artifact_url' => 'http://artifacts.example.test',
            'app.artifact_url_signing_key' => str_repeat('p', 32),
        ]);
        $previewUrl = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);
        config(['app.runtime_role' => 'artifact-host']);

        $this->withHeader('Sec-Fetch-Dest', 'iframe')
            ->get($previewUrl)
            ->assertNotFound()
            ->assertDontSee($pdf, false);
    }

    public function test_pdf_transaction_failure_rolls_back_page_version_facts_quota_and_blob(): void
    {
        $this->fakeProcessor('Rollback PDF text');
        $editor = $this->user('PDF Rollback Editor', 'pdf-rollback@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Rollback Team');
        $eventName = 'eloquent.creating: ' . DomainEvent::class;

        Event::listen($eventName, static function (DomainEvent $event): void {
            if ($event->event_type === 'page.version.created') {
                throw new RuntimeException('Forced PDF version event persistence failure.');
            }
        });

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Pdf,
                title: 'Rollback PDF',
                description: null,
                content: "%PDF-1.7\nrollback\n%%EOF",
                sourceFilename: 'rollback.pdf',
                source: PageVersionSource::Upload,
            ));
            $this->fail('A failed PDF version event must roll back the complete write.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced PDF version event persistence failure.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame(0, Page::query()->count());
        $this->assertSame(0, PageVersion::query()->count());
        $this->assertSame(0, PdfVersionFact::query()->count());
        $this->assertSame(0, $workspace->refresh()->used_storage_bytes);
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_stale_pdf_replace_does_not_persist_a_third_version_fact_or_blob(): void
    {
        $this->fakeProcessorSequence([
            ['text' => 'First text', 'pages' => 1, 'version' => '1.7', 'state' => 'indexed'],
            ['text' => 'Second text', 'pages' => 1, 'version' => '1.7', 'state' => 'indexed'],
            ['text' => 'Stale text', 'pages' => 1, 'version' => '1.7', 'state' => 'indexed'],
        ]);
        $editor = $this->user('PDF Concurrency Editor', 'pdf-concurrency@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Concurrency Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Concurrent PDF',
            description: null,
            content: "%PDF-1.7\nfirst\n%%EOF",
            sourceFilename: 'concurrent.pdf',
            source: PageVersionSource::Upload,
        ));
        $firstVersionUid = (string) $page->current_version_uid;

        app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: "%PDF-1.7\nsecond\n%%EOF",
            baseVersionUid: $firstVersionUid,
            source: PageVersionSource::Upload,
        ));
        $storedFiles = Storage::disk('artifacts')->allFiles();

        try {
            app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: "%PDF-1.7\nstale\n%%EOF",
                baseVersionUid: $firstVersionUid,
                source: PageVersionSource::Upload,
            ));
            $this->fail('A stale PDF replacement must be rejected.');
        } catch (StalePageVersionException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(2, PdfVersionFact::query()->count());
        $this->assertSame($storedFiles, Storage::disk('artifacts')->allFiles());
    }

    public function test_generic_search_reindex_uses_existing_pdf_text_without_processing_inside_its_transaction(): void
    {
        $this->fakeProcessor('reindexpdfneedle');
        $editor = $this->user('PDF Reindex Editor', 'pdf-reindex@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Reindex Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'PDF Reindex Boundary',
            description: null,
            content: "%PDF-1.7\nreindex\n%%EOF",
            sourceFilename: 'reindex.pdf',
            source: PageVersionSource::Upload,
        ));
        DB::table('pages')
            ->where('uid', $page->uid)
            ->update(['search_vector' => DB::raw("''::tsvector")]);

        $result = app(ReindexSearchText::class)->handle(pageUid: $page->uid);
        $this->assertSame(1, $result->pagesProcessed);
        $this->assertSame(1, $result->versionsExamined);
        $this->assertSame(0, $result->versionsChanged);
        $this->assertSame(1, $result->versionsSkipped);
        $this->assertFalse($result->dryRun);

        Http::assertSentCount(1);
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $this->assertSame('reindexpdfneedle', $version->extracted_text);
        $this->assertSame(
            1,
            Page::query()
                ->whereKey($page->uid)
                ->whereRaw("search_vector @@ websearch_to_tsquery('simple', ?)", ['reindexpdfneedle'])
                ->count(),
        );
        $this->assertTrue(app(PageAccess::class)->canView($editor, $page));
        $this->actingAs($editor)
            ->get('/pages?workspace_uid=all&q=reindexpdfneedle')
            ->assertOk()
            ->assertSee('PDF Reindex Boundary');
    }

    /**
     * @param list<int> $transactionLevels
     */
    private function fakeProcessor(
        string $text,
        int $pageCount = 1,
        string $pdfVersion = '1.7',
        string $state = 'indexed',
        array &$transactionLevels = [],
    ): void {
        $this->fakeProcessorSequence(
            [[
                'text' => $text,
                'pages' => $pageCount,
                'version' => $pdfVersion,
                'state' => $state,
            ]],
            $transactionLevels,
        );
    }

    /**
     * @param list<array{text: string, pages: int, version: string, state: string}> $responses
     * @param list<int> $transactionLevels
     */
    private function fakeProcessorSequence(array $responses, array &$transactionLevels = []): void
    {
        Http::fake(function (Request $request) use (&$responses, &$transactionLevels): \GuzzleHttp\Promise\PromiseInterface {
            $transactionLevels[] = DB::transactionLevel();
            $next = array_shift($responses);
            $this->assertIsArray($next);
            $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $this->assertIsString($nonce);
            $body = json_encode([
                'page_count' => $next['pages'],
                'pdf_version' => $next['version'],
                'extraction_state' => $next['state'],
                'processor_profile' => 'pdfbox-3.0.8-native-text-v1',
                'text' => $next['text'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            return Http::response($body, 200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-ArtifactFlow-Processor-Signature' => hash_hmac('sha256', implode("\n", [
                    'artifactflow-pdf-processor-response-v1',
                    $nonce,
                    hash('sha256', $request->body()),
                    hash('sha256', $body),
                ]), self::SHARED_SECRET),
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
