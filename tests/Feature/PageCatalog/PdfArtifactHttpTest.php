<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\PdfArtifactUrl;
use App\Application\PageCatalog\PdfProcessorProtocol;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PdfVersionFact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PdfArtifactHttpTest extends TestCase
{
    use RefreshDatabase;

    private const string PROCESSOR_SECRET = 'test-pdf-delivery-processor-secret-0001';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        config([
            'app.artifact_url' => 'http://127.0.0.1',
            'app.artifact_url_signing_key' => str_repeat('d', 32),
            'app.artifact_preview_url_ttl_seconds' => 60,
            'pdf_processor.enabled' => true,
            'pdf_processor.url' => 'http://pdf-processor.test',
            'pdf_processor.shared_secret' => self::PROCESSOR_SECRET,
            'pdf_processor.connect_timeout_seconds' => 2,
            'pdf_processor.timeout_seconds' => 15,
        ]);
    }

    public function test_editor_uploads_and_replaces_pdf_through_the_web_flow(): void
    {
        $this->fakeProcessorSequence(['First indexed text', 'Replacement indexed text']);
        $editor = $this->user('PDF Web Editor', 'pdf-web-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Web Team');
        $original = $this->pdf('First PDF body');

        $this->actingAs($editor)
            ->get('/pages/create')
            ->assertOk()
            ->assertSee('value="pdf"', false)
            ->assertSee('value="pdf_upload"', false)
            ->assertSee('name="pdf_file"', false);

        $create = $this->actingAs($editor)->post('/pages', [
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Pdf->value,
            'mode' => 'pdf_upload',
            'title' => 'Quarterly PDF',
            'description' => 'Native text report',
            'status' => 'draft',
            'pdf_file' => UploadedFile::fake()->createWithContent('quarterly.pdf', $original),
        ]);

        $page = Page::query()->where('title', 'Quarterly PDF')->sole();
        $create->assertRedirect("/pages/{$page->uid}");
        $this->assertSame(PageType::Pdf, $page->type);
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $this->assertSame($original, Storage::disk('artifacts')->get($firstVersion->content_storage_path));

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('title="PDF preview"', false)
            ->assertSee("/pages/{$page->uid}/download", false)
            ->assertSee('data-open-editor-dialog="pdf-version-dialog"', false)
            ->assertSee('data-pdf-extraction-state="indexed"', false)
            ->assertSee('Text extraction: Indexed')
            ->assertDontSee('data-artifact-preview-refresh-endpoint', false);

        $replacement = $this->pdf('Replacement PDF body');
        $replace = $this->actingAs($editor)->post("/pages/{$page->uid}/versions", [
            'mode' => PageVersionSource::Upload->value,
            'base_version_uid' => $firstVersion->uid,
            'change_summary' => 'Replace the quarterly report',
            'pdf_file' => UploadedFile::fake()->createWithContent('replacement.pdf', $replacement),
        ]);

        $replace->assertRedirect("/pages/{$page->uid}");
        $page->refresh();
        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
        $currentVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $this->assertSame($replacement, Storage::disk('artifacts')->get($currentVersion->content_storage_path));
        $this->assertSame('Replacement indexed text', $currentVersion->extracted_text);
    }

    public function test_editor_can_restore_a_historical_pdf_as_a_reprocessed_new_version(): void
    {
        $this->fakeProcessorSequence([
            'Original extraction',
            'Replacement extraction',
            'Current-profile restored extraction',
        ]);
        $editor = $this->user('PDF Web Restore Editor', 'pdf-web-restore@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Web Restore Team');
        $original = $this->pdf('Original PDF body');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Restorable Web PDF',
            description: null,
            content: $original,
            sourceFilename: 'restorable-web.pdf',
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: $this->pdf('Replacement PDF body'),
            baseVersionUid: $firstVersion->uid,
            source: PageVersionSource::Upload,
        ));

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee("/pages/{$page->uid}/versions/{$firstVersion->uid}/restore", false);
        $this->actingAs($editor)
            ->get("/pages/{$page->uid}/versions/{$firstVersion->uid}")
            ->assertOk()
            ->assertSee('Restore as new current version')
            ->assertDontSee('data-artifact-preview-refresh-endpoint', false);

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions/{$firstVersion->uid}/restore", [
                'current_version_uid' => $secondVersion->uid,
            ])
            ->assertRedirect("/pages/{$page->uid}")
            ->assertSessionHasNoErrors();

        $restored = PageVersion::query()->findOrFail($page->refresh()->current_version_uid);
        $this->assertSame(3, $restored->version_number);
        $this->assertSame(PageVersionSource::Restore, $restored->source);
        $this->assertSame($firstVersion->content_hash, $restored->content_hash);
        $this->assertSame($original, Storage::disk('artifacts')->get($restored->content_storage_path));
        $this->assertSame('Current-profile restored extraction', $restored->extracted_text);
        $this->assertNotNull(PdfVersionFact::query()->find($restored->uid));
    }

    public function test_web_upload_rejects_html_renamed_to_pdf_with_a_visible_field_error(): void
    {
        Http::fake();
        $editor = $this->user('PDF Web Confusion Editor', 'pdf-web-confusion@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Web Confusion Team');

        $response = $this->actingAs($editor)->from('/pages/create')->post('/pages', [
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Pdf->value,
            'mode' => 'pdf_upload',
            'title' => 'Renamed HTML PDF',
            'status' => 'draft',
            'pdf_file' => UploadedFile::fake()->createWithContent(
                'renamed-html.pdf',
                '<!doctype html><script>fetch("/pdf-upload-js-probe")</script>',
            ),
        ]);

        $response
            ->assertRedirect('/pages/create')
            ->assertSessionHasErrors([
                'pdf_file' => 'PDF content must start with a PDF document header.',
            ]);
        Http::assertNothingSent();
        $this->assertSame(0, Page::query()->count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_rejected_interactive_pdf_preserves_create_form_input_and_shows_an_inline_reason(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => 'pdf_rejected',
                'reason' => 'interactive_form',
            ], 422),
        ]);
        $editor = $this->user('PDF Form Editor', 'pdf-form-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Form Team');
        $submitted = [
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Pdf->value,
            'mode' => 'pdf_upload',
            'title' => 'House form',
            'description' => 'Keep this context after rejection.',
            'status' => 'approved',
            'tags' => 'property, family',
            'pdf_file' => UploadedFile::fake()->createWithContent('house-form.pdf', $this->pdf('form fixture')),
        ];

        $response = $this->actingAs($editor)
            ->from('/pages/create')
            ->post('/pages', $submitted);

        $message = 'PDF contains fillable form fields. ArtifactFlow does not accept interactive PDF forms.';
        $response
            ->assertRedirect('/pages/create')
            ->assertSessionHasErrors(['pdf_file' => $message])
            ->assertSessionHasInput([
                'workspace_uid' => $workspace->uid,
                'type' => PageType::Pdf->value,
                'mode' => 'pdf_upload',
                'title' => 'House form',
                'description' => 'Keep this context after rejection.',
                'status' => 'approved',
                'tags' => 'property, family',
            ]);

        $this->get('/pages/create')
            ->assertOk()
            ->assertSee($message)
            ->assertSee('data-native-submit', false)
            ->assertSee('data-pdf-upload-error', false)
            ->assertSee('role="alert"', false)
            ->assertSee('aria-describedby="create-pdf-file-error"', false)
            ->assertSee('autofocus', false)
            ->assertSee('value="House form"', false)
            ->assertSee('Keep this context after rejection.')
            ->assertSee('value="pdf" selected', false)
            ->assertSee('value="approved" selected', false);

        $this->assertSame(0, Page::query()->count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_web_pdf_upload_is_capped_at_the_configured_artifact_read_ceiling(): void
    {
        config([
            'pages.artifact_max_bytes' => 32,
            'pages.max_html_bytes' => 32,
            'pages.max_markdown_bytes' => 32,
        ]);
        Http::fake();
        $editor = $this->user('PDF Read Ceiling Editor', 'pdf-read-ceiling@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Read Ceiling Team');
        $oversized = "%PDF-1.7\n" . str_repeat('x', 40) . "\n%%EOF";

        $this->actingAs($editor)
            ->from('/pages/create')
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => PageType::Pdf->value,
                'mode' => 'pdf_upload',
                'title' => 'Unreadable PDF',
                'status' => 'draft',
                'pdf_file' => UploadedFile::fake()->createWithContent('unreadable.pdf', $oversized),
            ])
            ->assertRedirect('/pages/create')
            ->assertSessionHasErrors([
                'pdf_file' => 'PDF exceeds the configured size limit.',
            ]);

        Http::assertNothingSent();
        $this->assertSame(0, Page::query()->count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_pdf_page_displays_each_persisted_extraction_state(): void
    {
        $this->fakeProcessorSequence(['Visible PDF text']);
        $editor = $this->user('PDF State Editor', 'pdf-state@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF State Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Stateful PDF',
            description: null,
            content: $this->pdf('Stateful PDF'),
            sourceFilename: 'stateful.pdf',
            source: PageVersionSource::Upload,
        ));
        $facts = PdfVersionFact::query()->whereKey($page->current_version_uid)->sole();

        foreach ([
            'indexed' => 'Indexed',
            'no_embedded_text' => 'No embedded text',
            'partially_indexed' => 'Partially indexed',
        ] as $state => $label) {
            $facts->forceFill(['extraction_state' => $state])->save();

            $this->actingAs($editor)
                ->get("/pages/{$page->uid}")
                ->assertOk()
                ->assertSee("data-pdf-extraction-state=\"{$state}\"", false)
                ->assertSee("Text extraction: {$label}");
        }
    }

    public function test_historical_pdf_inspector_displays_the_selected_versions_extraction_state(): void
    {
        $this->fakeProcessorSequence(['Historical PDF text', 'Current PDF text']);
        $editor = $this->user('PDF History State Editor', 'pdf-history-state@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF History State Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Historical PDF State',
            description: null,
            content: $this->pdf('historical'),
            sourceFilename: 'historical.pdf',
            source: PageVersionSource::Upload,
        ));
        $historicalVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $currentVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: $this->pdf('current'),
            baseVersionUid: $historicalVersion->uid,
            source: PageVersionSource::Upload,
        ));
        $historicalFacts = PdfVersionFact::query()->whereKey($historicalVersion->uid)->sole();
        $currentFacts = PdfVersionFact::query()->whereKey($currentVersion->uid)->sole();
        config(['pdf_processor.enabled' => false]);

        foreach ([
            ['indexed', 'Indexed', 'no_embedded_text'],
            ['no_embedded_text', 'No embedded text', 'partially_indexed'],
            ['partially_indexed', 'Partially indexed', 'indexed'],
        ] as [$selectedState, $selectedLabel, $currentState]) {
            $historicalFacts->forceFill(['extraction_state' => $selectedState])->save();
            $currentFacts->forceFill(['extraction_state' => $currentState])->save();

            $this->actingAs($editor)
                ->get("/pages/{$page->uid}/versions/{$historicalVersion->uid}")
                ->assertOk()
                ->assertSee("data-pdf-extraction-state=\"{$selectedState}\"", false)
                ->assertSee("Text extraction: {$selectedLabel}")
                ->assertDontSee("data-pdf-extraction-state=\"{$currentState}\"", false)
                ->assertDontSee('title="Historical PDF preview"', false);
        }
    }

    public function test_pdf_create_replace_and_restore_preserve_retryable_processor_failures(): void
    {
        $this->fakeProcessorSequence([
            null,
            'First extraction',
            'Second extraction',
            null,
            null,
        ]);
        $editor = $this->user('PDF Retry Editor', 'pdf-retry@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Retry Team');

        $this->actingAs($editor)
            ->from('/pages/create')
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => PageType::Pdf->value,
                'mode' => 'pdf_upload',
                'title' => 'Unavailable PDF',
                'description' => 'Preserve this retry context.',
                'status' => 'draft',
                'pdf_file' => UploadedFile::fake()->createWithContent('unavailable.pdf', $this->pdf('unavailable')),
            ])
            ->assertStatus(303)
            ->assertRedirect('/pages/create')
            ->assertHeader('Retry-After', '5')
            ->assertSessionHasErrors([
                'pdf_file' => 'PDF processing service is unavailable. Try again shortly.',
            ])
            ->assertSessionHasInput([
                'workspace_uid' => $workspace->uid,
                'type' => PageType::Pdf->value,
                'mode' => 'pdf_upload',
                'title' => 'Unavailable PDF',
                'description' => 'Preserve this retry context.',
                'status' => 'draft',
            ]);

        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Retryable PDF',
            description: null,
            content: $this->pdf('first'),
            sourceFilename: 'retryable.pdf',
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: $this->pdf('second'),
            baseVersionUid: $firstVersion->uid,
            source: PageVersionSource::Upload,
        ));

        $this->actingAs($editor)
            ->from("/pages/{$page->uid}")
            ->post("/pages/{$page->uid}/versions", [
                'mode' => PageVersionSource::Upload->value,
                'base_version_uid' => $secondVersion->uid,
                'pdf_file' => UploadedFile::fake()->createWithContent('replacement.pdf', $this->pdf('third')),
            ])
            ->assertStatus(303)
            ->assertRedirect("/pages/{$page->uid}")
            ->assertHeader('Retry-After', '5')
            ->assertSessionHasErrors([
                'pdf_file' => 'PDF processing service is unavailable. Try again shortly.',
            ]);

        $this->actingAs($editor)
            ->from("/pages/{$page->uid}")
            ->post("/pages/{$page->uid}/versions/{$firstVersion->uid}/restore", [
                'current_version_uid' => $secondVersion->uid,
            ])
            ->assertStatus(303)
            ->assertRedirect("/pages/{$page->uid}")
            ->assertHeader('Retry-After', '5')
            ->assertSessionHasErrors([
                'version_uid' => 'PDF processing service is unavailable. Try again shortly.',
            ]);
    }

    public function test_disabled_pdf_setting_hides_creation_and_closes_existing_delivery_surfaces(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $this->fakeProcessorSequence(['Previously indexed text', 'Replacement indexed text']);
        $editor = $this->user('Disabled PDF Editor', 'disabled-pdf-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Disabled PDF Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Previously accepted PDF',
            description: null,
            content: $this->pdf('Previously accepted PDF body'),
            sourceFilename: 'previously-accepted.pdf',
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $currentVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: $this->pdf('Replacement accepted PDF body'),
            baseVersionUid: $firstVersion->uid,
            source: PageVersionSource::Upload,
        ));
        $signedUrl = app(PdfArtifactUrl::class)->temporaryCurrentUrl($page->refresh(), $currentVersion);

        config(['pdf_processor.enabled' => false]);
        Http::fake();

        $this->actingAs($editor)
            ->get('/pages/create')
            ->assertOk()
            ->assertDontSee('value="pdf"', false)
            ->assertDontSee('name="pdf_file"', false);

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertDontSee("/pages/{$page->uid}/download", false)
            ->assertDontSee('title="PDF preview"', false)
            ->assertDontSee('Replace PDF')
            ->assertDontSee("/pages/{$page->uid}/versions/{$firstVersion->uid}/restore", false);

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}/versions/{$firstVersion->uid}")
            ->assertOk()
            ->assertDontSee('Restore as new current version');

        $this->actingAs($editor)
            ->from("/pages/{$page->uid}")
            ->post("/pages/{$page->uid}/versions/{$firstVersion->uid}/restore", [
                'current_version_uid' => $currentVersion->uid,
            ])
            ->assertRedirect("/pages/{$page->uid}")
            ->assertSessionHasErrors([
                'version_uid' => 'PDF artifacts are disabled for this installation.',
            ]);

        $this->actingAs($editor)->get("/pages/{$page->uid}/download")->assertNotFound();
        $this->actingAs($editor)->get("/pages/{$page->uid}/artifact-preview-url")->assertNotFound();

        $this->actingAs($editor)
            ->from('/pages/create')
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => PageType::Pdf->value,
                'mode' => 'pdf_upload',
                'title' => 'Forged disabled PDF',
                'status' => 'draft',
                'pdf_file' => UploadedFile::fake()->createWithContent('forged.pdf', $this->pdf('forged')),
            ])
            ->assertRedirect('/pages/create')
            ->assertSessionHasErrors([
                'pdf_file' => 'PDF artifacts are disabled for this installation.',
            ]);
        Http::assertNothingSent();
        $this->assertSame(0, Page::query()->where('title', 'Forged disabled PDF')->count());

        config(['app.runtime_role' => 'artifact-host']);
        $this->get($signedUrl)->assertNotFound();
    }

    public function test_authorized_pdf_view_and_download_stream_exact_original_with_distinct_claims(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $this->fakeProcessorSequence(['Visible PDF text']);
        $editor = $this->user('PDF Delivery Editor', 'pdf-delivery@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Delivery Team');
        $pdf = $this->pdf('ArtifactFlow PDF cage');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Delivery PDF',
            description: null,
            content: $pdf,
            sourceFilename: 'delivery.pdf',
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $viewUrl = app(PdfArtifactUrl::class)->temporaryCurrentUrl($page, $version);

        $downloadRedirect = $this->actingAs($editor)->get("/pages/{$page->uid}/download");
        $downloadRedirect->assertRedirect();
        $downloadUrl = $downloadRedirect->headers->get('Location');
        $this->assertIsString($downloadUrl);
        $this->assertStringContainsString('purpose=download', $downloadUrl);
        $this->assertNotSame($viewUrl, $downloadUrl);

        config(['app.runtime_role' => 'artifact-host']);

        $view = $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($viewUrl);
        $view->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', sprintf(
                'inline; filename="artifactflow-%s-v1.pdf"',
                strtolower($page->uid),
            ))
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Length', (string) strlen($pdf))
            ->assertContent($pdf);
        $csp = $view->headers->get('Content-Security-Policy');
        $this->assertIsString($csp);
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors http://localhost:18080", $csp);
        $this->assertStringNotContainsString('sandbox', $csp);
        $this->assertFalse($view->headers->has('X-Frame-Options'));
        $this->assertFalse($view->headers->has('Access-Control-Allow-Origin'));
        $this->assertFalse($view->headers->has('Set-Cookie'));

        $this->get($downloadUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', sprintf(
                'attachment; filename="artifactflow-%s-v1.pdf"',
                strtolower($page->uid),
            ))
            ->assertContent($pdf);
    }

    public function test_pdf_claims_fail_closed_for_tampering_expiry_revocation_and_version_confusion(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $this->fakeProcessorSequence(['First text', 'Second text']);
        $editor = $this->user('PDF Claim Editor', 'pdf-claims@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'PDF Claim Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Claim PDF',
            description: null,
            content: $this->pdf('First claim PDF'),
            sourceFilename: 'claim.pdf',
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $currentUrl = app(PdfArtifactUrl::class)->temporaryCurrentUrl($page, $firstVersion);
        $historyUrl = app(PdfArtifactUrl::class)->temporaryHistoryUrl($page, $firstVersion);
        $expiredUrl = $currentUrl;

        app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: $this->pdf('Second claim PDF'),
            baseVersionUid: $firstVersion->uid,
            source: PageVersionSource::Upload,
        ));
        $page->refresh();
        $currentVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $secondPage = Page::factory()->create([
            'owner_user_uid' => $editor->uid,
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Pdf,
        ]);
        $wrongRelationUrl = app(PdfArtifactUrl::class)->temporaryHistoryUrl($secondPage, $currentVersion);

        config(['app.runtime_role' => 'artifact-host']);

        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($currentUrl)->assertNotFound();
        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($historyUrl)->assertOk();
        $this->withHeader('Sec-Fetch-Dest', 'iframe')
            ->get(str_replace('purpose=history', 'purpose=download', $historyUrl))
            ->assertNotFound();
        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($wrongRelationUrl)->assertNotFound();

        Carbon::setTestNow('2026-08-17 12:02:00');
        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($expiredUrl)->assertNotFound();

        Carbon::setTestNow('2026-08-17 12:00:00');
        $freshUrl = app(PdfArtifactUrl::class)->temporaryCurrentUrl($page, $currentVersion);
        Page::query()->whereKey($page->uid)->increment('preview_access_revision');
        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($freshUrl)->assertNotFound();
    }

    /** @param list<string|null> $outcomes */
    private function fakeProcessorSequence(array $outcomes): void
    {
        Http::fake(function (Request $request) use (&$outcomes): \GuzzleHttp\Promise\PromiseInterface {
            $this->assertNotSame([], $outcomes);
            $text = array_shift($outcomes);

            if ($text === null) {
                return Http::response(['error' => 'service_unavailable'], 503);
            }

            $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $this->assertIsString($nonce);
            $body = json_encode([
                'page_count' => 1,
                'pdf_version' => '1.4',
                'extraction_state' => 'indexed',
                'processor_profile' => 'pdfbox-3.0.8-native-text-v1',
                'text' => $text,
            ], JSON_THROW_ON_ERROR);

            return Http::response($body, 200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-ArtifactFlow-Processor-Signature' => PdfProcessorProtocol::responseSignature(
                    $nonce,
                    hash('sha256', $request->body()),
                    $body,
                    self::PROCESSOR_SECRET,
                ),
            ]);
        });
    }

    private function pdf(string $text): string
    {
        return "%PDF-1.4\n{$text}\n%%EOF\n";
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
