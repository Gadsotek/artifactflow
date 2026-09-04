<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\ArtifactPreviewUrl;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\DocumentOriginalUrl;
use App\Application\PageCatalog\DocxPreviewContentReader;
use App\Application\PageCatalog\DocxPreviewUrl;
use App\Application\PageCatalog\DocxProcessorProtocol;
use App\Application\PageCatalog\PdfProcessingResult;
use App\Application\PageCatalog\PdfProcessorProtocol;
use App\Application\PageCatalog\ReprocessDocxArtifact;
use App\Application\PageCatalog\ReprocessDocxArtifactCommand;
use App\Application\PageCatalog\ReprocessXlsxArtifact;
use App\Application\PageCatalog\ReprocessXlsxArtifactCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Application\PageCatalog\XlsxManifestContentReader;
use App\Application\PageCatalog\XlsxProcessorProtocol;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\InvalidPageStatusTransition;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Models\DocxVersionFact;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use App\Models\User;
use App\Models\XlsxVersionFact;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite as ViteFacade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OfficeArtifactHttpTest extends TestCase
{
    use RefreshDatabase;

    private const string DOCX_SECRET = 'test-office-http-docx-secret-000000001';
    private const string PDF_SECRET = 'test-office-http-pdf-secret-0000000001';
    private const string XLSX_SECRET = 'test-office-http-xlsx-secret-000000001';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        config([
            'app.artifact_frame_ancestors' => 'http://app.example.test',
            'app.artifact_preview_url_ttl_seconds' => 60,
            'app.artifact_url' => 'http://artifacts.example.test',
            'app.artifact_url_signing_key' => str_repeat('o', 32),
            'app.url' => 'http://app.example.test',
            'docx_processor.connect_timeout_seconds' => 2,
            'docx_processor.enabled' => true,
            'docx_processor.shared_secret' => self::DOCX_SECRET,
            'docx_processor.socket_path' => null,
            'docx_processor.timeout_seconds' => 35,
            'docx_processor.url' => 'http://docx-processor.test',
            'pdf_processor.connect_timeout_seconds' => 2,
            'pdf_processor.enabled' => true,
            'pdf_processor.shared_secret' => self::PDF_SECRET,
            'pdf_processor.socket_path' => null,
            'pdf_processor.timeout_seconds' => 15,
            'pdf_processor.url' => 'http://pdf-processor.test',
            'xlsx_processor.connect_timeout_seconds' => 2,
            'xlsx_processor.enabled' => true,
            'xlsx_processor.shared_secret' => self::XLSX_SECRET,
            'xlsx_processor.socket_path' => null,
            'xlsx_processor.timeout_seconds' => 15,
            'xlsx_processor.url' => 'http://xlsx-processor.test',
        ]);

        ViteFacade::clearResolvedInstance();
        $this->app->instance(Vite::class, new class() extends Vite {
            public function __invoke($entrypoints, $buildDirectory = null): HtmlString
            {
                return new HtmlString('');
            }

            public function asset($asset, $buildDirectory = null): string
            {
                return match ($asset) {
                    'resources/js/xlsx-viewer.js' => 'http://artifacts.example.test/build/assets/xlsx-viewer-test.js',
                    'resources/css/xlsx-viewer.css' => 'http://artifacts.example.test/build/assets/xlsx-viewer-test.css',
                    default => '',
                };
            }
        });
    }

    public function test_web_create_and_replace_flows_render_excel_and_word_controls(): void
    {
        $this->fakeOfficeProcessors(
            xlsxValues: ['Initial workbook', 'Replacement workbook'],
            docxPreviews: [
                "%PDF-1.7\nInitial Word preview\n%%EOF\n",
                "%PDF-1.7\nReplacement Word preview\n%%EOF\n",
            ],
            docxTexts: ['Initial Word text', 'Replacement Word text'],
        );
        $editor = $this->user('Office Web Editor', 'office-web-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Office Web Team');

        $this->actingAs($editor)
            ->get('/pages/create')
            ->assertOk()
            ->assertSee('value="xlsx"', false)
            ->assertSee('value="xlsx_upload"', false)
            ->assertSee('name="xlsx_file"', false)
            ->assertSee('value="docx"', false)
            ->assertSee('value="docx_upload"', false)
            ->assertSee('name="docx_file"', false);

        $this->actingAs($editor)->post('/pages', [
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Xlsx->value,
            'mode' => 'xlsx_upload',
            'title' => 'Web Workbook',
            'status' => 'draft',
            'xlsx_file' => UploadedFile::fake()->createWithContent('workbook.xlsx', "PK\x03\x04initial-workbook"),
        ])->assertRedirect();

        $xlsxPage = Page::query()->where('title', 'Web Workbook')->sole();
        $xlsxVersion = PageVersion::query()->whereKey($xlsxPage->current_version_uid)->sole();
        $this->actingAs($editor)
            ->get("/pages/{$xlsxPage->uid}")
            ->assertOk()
            ->assertSee('data-xlsx-preview', false)
            ->assertSee('sandbox="allow-scripts"', false)
            ->assertSee('title="Read-only Excel preview"', false)
            ->assertSee("/pages/{$xlsxPage->uid}/document-original", false)
            ->assertSee('data-open-editor-dialog="xlsx-version-dialog"', false)
            ->assertSee('Reprocess workbook preview');

        $this->actingAs($editor)
            ->get("/pages/{$xlsxPage->uid}/versions/{$xlsxVersion->uid}")
            ->assertOk()
            ->assertSee('data-xlsx-preview', false)
            ->assertSee('sandbox="allow-scripts"', false)
            ->assertSee('title="Historical read-only Excel preview"', false);

        $this->actingAs($editor)->post("/pages/{$xlsxPage->uid}/versions", [
            'mode' => PageVersionSource::Upload->value,
            'base_version_uid' => $xlsxVersion->uid,
            'change_summary' => 'Replace workbook',
            'xlsx_file' => UploadedFile::fake()->createWithContent('replacement.xlsx', "PK\x03\x04replacement-workbook"),
        ])->assertRedirect("/pages/{$xlsxPage->uid}");
        $this->assertSame(2, PageVersion::query()->where('page_uid', $xlsxPage->uid)->count());

        $this->actingAs($editor)->post('/pages', [
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Docx->value,
            'mode' => 'docx_upload',
            'title' => 'Web Word Document',
            'status' => 'draft',
            'docx_file' => UploadedFile::fake()->createWithContent('document.docx', "PK\x03\x04initial-document"),
        ])->assertRedirect();

        $docxPage = Page::query()->where('title', 'Web Word Document')->sole();
        $docxVersion = PageVersion::query()->whereKey($docxPage->current_version_uid)->sole();
        $this->actingAs($editor)
            ->get("/pages/{$docxPage->uid}")
            ->assertOk()
            ->assertSee('data-docx-preview', false)
            ->assertSee('title="Word document PDF preview"', false)
            ->assertSee('validated, searchable PDF derivative')
            ->assertSee("/pages/{$docxPage->uid}/document-original", false)
            ->assertSee('data-open-editor-dialog="docx-version-dialog"', false)
            ->assertSee('Regenerate Word preview');

        $this->actingAs($editor)->post("/pages/{$docxPage->uid}/versions", [
            'mode' => PageVersionSource::Upload->value,
            'base_version_uid' => $docxVersion->uid,
            'change_summary' => 'Replace Word document',
            'docx_file' => UploadedFile::fake()->createWithContent('replacement.docx', "PK\x03\x04replacement-document"),
        ])->assertRedirect("/pages/{$docxPage->uid}");
        $this->assertSame(2, PageVersion::query()->where('page_uid', $docxPage->uid)->count());
    }

    public function test_blocked_xlsx_replacement_reports_the_upload_field_without_creating_a_version(): void
    {
        $this->fakeOfficeProcessors(
            xlsxValues: ['Initial workbook', 'password: "correct-horse-battery-staple"'],
            docxPreviews: [],
            docxTexts: [],
        );
        $editor = $this->user('Blocked Excel Editor', 'blocked-excel@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Blocked Excel Team');
        [$page, $version] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Xlsx,
            "PK\x03\x04initial-blocked-workbook",
            'initial.xlsx',
        );

        $this->actingAs($editor)->post("/pages/{$page->uid}/versions", [
            'mode' => PageVersionSource::Upload->value,
            'base_version_uid' => $version->uid,
            'change_summary' => 'Replace workbook with blocked extraction',
            'xlsx_file' => UploadedFile::fake()->createWithContent(
                'blocked.xlsx',
                "PK\x03\x04blocked-workbook",
            ),
        ])
            ->assertSessionHasErrors('xlsx_file')
            ->assertSessionDoesntHaveErrors('content');

        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_blocked_docx_replacement_reports_the_upload_field_without_creating_a_version(): void
    {
        $this->fakeOfficeProcessors(
            xlsxValues: [],
            docxPreviews: [
                "%PDF-1.7\nInitial Word preview\n%%EOF\n",
                "%PDF-1.7\nBlocked Word preview\n%%EOF\n",
            ],
            docxTexts: ['Initial Word text', 'password: "correct-horse-battery-staple"'],
        );
        $editor = $this->user('Blocked Word Editor', 'blocked-word@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Blocked Word Team');
        [$page, $version] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Docx,
            "PK\x03\x04initial-blocked-document",
            'initial.docx',
        );

        $this->actingAs($editor)->post("/pages/{$page->uid}/versions", [
            'mode' => PageVersionSource::Upload->value,
            'base_version_uid' => $version->uid,
            'change_summary' => 'Replace document with blocked extraction',
            'docx_file' => UploadedFile::fake()->createWithContent(
                'blocked.docx',
                "PK\x03\x04blocked-document",
            ),
        ])
            ->assertSessionHasErrors('docx_file')
            ->assertSessionDoesntHaveErrors('content');

        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_blocked_office_restore_reports_the_version_field_without_creating_a_version(): void
    {
        $this->fakeOfficeProcessors(
            xlsxValues: [
                'Initial workbook',
                'Replacement workbook',
                'password: "correct-horse-battery-staple"',
            ],
            docxPreviews: [],
            docxTexts: [],
        );
        $editor = $this->user('Blocked Restore Editor', 'blocked-restore@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Blocked Restore Team');
        [$page, $firstVersion] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Xlsx,
            "PK\x03\x04initial-restore-workbook",
            'initial.xlsx',
        );
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: "PK\x03\x04replacement-restore-workbook",
            baseVersionUid: $firstVersion->uid,
            source: PageVersionSource::Upload,
            changeSummary: 'Replace workbook before restore',
        ));

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions/{$firstVersion->uid}/restore", [
                'current_version_uid' => $secondVersion->uid,
            ])
            ->assertSessionHasErrors('version_uid')
            ->assertSessionDoesntHaveErrors('content');

        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_office_reprocessing_refreshes_derivatives_without_creating_versions(): void
    {
        $this->fakeOfficeProcessors(
            ['Initial workbook', 'Refreshed workbook'],
            [
                "%PDF-1.7\nInitial Word preview\n%%EOF\n",
                "%PDF-1.7\nRefreshed Word preview\n%%EOF\n",
            ],
            ['Initial Word text', 'Refreshed Word text'],
        );
        $editor = $this->user('Office Reprocess Editor', 'office-reprocess@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Office Reprocess Team');
        [$xlsxPage, $xlsxVersion] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Xlsx,
            "PK\x03\x04reprocess-workbook",
            'reprocess.xlsx',
        );
        [$docxPage, $docxVersion] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Docx,
            "PK\x03\x04reprocess-document",
            'reprocess.docx',
        );

        $this->actingAs($editor)
            ->post("/pages/{$xlsxPage->uid}/xlsx/reprocess", [
                'current_version_uid' => $xlsxVersion->uid,
            ])
            ->assertRedirect("/pages/{$xlsxPage->uid}")
            ->assertSessionHas('status', 'Excel workbook preview and search projection were refreshed.');
        $this->actingAs($editor)
            ->post("/pages/{$docxPage->uid}/docx/reprocess", [
                'current_version_uid' => $docxVersion->uid,
            ])
            ->assertRedirect("/pages/{$docxPage->uid}")
            ->assertSessionHas('status', 'Word document preview and search projection were refreshed.');

        $this->assertSame(1, PageVersion::query()->where('page_uid', $xlsxPage->uid)->count());
        $this->assertSame(1, PageVersion::query()->where('page_uid', $docxPage->uid)->count());
        $this->assertSame(
            '[Visible] A1 Refreshed workbook',
            PageVersion::query()->whereKey($xlsxVersion->uid)->value('extracted_text'),
        );
        $this->assertSame(
            'Refreshed Word text',
            PageVersion::query()->whereKey($docxVersion->uid)->value('extracted_text'),
        );
    }

    public function test_office_reprocessing_rejects_missing_stale_wrong_type_and_archived_requests(): void
    {
        $this->fakeOfficeProcessors(
            ['Initial workbook'],
            ["%PDF-1.7\nInitial Word preview\n%%EOF\n"],
            ['Initial Word text'],
        );
        $editor = $this->user('Office Reprocess Guard Editor', 'office-reprocess-guard@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Office Reprocess Guard Team');
        [$xlsxPage, $xlsxVersion] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Xlsx,
            "PK\x03\x04guard-workbook",
            'guard.xlsx',
        );
        [$docxPage, $docxVersion] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Docx,
            "PK\x03\x04guard-document",
            'guard.docx',
        );

        $this->actingAs($editor)
            ->post("/pages/{$xlsxPage->uid}/xlsx/reprocess")
            ->assertConflict();
        $this->actingAs($editor)
            ->post("/pages/{$docxPage->uid}/docx/reprocess")
            ->assertConflict();
        $this->actingAs($editor)
            ->post("/pages/{$xlsxPage->uid}/xlsx/reprocess", ['current_version_uid' => (string) Str::ulid()])
            ->assertConflict();
        $this->actingAs($editor)
            ->post("/pages/{$docxPage->uid}/docx/reprocess", ['current_version_uid' => (string) Str::ulid()])
            ->assertConflict();

        $this->actingAs($editor)
            ->post("/pages/{$docxPage->uid}/xlsx/reprocess", ['current_version_uid' => $docxVersion->uid])
            ->assertSessionHasErrors('xlsx');
        $this->actingAs($editor)
            ->post("/pages/{$xlsxPage->uid}/docx/reprocess", ['current_version_uid' => $xlsxVersion->uid])
            ->assertSessionHasErrors('docx');

        $this->actingAs($editor)
            ->post("/pages/{$xlsxPage->uid}/archive", ['confirmed' => '1'])
            ->assertRedirect();
        $this->actingAs($editor)
            ->post("/pages/{$docxPage->uid}/archive", ['confirmed' => '1'])
            ->assertRedirect();
        $this->actingAs($editor)
            ->post("/pages/{$xlsxPage->uid}/xlsx/reprocess", ['current_version_uid' => $xlsxVersion->uid])
            ->assertSessionHasErrors('lifecycle');
        $this->actingAs($editor)
            ->post("/pages/{$docxPage->uid}/docx/reprocess", ['current_version_uid' => $docxVersion->uid])
            ->assertSessionHasErrors('lifecycle');
    }

    public function test_office_reprocessing_handlers_enforce_every_guard_before_processor_dispatch(): void
    {
        $this->fakeOfficeProcessors(
            ['Guard workbook'],
            ["%PDF-1.7\nGuard Word preview\n%%EOF\n"],
            ['Guard Word text'],
        );
        $owner = $this->user('Office Handler Owner', 'office-handler-owner@example.test');
        $outsider = $this->user('Office Handler Outsider', 'office-handler-outsider@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Office Handler Guard Team');
        [$xlsxPage, $xlsxVersion] = $this->createArtifact(
            $owner,
            $workspace->uid,
            PageType::Xlsx,
            "PK\x03\x04handler-guard-workbook",
            'handler-guard.xlsx',
        );
        [$docxPage, $docxVersion] = $this->createArtifact(
            $owner,
            $workspace->uid,
            PageType::Docx,
            "PK\x03\x04handler-guard-document",
            'handler-guard.docx',
        );
        $markdownPage = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Office handler wrong type',
            description: null,
            content: '# Wrong type',
            source: PageVersionSource::Editor,
        ));

        $this->assertOperationThrows(
            AuthorizationException::class,
            static fn () => app(ReprocessXlsxArtifact::class)->handle(
                $outsider,
                new ReprocessXlsxArtifactCommand($xlsxPage->uid, $xlsxVersion->uid),
            ),
        );
        $this->assertOperationThrows(
            AuthorizationException::class,
            static fn () => app(ReprocessDocxArtifact::class)->handle(
                $outsider,
                new ReprocessDocxArtifactCommand($docxPage->uid, $docxVersion->uid),
            ),
        );
        $this->assertOperationThrows(
            DomainRuleViolation::class,
            static fn () => app(ReprocessXlsxArtifact::class)->handle(
                $owner,
                new ReprocessXlsxArtifactCommand(
                    $markdownPage->uid,
                    (string) $markdownPage->current_version_uid,
                ),
            ),
        );
        $this->assertOperationThrows(
            DomainRuleViolation::class,
            static fn () => app(ReprocessDocxArtifact::class)->handle(
                $owner,
                new ReprocessDocxArtifactCommand(
                    $markdownPage->uid,
                    (string) $markdownPage->current_version_uid,
                ),
            ),
        );

        config([
            'xlsx_processor.enabled' => false,
            'docx_processor.enabled' => false,
        ]);
        $this->assertOperationThrows(
            DomainRuleViolation::class,
            static fn () => app(ReprocessXlsxArtifact::class)->handle(
                $owner,
                new ReprocessXlsxArtifactCommand($xlsxPage->uid, $xlsxVersion->uid),
            ),
        );
        $this->assertOperationThrows(
            DomainRuleViolation::class,
            static fn () => app(ReprocessDocxArtifact::class)->handle(
                $owner,
                new ReprocessDocxArtifactCommand($docxPage->uid, $docxVersion->uid),
            ),
        );
        config([
            'xlsx_processor.enabled' => true,
            'docx_processor.enabled' => true,
        ]);

        $xlsxPage->forceFill(['status' => PageStatus::Archived])->save();
        $docxPage->forceFill(['status' => PageStatus::Archived])->save();
        $this->assertOperationThrows(
            InvalidPageStatusTransition::class,
            static fn () => app(ReprocessXlsxArtifact::class)->handle(
                $owner,
                new ReprocessXlsxArtifactCommand($xlsxPage->uid, $xlsxVersion->uid),
            ),
        );
        $this->assertOperationThrows(
            InvalidPageStatusTransition::class,
            static fn () => app(ReprocessDocxArtifact::class)->handle(
                $owner,
                new ReprocessDocxArtifactCommand($docxPage->uid, $docxVersion->uid),
            ),
        );
        $xlsxPage->forceFill(['status' => PageStatus::Draft])->save();
        $docxPage->forceFill(['status' => PageStatus::Draft])->save();

        $this->assertOperationThrows(
            StalePageVersionException::class,
            static fn () => app(ReprocessXlsxArtifact::class)->handle(
                $owner,
                new ReprocessXlsxArtifactCommand($xlsxPage->uid, (string) Str::ulid()),
            ),
        );
        $this->assertOperationThrows(
            StalePageVersionException::class,
            static fn () => app(ReprocessDocxArtifact::class)->handle(
                $owner,
                new ReprocessDocxArtifactCommand($docxPage->uid, (string) Str::ulid()),
            ),
        );

        Http::assertSentCount(3);
    }

    public function test_office_reprocessing_blocks_secret_bearing_projections_and_preserves_current_derivatives(): void
    {
        $this->fakeOfficeProcessors(
            ['Safe workbook', 'password: "correct-horse-battery-staple"'],
            [
                "%PDF-1.7\nSafe Word preview\n%%EOF\n",
                "%PDF-1.7\nBlocked Word preview\n%%EOF\n",
            ],
            ['Safe Word text', 'password: "correct-horse-battery-staple"'],
        );
        $owner = $this->user('Office Secret Guard Owner', 'office-secret-guard@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Office Secret Guard Team');
        [$xlsxPage, $xlsxVersion] = $this->createArtifact(
            $owner,
            $workspace->uid,
            PageType::Xlsx,
            "PK\x03\x04secret-guard-workbook",
            'secret-guard.xlsx',
        );
        [$docxPage, $docxVersion] = $this->createArtifact(
            $owner,
            $workspace->uid,
            PageType::Docx,
            "PK\x03\x04secret-guard-document",
            'secret-guard.docx',
        );
        $xlsxDerivativePath = PageVersionDerivative::query()
            ->where('page_version_uid', $xlsxVersion->uid)
            ->sole()
            ->storage_path;
        $docxDerivativePath = PageVersionDerivative::query()
            ->where('page_version_uid', $docxVersion->uid)
            ->sole()
            ->storage_path;

        foreach ([
            static fn () => app(ReprocessXlsxArtifact::class)->handle(
                $owner,
                new ReprocessXlsxArtifactCommand($xlsxPage->uid, $xlsxVersion->uid),
            ),
            static fn () => app(ReprocessDocxArtifact::class)->handle(
                $owner,
                new ReprocessDocxArtifactCommand($docxPage->uid, $docxVersion->uid),
            ),
        ] as $operation) {
            try {
                $operation();
                $this->fail('A secret-bearing Office projection must block reprocessing.');
            } catch (BlockedPageContentException $exception) {
                $this->assertSame(['credential_assignment'], $exception->findingCodes());
            }
        }

        $this->assertSame(1, PageVersion::query()->where('page_uid', $xlsxPage->uid)->count());
        $this->assertSame(1, PageVersion::query()->where('page_uid', $docxPage->uid)->count());
        $this->assertSame(
            $xlsxDerivativePath,
            PageVersionDerivative::query()->where('page_version_uid', $xlsxVersion->uid)->sole()->storage_path,
        );
        $this->assertSame(
            $docxDerivativePath,
            PageVersionDerivative::query()->where('page_version_uid', $docxVersion->uid)->sole()->storage_path,
        );
        Http::assertSentCount(6);
    }

    public function test_office_reprocessing_fails_closed_when_current_version_points_to_another_page(): void
    {
        $this->fakeOfficeProcessors(
            ['Pointer workbook'],
            ["%PDF-1.7\nPointer Word preview\n%%EOF\n"],
            ['Pointer Word text'],
        );
        $owner = $this->user('Office Pointer Owner', 'office-pointer-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Office Pointer Team');
        [$xlsxPage] = $this->createArtifact(
            $owner,
            $workspace->uid,
            PageType::Xlsx,
            "PK\x03\x04pointer-workbook",
            'pointer.xlsx',
        );
        [$docxPage] = $this->createArtifact(
            $owner,
            $workspace->uid,
            PageType::Docx,
            "PK\x03\x04pointer-document",
            'pointer.docx',
        );
        $foreignPage = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Foreign Office pointer',
            description: null,
            content: '# Foreign version',
            source: PageVersionSource::Editor,
        ));
        $foreignVersionUid = (string) $foreignPage->current_version_uid;
        $xlsxPage->forceFill(['current_version_uid' => $foreignVersionUid])->save();
        $docxPage->forceFill(['current_version_uid' => $foreignVersionUid])->save();

        $this->assertOperationThrows(
            DomainRuleViolation::class,
            static fn () => app(ReprocessXlsxArtifact::class)->handle(
                $owner,
                new ReprocessXlsxArtifactCommand($xlsxPage->uid, $foreignVersionUid),
            ),
        );
        $this->assertOperationThrows(
            DomainRuleViolation::class,
            static fn () => app(ReprocessDocxArtifact::class)->handle(
                $owner,
                new ReprocessDocxArtifactCommand($docxPage->uid, $foreignVersionUid),
            ),
        );

        Http::assertSentCount(3);
    }

    public function test_office_reprocessing_returns_retryable_no_store_failures(): void
    {
        $this->fakeOfficeProcessors(
            ['Initial workbook'],
            ["%PDF-1.7\nInitial Word preview\n%%EOF\n"],
            ['Initial Word text'],
        );
        $editor = $this->user('Office Retry Editor', 'office-retry@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Office Retry Team');
        [$xlsxPage, $xlsxVersion] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Xlsx,
            "PK\x03\x04retry-workbook",
            'retry.xlsx',
        );
        [$docxPage, $docxVersion] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Docx,
            "PK\x03\x04retry-document",
            'retry.docx',
        );

        Http::fake(['*' => Http::response('processor unavailable', 500)]);

        $xlsxResponse = $this->from("/pages/{$xlsxPage->uid}")
            ->actingAs($editor)
            ->post("/pages/{$xlsxPage->uid}/xlsx/reprocess", [
                'current_version_uid' => $xlsxVersion->uid,
            ])
            ->assertStatus(303)
            ->assertRedirect("/pages/{$xlsxPage->uid}")
            ->assertHeader('Retry-After', '5')
            ->assertSessionHasErrors('xlsx');
        $this->assertStringContainsString(
            'no-store',
            (string) $xlsxResponse->headers->get('Cache-Control'),
        );

        $docxResponse = $this->from("/pages/{$docxPage->uid}")
            ->actingAs($editor)
            ->post("/pages/{$docxPage->uid}/docx/reprocess", [
                'current_version_uid' => $docxVersion->uid,
            ])
            ->assertStatus(303)
            ->assertRedirect("/pages/{$docxPage->uid}")
            ->assertHeader('Retry-After', '5')
            ->assertSessionHasErrors('docx');
        $this->assertStringContainsString(
            'no-store',
            (string) $docxResponse->headers->get('Cache-Control'),
        );
    }

    public function test_signed_delivery_serves_only_validated_previews_and_exact_original_downloads(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        $xlsx = "PK\x03\x04delivery-workbook";
        $docx = "PK\x03\x04delivery-document";
        $docxPreview = "%PDF-1.7\nSearchable Word preview\n%%EOF\n";
        $this->fakeOfficeProcessors(['Visible worksheet value'], [$docxPreview], ['Visible Word text']);
        $editor = $this->user('Office Delivery Editor', 'office-delivery@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Office Delivery Team');
        [$xlsxPage, $xlsxVersion] = $this->createArtifact($editor, $workspace->uid, PageType::Xlsx, $xlsx, 'delivery.xlsx');
        [$docxPage, $docxVersion] = $this->createArtifact($editor, $workspace->uid, PageType::Docx, $docx, 'delivery.docx');

        $xlsxPreviewUrl = app(ArtifactPreviewUrl::class)->temporaryUrl($xlsxPage, $xlsxVersion);
        $docxPreviewUrl = app(DocxPreviewUrl::class)->temporaryCurrentUrl($docxPage, $docxVersion);
        $xlsxOriginalUrl = $this->downloadRedirect($editor, $xlsxPage);
        $docxOriginalUrl = $this->downloadRedirect($editor, $docxPage);

        config(['app.runtime_role' => 'artifact-host']);

        $xlsxResponse = $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($xlsxPreviewUrl);
        $xlsxResponse
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Read-only Excel preview')
            ->assertSee('<script defer src="/build/assets/xlsx-viewer-test.js"></script>', false)
            ->assertSee('/build/assets/xlsx-viewer-test.css', false)
            ->assertDontSee($xlsx, false)
            ->assertHeaderMissing('Set-Cookie')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
        $xlsxCsp = (string) $xlsxResponse->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('sandbox allow-scripts', $xlsxCsp);
        $this->assertStringNotContainsString('allow-popups', $xlsxCsp);
        $this->assertStringContainsString("script-src 'self'", $xlsxCsp);
        $this->assertStringContainsString("connect-src 'none'", $xlsxCsp);
        $this->assertStringContainsString("frame-src 'none'", $xlsxCsp);

        $this->withHeader('Sec-Fetch-Dest', 'document')
            ->get($xlsxPreviewUrl)
            ->assertForbidden()
            ->assertSee('only be viewed inside ArtifactFlow');

        $docxResponse = $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($docxPreviewUrl);
        $docxResponse
            ->assertOk()
            ->assertContent($docxPreview)
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', sprintf(
                'inline; filename="artifactflow-%s-v1.pdf"',
                strtolower($docxPage->uid),
            ))
            ->assertHeaderMissing('X-Frame-Options')
            ->assertHeaderMissing('Set-Cookie')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
        $docxCsp = (string) $docxResponse->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $docxCsp);
        $this->assertStringContainsString('frame-ancestors http://app.example.test', $docxCsp);
        $this->assertStringNotContainsString('sandbox', $docxCsp);
        $this->assertNotSame($docx, $docxResponse->getContent());

        $this->get($xlsxOriginalUrl)
            ->assertOk()
            ->assertContent($xlsx)
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition', sprintf(
                'attachment; filename="artifactflow-%s-v1.xlsx"',
                strtolower($xlsxPage->uid),
            ));
        $this->get($docxOriginalUrl)
            ->assertOk()
            ->assertContent($docx)
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->assertHeader('Content-Disposition', sprintf(
                'attachment; filename="artifactflow-%s-v1.docx"',
                strtolower($docxPage->uid),
            ));
    }

    public function test_document_claims_fail_closed_for_stale_current_tampering_revocation_and_disablement(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        $this->fakeOfficeProcessors(
            ['First workbook', 'Second workbook'],
            ["%PDF-1.7\nFirst Word preview\n%%EOF\n", "%PDF-1.7\nSecond Word preview\n%%EOF\n"],
            ['First Word text', 'Second Word text'],
        );
        $editor = $this->user('Office Claim Editor', 'office-claims@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Office Claim Team');
        [$xlsxPage, $xlsxFirst] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Xlsx,
            "PK\x03\x04first-claim-workbook",
            'first.xlsx',
        );
        [$docxPage, $docxFirst] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Docx,
            "PK\x03\x04first-claim-document",
            'first.docx',
        );

        $xlsxCurrent = app(ArtifactPreviewUrl::class)->temporaryUrl($xlsxPage, $xlsxFirst);
        $xlsxHistory = app(ArtifactPreviewUrl::class)->temporaryHistoryUrl($xlsxPage, $xlsxFirst);
        $xlsxOriginalCurrent = app(DocumentOriginalUrl::class)->temporaryCurrentUrl($xlsxPage, $xlsxFirst);
        $xlsxOriginalHistory = app(DocumentOriginalUrl::class)->temporaryHistoryUrl($xlsxPage, $xlsxFirst);
        $docxCurrent = app(DocxPreviewUrl::class)->temporaryCurrentUrl($docxPage, $docxFirst);
        $docxHistory = app(DocxPreviewUrl::class)->temporaryHistoryUrl($docxPage, $docxFirst);
        $docxOriginalCurrent = app(DocumentOriginalUrl::class)->temporaryCurrentUrl($docxPage, $docxFirst);
        $docxOriginalHistory = app(DocumentOriginalUrl::class)->temporaryHistoryUrl($docxPage, $docxFirst);

        $xlsxSecond = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $xlsxPage->uid,
            content: "PK\x03\x04second-claim-workbook",
            baseVersionUid: $xlsxFirst->uid,
            source: PageVersionSource::Upload,
        ));
        $docxSecond = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $docxPage->uid,
            content: "PK\x03\x04second-claim-document",
            baseVersionUid: $docxFirst->uid,
            source: PageVersionSource::Upload,
        ));
        $xlsxPage->refresh();
        $docxPage->refresh();

        config(['app.runtime_role' => 'artifact-host']);

        foreach ([$xlsxCurrent, $xlsxOriginalCurrent, $docxCurrent, $docxOriginalCurrent] as $staleUrl) {
            $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($staleUrl)->assertNotFound();
        }
        foreach ([$xlsxHistory, $xlsxOriginalHistory, $docxHistory, $docxOriginalHistory] as $historyUrl) {
            $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($historyUrl)->assertOk();
        }

        $this->get(str_replace('purpose=history', 'purpose=current', $xlsxOriginalHistory))->assertNotFound();
        $this->get(str_replace('purpose=history', 'purpose=current', $docxHistory))->assertNotFound();
        $this->get(str_replace('signature=', 'signature=0', $docxOriginalHistory))->assertNotFound();

        $freshXlsx = app(ArtifactPreviewUrl::class)->temporaryUrl($xlsxPage, $xlsxSecond);
        $freshDocx = app(DocxPreviewUrl::class)->temporaryCurrentUrl($docxPage, $docxSecond);
        Page::query()->whereKey($xlsxPage->uid)->increment('preview_access_revision');
        Page::query()->whereKey($docxPage->uid)->increment('preview_access_revision');
        $this->get($freshXlsx)->assertNotFound();
        $this->get($freshDocx)->assertNotFound();

        $xlsxPage->refresh();
        $docxPage->refresh();
        $enabledXlsx = app(ArtifactPreviewUrl::class)->temporaryUrl($xlsxPage, $xlsxSecond);
        $enabledXlsxOriginal = app(DocumentOriginalUrl::class)->temporaryCurrentUrl($xlsxPage, $xlsxSecond);
        $enabledDocx = app(DocxPreviewUrl::class)->temporaryCurrentUrl($docxPage, $docxSecond);
        $enabledDocxOriginal = app(DocumentOriginalUrl::class)->temporaryCurrentUrl($docxPage, $docxSecond);
        config([
            'xlsx_processor.enabled' => false,
            'docx_processor.enabled' => false,
        ]);
        foreach ([$enabledXlsx, $enabledXlsxOriginal, $enabledDocx, $enabledDocxOriginal] as $disabledUrl) {
            $this->get($disabledUrl)->assertNotFound();
        }

        config(['app.runtime_role' => 'app']);
        $this->actingAs($editor)
            ->get("/pages/{$xlsxPage->uid}/document-original")
            ->assertNotFound();
        $this->actingAs($editor)
            ->get("/pages/{$docxPage->uid}/versions/{$docxSecond->uid}/document-original")
            ->assertNotFound();
        config(['app.runtime_role' => 'artifact-host']);

        config([
            'xlsx_processor.enabled' => true,
            'docx_processor.enabled' => true,
        ]);
        $tamperedXlsx = app(ArtifactPreviewUrl::class)->temporaryUrl($xlsxPage, $xlsxSecond);
        $tamperedDocx = app(DocxPreviewUrl::class)->temporaryCurrentUrl($docxPage, $docxSecond);
        $xlsxDerivative = PageVersionDerivative::query()->where('page_version_uid', $xlsxSecond->uid)->sole();
        $docxDerivative = PageVersionDerivative::query()->where('page_version_uid', $docxSecond->uid)->sole();
        Storage::disk('artifacts')->put($xlsxDerivative->storage_path, '{"tampered":true}');
        Storage::disk('artifacts')->put($docxDerivative->storage_path, "%PDF-1.7\ntampered\n%%EOF\n");
        $this->get($tamperedXlsx)->assertNotFound();
        $this->get($tamperedDocx)->assertNotFound();

        Carbon::setTestNow('2026-08-30 12:02:00');
        $expired = app(DocumentOriginalUrl::class)->temporaryHistoryUrl($xlsxPage, $xlsxFirst);
        Carbon::setTestNow('2026-08-30 12:04:00');
        $this->get($expired)->assertNotFound();
    }

    public function test_app_origin_delivery_capabilities_do_not_disclose_documents_to_unauthorized_users(): void
    {
        $this->fakeOfficeProcessors(
            ['Private workbook'],
            ["%PDF-1.7\nPrivate Word preview\n%%EOF\n"],
            ['Private Word text'],
        );
        $owner = $this->user('Office Owner', 'office-owner@example.test');
        $outsider = $this->user('Office Outsider', 'office-outsider@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Private Office Team');
        [$xlsxPage, $xlsxVersion] = $this->createArtifact(
            $owner,
            $workspace->uid,
            PageType::Xlsx,
            "PK\x03\x04private-workbook",
            'private.xlsx',
        );
        [$docxPage, $docxVersion] = $this->createArtifact(
            $owner,
            $workspace->uid,
            PageType::Docx,
            "PK\x03\x04private-document",
            'private.docx',
        );

        foreach ([$xlsxPage, $docxPage] as $page) {
            $this->actingAs($outsider)->get("/pages/{$page->uid}")->assertNotFound();
            $this->actingAs($outsider)->get("/pages/{$page->uid}/artifact-preview-url")->assertNotFound();
            $this->actingAs($outsider)->get("/pages/{$page->uid}/document-original")->assertNotFound();
        }
        $this->actingAs($outsider)
            ->get("/pages/{$xlsxPage->uid}/versions/{$xlsxVersion->uid}/artifact-preview-url")
            ->assertNotFound();
        $this->actingAs($outsider)
            ->get("/pages/{$docxPage->uid}/versions/{$docxVersion->uid}/document-original")
            ->assertNotFound();
    }

    public function test_office_content_readers_fail_closed_when_derivatives_or_facts_are_corrupt(): void
    {
        $xlsx = "PK\x03\x04reader-workbook";
        $docx = "PK\x03\x04reader-document";
        $pdf = "%PDF-1.7\nReader Word preview\n%%EOF\n";
        $this->fakeOfficeProcessors(['Reader workbook'], [$pdf], ['Reader Word text']);
        $editor = $this->user('Office Reader Editor', 'office-reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Office Reader Team');
        [, $xlsxVersion] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Xlsx,
            $xlsx,
            'reader.xlsx',
        );
        [, $docxVersion] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Docx,
            $docx,
            'reader.docx',
        );

        $xlsxReader = app(XlsxManifestContentReader::class);
        $xlsxFacts = XlsxVersionFact::query()->whereKey($xlsxVersion->uid)->sole();
        $xlsxDerivative = PageVersionDerivative::query()->findOrFail($xlsxFacts->manifest_derivative_uid);
        $manifest = Storage::disk('artifacts')->get($xlsxDerivative->storage_path);
        $this->assertIsString($manifest);
        $this->assertSame($manifest, $xlsxReader->read($xlsxVersion));

        Storage::disk('artifacts')->delete($xlsxDerivative->storage_path);
        $this->assertNull($xlsxReader->read($xlsxVersion));
        Storage::disk('artifacts')->put($xlsxDerivative->storage_path, $manifest);

        $xlsxDerivative->forceFill(['byte_size' => strlen($manifest) + 1])->save();
        $this->assertNull($xlsxReader->read($xlsxVersion));
        $xlsxDerivative->forceFill(['byte_size' => strlen($manifest)])->save();
        $xlsxDerivative->forceFill(['content_hash' => str_repeat('0', 64)])->save();
        $this->assertNull($xlsxReader->read($xlsxVersion));

        foreach (['not-json', '[]'] as $invalidManifest) {
            Storage::disk('artifacts')->put($xlsxDerivative->storage_path, $invalidManifest);
            $xlsxDerivative->forceFill([
                'byte_size' => strlen($invalidManifest),
                'content_hash' => hash('sha256', $invalidManifest),
            ])->save();
            $this->assertNull($xlsxReader->read($xlsxVersion));
        }

        $decodedManifest = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decodedManifest);
        $nonCanonicalManifest = json_encode(
            $decodedManifest,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $this->assertNotSame($manifest, $nonCanonicalManifest);
        Storage::disk('artifacts')->put($xlsxDerivative->storage_path, $nonCanonicalManifest);
        $xlsxDerivative->forceFill([
            'byte_size' => strlen($nonCanonicalManifest),
            'content_hash' => hash('sha256', $nonCanonicalManifest),
        ])->save();
        $this->assertNull($xlsxReader->read($xlsxVersion));
        $xlsxFacts->delete();
        $this->assertNull($xlsxReader->read($xlsxVersion));

        $docxReader = app(DocxPreviewContentReader::class);
        $docxFacts = DocxVersionFact::query()->whereKey($docxVersion->uid)->sole();
        $docxDerivative = PageVersionDerivative::query()->findOrFail($docxFacts->preview_derivative_uid);
        $this->assertSame($pdf, $docxReader->read($docxVersion));

        Storage::disk('artifacts')->delete($docxDerivative->storage_path);
        $this->assertNull($docxReader->read($docxVersion));
        Storage::disk('artifacts')->put($docxDerivative->storage_path, $pdf);
        $docxDerivative->forceFill(['byte_size' => strlen($pdf) + 1])->save();
        $this->assertNull($docxReader->read($docxVersion));
        $docxDerivative->forceFill([
            'byte_size' => strlen($pdf),
            'content_hash' => str_repeat('0', 64),
        ])->save();
        $this->assertNull($docxReader->read($docxVersion));

        foreach (["not-a-pdf\n%%EOF\n", "%PDF-1.7\nmissing-eof"] as $invalidPdf) {
            Storage::disk('artifacts')->put($docxDerivative->storage_path, $invalidPdf);
            $docxDerivative->forceFill([
                'byte_size' => strlen($invalidPdf),
                'content_hash' => hash('sha256', $invalidPdf),
            ])->save();
            $this->assertNull($docxReader->read($docxVersion));
        }

        $docxFacts->delete();
        $this->assertNull($docxReader->read($docxVersion));
    }

    public function test_document_download_redirects_bind_current_and_history_to_the_exact_office_page(): void
    {
        $this->fakeOfficeProcessors(
            ['First workbook', 'Second workbook'],
            ["%PDF-1.7\nDownload Word preview\n%%EOF\n"],
            ['Download Word text'],
        );
        $editor = $this->user('Office Download Editor', 'office-download@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Office Download Team');
        [$firstPage, $firstVersion] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Xlsx,
            "PK\x03\x04first-download-workbook",
            'first.xlsx',
        );
        [, $secondVersion] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Xlsx,
            "PK\x03\x04second-download-workbook",
            'second.xlsx',
        );
        [$docxPage, $docxVersion] = $this->createArtifact(
            $editor,
            $workspace->uid,
            PageType::Docx,
            "PK\x03\x04download-document",
            'download.docx',
        );
        $markdownPage = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Download guard note',
            description: null,
            content: '# Download guard',
            source: PageVersionSource::Editor,
        ));
        $markdownVersion = PageVersion::query()->whereKey($markdownPage->current_version_uid)->sole();

        foreach ([[$firstPage, $firstVersion], [$docxPage, $docxVersion]] as [$page, $version]) {
            $response = $this->actingAs($editor)
                ->get("/pages/{$page->uid}/versions/{$version->uid}/document-original")
                ->assertRedirect()
                ->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertStringContainsString(
                'no-store',
                (string) $response->headers->get('Cache-Control'),
            );
        }

        $this->actingAs($editor)
            ->get("/pages/{$firstPage->uid}/versions/{$secondVersion->uid}/document-original")
            ->assertNotFound();
        $this->actingAs($editor)
            ->get("/pages/{$markdownPage->uid}/document-original")
            ->assertNotFound();
        $this->actingAs($editor)
            ->get("/pages/{$markdownPage->uid}/versions/{$markdownVersion->uid}/document-original")
            ->assertNotFound();
    }

    /**
     * @return array{Page, PageVersion}
     */
    private function createArtifact(
        User $actor,
        string $workspaceUid,
        PageType $type,
        string $bytes,
        string $filename,
    ): array {
        $page = app(CreatePage::class)->handle($actor, new CreatePageCommand(
            workspaceUid: $workspaceUid,
            type: $type,
            title: sprintf('%s %s', $type->value, bin2hex(random_bytes(4))),
            description: null,
            content: $bytes,
            sourceFilename: $filename,
            source: PageVersionSource::Upload,
        ));

        return [$page, PageVersion::query()->whereKey($page->current_version_uid)->sole()];
    }

    private function downloadRedirect(User $actor, Page $page): string
    {
        $response = $this->actingAs($actor)->get("/pages/{$page->uid}/document-original");
        $response->assertRedirect();
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertIsString($cacheControl);
        $this->assertEqualsCanonicalizing(
            ['private', 'no-store'],
            array_map('trim', explode(',', strtolower($cacheControl))),
        );
        $location = $response->headers->get('Location');
        $this->assertIsString($location);

        return $location;
    }

    /**
     * @param list<string> $xlsxValues
     * @param list<string> $docxPreviews
     * @param list<string> $docxTexts
     */
    private function fakeOfficeProcessors(
        array $xlsxValues,
        array $docxPreviews,
        array $docxTexts,
    ): void {
        Http::fake(function (Request $request) use (&$xlsxValues, &$docxPreviews, &$docxTexts): \GuzzleHttp\Promise\PromiseInterface {
            if (str_ends_with($request->url(), '/v1/xlsx/manifests')) {
                $value = array_shift($xlsxValues);
                $this->assertIsString($value);

                return $this->xlsxProcessorResponse($request, $value);
            }

            if (str_ends_with($request->url(), '/v1/docx/previews')) {
                $preview = array_shift($docxPreviews);
                $this->assertIsString($preview);

                return $this->docxProcessorResponse($request, $preview);
            }

            $text = array_shift($docxTexts);
            $this->assertIsString($text);

            return $this->docxPdfInspectionResponse($request, $text);
        });
    }

    private function xlsxProcessorResponse(Request $request, string $value): \GuzzleHttp\Promise\PromiseInterface
    {
        $xlsx = $request->body();
        $nonce = $this->requestNonce($request);
        $inputHash = hash('sha256', $xlsx);
        $body = json_encode([
            'schema' => XlsxProcessorProtocol::RESPONSE_SCHEMA,
            'profile' => XlsxProcessorProtocol::PROCESSOR_PROFILE,
            'engine' => [
                'name' => XlsxProcessorProtocol::ENGINE_NAME,
                'version' => XlsxProcessorProtocol::ENGINE_VERSION,
            ],
            'input' => ['bytes' => strlen($xlsx), 'sha256' => $inputHash],
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
                self::XLSX_SECRET,
            ),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function docxProcessorResponse(Request $request, string $preview): \GuzzleHttp\Promise\PromiseInterface
    {
        $docx = $request->body();
        $nonce = $this->requestNonce($request);
        $inputHash = hash('sha256', $docx);

        return Http::response($preview, 200, [
            'Cache-Control' => 'no-store',
            'Content-Type' => DocxProcessorProtocol::OUTPUT_MEDIA_TYPE,
            'X-Content-Type-Options' => 'nosniff',
            'X-ArtifactFlow-Processor-Nonce' => $nonce,
            'X-ArtifactFlow-Input-Bytes' => (string) strlen($docx),
            'X-ArtifactFlow-Input-SHA256' => $inputHash,
            'X-ArtifactFlow-Response-SHA256' => hash('sha256', $preview),
            'X-ArtifactFlow-Processor-Profile' => DocxProcessorProtocol::PROCESSOR_PROFILE,
            'X-ArtifactFlow-Processor-Schema' => DocxProcessorProtocol::RESPONSE_SCHEMA,
            'X-ArtifactFlow-Processor-Engine' => DocxProcessorProtocol::ENGINE_NAME,
            'X-ArtifactFlow-Processor-Engine-Version' => DocxProcessorProtocol::ENGINE_VERSION,
            'X-ArtifactFlow-Package-Entry-Count' => '7',
            'X-ArtifactFlow-Package-Expanded-Bytes' => '2048',
            'X-ArtifactFlow-Package-Relationship-Count' => '2',
            'X-ArtifactFlow-Package-Media-Count' => '0',
            'X-ArtifactFlow-Package-External-Hyperlink-Count' => '1',
            'X-ArtifactFlow-Processor-Signature' => DocxProcessorProtocol::responseSignature(
                $nonce,
                strlen($docx),
                $inputHash,
                $preview,
                7,
                2_048,
                2,
                0,
                1,
                self::DOCX_SECRET,
            ),
        ]);
    }

    private function docxPdfInspectionResponse(Request $request, string $text): \GuzzleHttp\Promise\PromiseInterface
    {
        $pdf = $request->body();
        $nonce = $this->requestNonce($request);
        $this->assertStringEndsWith('/v1/inspect-docx-preview', $request->url());
        $this->assertSame(
            PdfProcessorProtocol::DOCX_PREVIEW_PROFILE,
            $request->header('X-ArtifactFlow-Processor-Profile')[0] ?? null,
        );
        $body = json_encode([
            'page_count' => 1,
            'pdf_version' => '1.7',
            'extraction_state' => 'indexed',
            'processor_profile' => PdfProcessingResult::DOCX_PREVIEW_PROCESSOR_PROFILE,
            'text' => $text,
        ], JSON_THROW_ON_ERROR);

        return Http::response($body, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-ArtifactFlow-Processor-Signature' => PdfProcessorProtocol::responseSignature(
                $nonce,
                hash('sha256', $pdf),
                $body,
                self::PDF_SECRET,
                PdfProcessorProtocol::DOCX_PREVIEW_PROFILE,
            ),
        ]);
    }

    private function requestNonce(Request $request): string
    {
        $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
        $this->assertIsString($nonce);

        return $nonce;
    }

    /**
     * @param class-string<\Throwable> $expectedException
     * @param \Closure(): mixed $operation
     */
    private function assertOperationThrows(string $expectedException, \Closure $operation): void
    {
        try {
            $operation();
            $this->fail(sprintf('Expected %s to be thrown.', $expectedException));
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($expectedException, $exception);
        }
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
