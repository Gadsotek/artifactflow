<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\PageSearchVectorUpdater;
use App\Application\PageCatalog\ReprocessPdfArtifact;
use App\Application\PageCatalog\ReprocessPdfArtifactCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\PdfProcessingUnavailable;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PdfVersionFact;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class PdfArtifactReprocessTest extends TestCase
{
    use RefreshDatabase;

    private const string PROCESSOR_SECRET = 'test-pdf-reprocess-processor-secret-0001';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        config([
            'pdf_processor.enabled' => true,
            'pdf_processor.url' => 'http://pdf-processor.test',
            'pdf_processor.shared_secret' => self::PROCESSOR_SECRET,
            'pdf_processor.connect_timeout_seconds' => 2,
            'pdf_processor.timeout_seconds' => 15,
        ]);
    }

    public function test_reprocess_replaces_only_current_derived_facts_outside_the_transaction(): void
    {
        $transactionLevels = [];
        $this->fakeProcessorSequence([
            ['text' => 'oldpdfneedle', 'pages' => 1, 'version' => '1.4', 'state' => 'indexed'],
            [
                'text' => "newpdfneedle\n<script>alert(1)</script>",
                'pages' => 3,
                'version' => '1.7',
                'state' => 'partially_indexed',
            ],
        ], $transactionLevels);
        $editor = $this->user('PDF Reprocess Editor', 'pdf-reprocess-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Reprocess Team');
        $pdf = "%PDF-1.4\nimmutable-original\n%%EOF";
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Reprocessable PDF',
            description: null,
            content: $pdf,
            sourceFilename: 'reprocessable.pdf',
            source: PageVersionSource::Upload,
            status: PageStatus::Approved,
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $originalVersion = $version->getAttributes();
        $originalFiles = Storage::disk('artifacts')->allFiles();
        $originalQuota = $workspace->refresh()->used_storage_bytes;
        $baselineTransactionLevel = DB::transactionLevel();

        $reprocessed = app(ReprocessPdfArtifact::class)->handle($editor, new ReprocessPdfArtifactCommand(
            pageUid: $page->uid,
            expectedCurrentVersionUid: $version->uid,
        ));

        $version->refresh();
        $facts = PdfVersionFact::query()->whereKey($version->uid)->sole();
        $page->refresh();

        $this->assertSame([$baselineTransactionLevel, $baselineTransactionLevel], $transactionLevels);
        $this->assertSame($version->uid, $reprocessed->uid);
        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
        foreach ([
            'uid',
            'page_uid',
            'version_number',
            'content_storage_path',
            'content_hash',
            'byte_size',
            'source',
            'change_summary',
            'created_by_user_uid',
            'source_text',
            'created_at',
        ] as $attribute) {
            $this->assertSame($originalVersion[$attribute], $version->getAttributes()[$attribute]);
        }
        $this->assertSame($pdf, Storage::disk('artifacts')->get($version->content_storage_path));
        $this->assertSame($originalFiles, Storage::disk('artifacts')->allFiles());
        $this->assertSame($originalQuota, $workspace->refresh()->used_storage_bytes);
        $this->assertSame($version->uid, $page->current_version_uid);
        $this->assertSame(PageStatus::Approved, $page->status);
        $this->assertSame("newpdfneedle\n<script>alert(1)</script>", $version->extracted_text);
        $this->assertSame('warnings', $version->scan_status->value);
        $this->assertSame('inline_script', $version->scan_findings[0]['code'] ?? null);
        $this->assertSame(3, $facts->page_count);
        $this->assertSame('1.7', $facts->pdf_version);
        $this->assertSame('partially_indexed', $facts->extraction_state->value);
        $this->assertSame('pdfbox-3.0.8-native-text-v1', $facts->processor_profile);
        $this->assertSame(1, Page::query()
            ->whereKey($page->uid)
            ->whereRaw("search_vector @@ websearch_to_tsquery('simple', ?)", ['newpdfneedle'])
            ->count());
        $this->assertSame(0, Page::query()
            ->whereKey($page->uid)
            ->whereRaw("search_vector @@ websearch_to_tsquery('simple', ?)", ['oldpdfneedle'])
            ->count());

        $event = DomainEvent::query()->where('event_type', 'page.pdf.reprocessed')->sole();
        $audit = AuditEntry::query()->where('action', 'page.pdf.reprocessed')->sole();
        $this->assertSame($version->uid, $event->payload['page_version_uid'] ?? null);
        $this->assertSame('partially_indexed', $event->payload['pdf_extraction_state'] ?? null);
        $this->assertSame('warnings', $audit->metadata['scan_status'] ?? null);
        $this->assertStringNotContainsString('newpdfneedle', json_encode([
            $event->payload,
            $audit->metadata,
        ], JSON_THROW_ON_ERROR));
        $this->assertSame(1, DomainEvent::query()
            ->where('event_type', 'page.security_warnings.recorded')
            ->count());
    }

    public function test_reprocess_marks_application_truncated_extraction_as_partial(): void
    {
        $text = str_repeat('y', PageSearchVectorUpdater::MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS + 1);
        $this->fakeProcessorSequence([
            ['text' => 'initial extraction', 'pages' => 1, 'version' => '1.4', 'state' => 'indexed'],
            ['text' => $text, 'pages' => 2, 'version' => '1.7', 'state' => 'indexed'],
        ]);
        $editor = $this->user('PDF Reprocess Truncation Editor', 'pdf-reprocess-truncation@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Reprocess Truncation');
        $page = $this->createPdf($editor, $workspace->uid);
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();

        app(ReprocessPdfArtifact::class)->handle($editor, new ReprocessPdfArtifactCommand(
            pageUid: $page->uid,
            expectedCurrentVersionUid: $version->uid,
        ));

        $this->assertSame(
            PageSearchVectorUpdater::MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS,
            mb_strlen((string) $version->refresh()->extracted_text),
        );
        $this->assertSame(
            'partially_indexed',
            PdfVersionFact::query()->whereKey($version->uid)->sole()->extraction_state->value,
        );
        $event = DomainEvent::query()->where('event_type', 'page.pdf.reprocessed')->sole();
        $this->assertSame('partially_indexed', $event->payload['pdf_extraction_state'] ?? null);
    }

    public function test_stale_or_unauthorized_reprocess_never_dispatches_processor_work(): void
    {
        $this->fakeProcessorSequence([
            ['text' => 'initial extraction', 'pages' => 1, 'version' => '1.4', 'state' => 'indexed'],
        ]);
        $editor = $this->user('PDF Reprocess Owner', 'pdf-reprocess-owner@example.test');
        $outsider = $this->user('PDF Reprocess Outsider', 'pdf-reprocess-outsider@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Reprocess Authorization');
        $page = $this->createPdf($editor, $workspace->uid);
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();

        try {
            app(ReprocessPdfArtifact::class)->handle($outsider, new ReprocessPdfArtifactCommand(
                pageUid: $page->uid,
                expectedCurrentVersionUid: $version->uid,
            ));
            $this->fail('An unauthorized reprocess must be rejected.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        try {
            app(ReprocessPdfArtifact::class)->handle($editor, new ReprocessPdfArtifactCommand(
                pageUid: $page->uid,
                expectedCurrentVersionUid: '01J00000000000000000000000',
            ));
            $this->fail('A stale reprocess must be rejected.');
        } catch (StalePageVersionException $exception) {
            $this->assertSame($version->uid, $exception->currentVersionUid);
        }

        Http::assertSentCount(1);
        $this->assertSame('initial extraction', $version->refresh()->extracted_text);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.pdf.reprocessed')->count());
    }

    public function test_reprocess_rejects_missing_or_corrupt_original_before_processor_dispatch(): void
    {
        $this->fakeProcessorSequence([
            ['text' => 'initial extraction', 'pages' => 1, 'version' => '1.4', 'state' => 'indexed'],
        ]);
        $editor = $this->user('PDF Integrity Editor', 'pdf-reprocess-integrity@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Reprocess Integrity');
        $page = $this->createPdf($editor, $workspace->uid);
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        Storage::disk('artifacts')->put($version->content_storage_path, "%PDF-1.4\ncorrupt\n%%EOF");

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('The retained PDF original is unavailable or failed integrity verification.');

        try {
            app(ReprocessPdfArtifact::class)->handle($editor, new ReprocessPdfArtifactCommand(
                pageUid: $page->uid,
                expectedCurrentVersionUid: $version->uid,
            ));
        } finally {
            Http::assertSentCount(1);
            $this->assertSame('initial extraction', $version->refresh()->extracted_text);
            $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.pdf.reprocessed')->count());
        }
    }

    public function test_blocked_or_unavailable_reprocess_leaves_derived_state_unchanged(): void
    {
        $this->fakeProcessorSequence([
            ['text' => 'initial extraction', 'pages' => 1, 'version' => '1.4', 'state' => 'indexed'],
            [
                'text' => 'api_key=abcdefghijklmnopqrstuvwx',
                'pages' => 9,
                'version' => '2.0',
                'state' => 'indexed',
            ],
        ]);
        $editor = $this->user('PDF Blocked Editor', 'pdf-reprocess-blocked@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Reprocess Blocked');
        $page = $this->createPdf($editor, $workspace->uid);
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $facts = PdfVersionFact::query()->whereKey($version->uid)->sole();
        $oldFacts = $facts->getAttributes();

        try {
            app(ReprocessPdfArtifact::class)->handle($editor, new ReprocessPdfArtifactCommand(
                pageUid: $page->uid,
                expectedCurrentVersionUid: $version->uid,
            ));
            $this->fail('A newly extracted secret must block reprocessing.');
        } catch (BlockedPageContentException $exception) {
            $this->assertContains('credential_assignment', $exception->findingCodes());
        }

        $blockedEvent = DomainEvent::query()->where('event_type', 'page.secret_scan.blocked')->sole();
        $this->assertSame('reprocess_pdf', $blockedEvent->payload['operation'] ?? null);
        $this->assertSame('initial extraction', $version->refresh()->extracted_text);
        $this->assertSame($oldFacts, $facts->refresh()->getAttributes());

        Http::fake(['*' => Http::response(['error' => 'service_unavailable'], 503)]);

        try {
            app(ReprocessPdfArtifact::class)->handle($editor, new ReprocessPdfArtifactCommand(
                pageUid: $page->uid,
                expectedCurrentVersionUid: $version->uid,
            ));
            $this->fail('An unavailable processor must fail reprocessing.');
        } catch (PdfProcessingUnavailable) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('initial extraction', $version->refresh()->extracted_text);
        $this->assertSame($oldFacts, $facts->refresh()->getAttributes());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.pdf.reprocessed')->count());
    }

    public function test_reprocess_transaction_failure_rolls_back_text_facts_search_and_traceability(): void
    {
        $this->fakeProcessorSequence([
            ['text' => 'oldrollbackneedle', 'pages' => 1, 'version' => '1.4', 'state' => 'indexed'],
            ['text' => 'newrollbackneedle', 'pages' => 2, 'version' => '1.7', 'state' => 'indexed'],
        ]);
        $editor = $this->user('PDF Rollback Editor', 'pdf-reprocess-rollback@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Reprocess Rollback');
        $page = $this->createPdf($editor, $workspace->uid);
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $facts = PdfVersionFact::query()->whereKey($version->uid)->sole();
        $oldFacts = $facts->getAttributes();
        $eventName = 'eloquent.creating: ' . DomainEvent::class;

        Event::listen($eventName, static function (DomainEvent $event): void {
            if ($event->event_type === 'page.pdf.reprocessed') {
                throw new RuntimeException('Forced PDF reprocess event persistence failure.');
            }
        });

        try {
            app(ReprocessPdfArtifact::class)->handle($editor, new ReprocessPdfArtifactCommand(
                pageUid: $page->uid,
                expectedCurrentVersionUid: $version->uid,
            ));
            $this->fail('A failed event write must roll back PDF reprocessing.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced PDF reprocess event persistence failure.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame('oldrollbackneedle', $version->refresh()->extracted_text);
        $this->assertSame($oldFacts, $facts->refresh()->getAttributes());
        $this->assertSame(1, Page::query()
            ->whereKey($page->uid)
            ->whereRaw("search_vector @@ websearch_to_tsquery('simple', ?)", ['oldrollbackneedle'])
            ->count());
        $this->assertSame(0, Page::query()
            ->whereKey($page->uid)
            ->whereRaw("search_vector @@ websearch_to_tsquery('simple', ?)", ['newrollbackneedle'])
            ->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.pdf.reprocessed')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'page.pdf.reprocessed')->count());
    }

    public function test_editor_reprocesses_from_page_detail_while_disabled_archived_and_non_pdf_requests_fail_closed(): void
    {
        $this->fakeProcessorSequence([
            ['text' => 'initial web extraction', 'pages' => 1, 'version' => '1.4', 'state' => 'indexed'],
            ['text' => 'reprocessed web extraction', 'pages' => 2, 'version' => '1.7', 'state' => 'indexed'],
        ]);
        $editor = $this->user('PDF Web Reprocess Editor', 'pdf-web-reprocess@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Web Reprocess');
        $page = $this->createPdf($editor, $workspace->uid);
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee("/pages/{$page->uid}/pdf/reprocess", false)
            ->assertSee('Reprocess PDF text');

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/pdf/reprocess", [
                'current_version_uid' => $version->uid,
            ])
            ->assertRedirect("/pages/{$page->uid}")
            ->assertSessionHasNoErrors();
        $this->assertSame('reprocessed web extraction', $version->refresh()->extracted_text);

        config(['pdf_processor.enabled' => false]);
        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertDontSee("/pages/{$page->uid}/pdf/reprocess", false);
        $this->actingAs($editor)
            ->from("/pages/{$page->uid}")
            ->post("/pages/{$page->uid}/pdf/reprocess", [
                'current_version_uid' => $version->uid,
            ])
            ->assertRedirect("/pages/{$page->uid}")
            ->assertSessionHasErrors([
                'pdf' => 'PDF artifacts are disabled for this installation.',
            ]);

        config(['pdf_processor.enabled' => true]);
        $page->forceFill(['status' => PageStatus::Archived])->save();
        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertDontSee("/pages/{$page->uid}/pdf/reprocess", false);
        $this->actingAs($editor)
            ->from("/pages/{$page->uid}")
            ->post("/pages/{$page->uid}/pdf/reprocess", [
                'current_version_uid' => $version->uid,
            ])
            ->assertRedirect("/pages/{$page->uid}")
            ->assertSessionHasErrors([
                'lifecycle' => 'Archived pages must be unarchived before reprocessing.',
            ]);

        $markdownPage = Page::factory()->create([
            'owner_user_uid' => $editor->uid,
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Markdown,
        ]);
        $this->actingAs($editor)
            ->from("/pages/{$markdownPage->uid}")
            ->post("/pages/{$markdownPage->uid}/pdf/reprocess", [
                'current_version_uid' => '01J00000000000000000000000',
            ])
            ->assertRedirect("/pages/{$markdownPage->uid}")
            ->assertSessionHasErrors([
                'pdf' => 'Only PDF artifacts can be reprocessed.',
            ]);
    }

    private function createPdf(User $editor, string $workspaceUid): Page
    {
        return app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspaceUid,
            type: PageType::Pdf,
            title: 'Reprocess Fixture PDF',
            description: null,
            content: "%PDF-1.4\nfixture\n%%EOF",
            sourceFilename: 'fixture.pdf',
            source: PageVersionSource::Upload,
        ));
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
                ]), self::PROCESSOR_SECRET),
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
