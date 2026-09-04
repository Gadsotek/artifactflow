<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\ExternalSharing\CreateExternalShare;
use App\Application\ExternalSharing\CreateExternalShareCommand;
use App\Application\ExternalSharing\ExchangeExternalShare;
use App\Application\ExternalSharing\ExternalArtifactPreviewUrl;
use App\Application\ExternalSharing\ExternalPagePresentationRegistry;
use App\Application\ExternalSharing\ExternalShareViewContext;
use App\Application\ExternalSharing\ExternalShareViewerContent;
use App\Application\ExternalSharing\ExternalShareWindowToken;
use App\Application\ExternalSharing\ResolveExternalShareView;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\DocxProcessorProtocol;
use App\Application\PageCatalog\PdfProcessingResult;
use App\Application\PageCatalog\XlsxManifestValidator;
use App\Application\PageCatalog\XlsxProcessorProtocol;
use App\Domain\DomainRuleViolation;
use App\Domain\ExternalSharing\ExternalPagePresentation;
use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\ExternalSharing\ExternalShareSessionKind;
use App\Domain\PageCatalog\ArtifactDerivativeKind;
use App\Domain\PageCatalog\PageType;
use App\Models\DocxVersionFact;
use App\Models\ExternalShare;
use App\Models\InstallationSettings;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use App\Models\User;
use App\Models\XlsxVersionFact;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite as ViteFacade;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\TestCase;

final class ExternalSharePresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_type_has_an_explicit_external_presentation(): void
    {
        $registry = app(ExternalPagePresentationRegistry::class);
        $presentations = [];

        foreach (PageType::cases() as $pageType) {
            $presentations[$pageType->value] = $registry->forPageType($pageType);
        }

        $this->assertSame([
            PageType::Markdown->value => ExternalPagePresentation::Markdown,
            PageType::HtmlArtifact->value => ExternalPagePresentation::SandboxedHtml,
            PageType::Image->value => ExternalPagePresentation::ScriptlessImage,
            PageType::Pdf->value => ExternalPagePresentation::NativePdf,
            PageType::Xlsx->value => ExternalPagePresentation::TypedSpreadsheet,
            PageType::Docx->value => ExternalPagePresentation::DerivedDocumentPdf,
        ], $presentations);
    }

    public function test_pdf_external_share_creation_requires_the_pdf_feature(): void
    {
        [$owner, $page] = $this->pageFixture(PageType::Markdown, '# Placeholder');
        $page->forceFill(['type' => PageType::Pdf])->save();
        config(['pdf_processor.enabled' => false]);

        try {
            app(CreateExternalShare::class)->handle(
                $owner,
                new CreateExternalShareCommand(
                    $page->uid,
                    ExternalShareMode::ExpiresAt,
                    CarbonImmutable::now()->addHour(),
                ),
            );
            $this->fail('PDF external sharing must fail closed while PDF artifacts are disabled.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame(
                'External sharing is not available while PDF artifacts are disabled.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, ExternalShare::query()->where('page_uid', $page->uid)->count());
    }

    public function test_office_external_share_creation_requires_each_complete_preview_pipeline(): void
    {
        [$owner, $page] = $this->pageFixture(PageType::Markdown, '# Placeholder');

        foreach ([
            [PageType::Xlsx, ['xlsx_processor.enabled' => false], 'Excel workbook'],
            [PageType::Docx, [
                'docx_processor.enabled' => true,
                'pdf_processor.enabled' => false,
            ], 'Word document'],
        ] as [$type, $configuration, $label]) {
            $page->forceFill(['type' => $type])->save();
            config($configuration);

            try {
                app(CreateExternalShare::class)->handle(
                    $owner,
                    new CreateExternalShareCommand(
                        $page->uid,
                        ExternalShareMode::ExpiresAt,
                        CarbonImmutable::now()->addHour(),
                    ),
                );
                $this->fail(sprintf('%s external sharing must fail closed when its preview pipeline is disabled.', $label));
            } catch (DomainRuleViolation $exception) {
                $this->assertSame(
                    sprintf('External sharing is not available while %s artifacts are disabled.', $label),
                    $exception->getMessage(),
                );
            }
        }

        $this->assertSame(0, ExternalShare::query()->where('page_uid', $page->uid)->count());
    }

    public function test_xlsx_external_share_serves_only_the_validated_typed_manifest(): void
    {
        $this->configureOfficePreviewOrigins();
        config(['xlsx_processor.enabled' => true]);
        [$owner, $page, $version, $derivative] = $this->officePageFixture(
            PageType::Xlsx,
            "PK\x03\x04private-original-workbook",
            $this->xlsxManifest('Externally visible cell'),
        );
        [$shareUid, $sessionUid, $credential, $secret, $windowToken] = $this->viewCredential($owner, $page);

        $viewer = $this->viewerContent($shareUid, $sessionUid, $credential, $windowToken);
        $viewer
            ->assertOk()
            ->assertSee('data-xlsx-preview', false)
            ->assertSee('sandbox="allow-scripts"', false)
            ->assertSee('data-artifact-preview-refresh-endpoint', false)
            ->assertSee('Read-only Excel preview. Formulas are not recalculated')
            ->assertSee('original workbook bytes are never shared')
            ->assertDontSee('document-original', false)
            ->assertDontSee($secret);

        $context = $this->externalContext($shareUid, $sessionUid, $credential);
        $content = app(ExternalShareViewerContent::class)->forContext($context);
        $this->assertNotNull($content);
        $this->assertSame(ExternalPagePresentation::TypedSpreadsheet, $content->presentation);
        $previewUrl = $content->artifactPreviewUrl;
        $this->assertIsString($previewUrl);

        config(['app.runtime_role' => 'artifact-host']);
        $preview = $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($previewUrl);
        $preview
            ->assertOk()
            ->assertSee('id="xlsx-manifest" type="application/octet-stream"', false)
            ->assertDontSee('private-original-workbook', false)
            ->assertHeaderMissing('Set-Cookie')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->assertStringContainsString(
            'Externally visible cell',
            $this->embeddedXlsxManifest((string) $preview->getContent()),
        );
        $csp = (string) $preview->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('sandbox allow-scripts', $csp);
        $this->assertStringNotContainsString('allow-popups', $csp);
        $this->assertStringContainsString("connect-src 'none'", $csp);
        $this->assertStringContainsString("frame-src 'none'", $csp);

        Storage::disk('artifacts')->put($derivative->storage_path, '{"tampered":true}');
        $this->withHeader('Sec-Fetch-Dest', 'iframe')
            ->get($previewUrl)
            ->assertNotFound()
            ->assertHeader('X-Frame-Options', 'DENY');

        Storage::disk('artifacts')->put($derivative->storage_path, $this->xlsxManifest('Externally visible cell'));
        config(['xlsx_processor.enabled' => false]);
        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($previewUrl)->assertNotFound();
        $this->assertSame($version->uid, $page->current_version_uid);
    }

    public function test_docx_external_share_serves_only_the_searchable_pdf_derivative(): void
    {
        $this->configureOfficePreviewOrigins();
        config([
            'docx_processor.enabled' => true,
            'pdf_processor.enabled' => true,
        ]);
        $previewPdf = "%PDF-1.7\nSearchable shared Word preview\n%%EOF\n";
        [$owner, $page, , $derivative] = $this->officePageFixture(
            PageType::Docx,
            "PK\x03\x04private-original-document",
            $previewPdf,
        );
        [$shareUid, $sessionUid, $credential, $secret, $windowToken] = $this->viewCredential($owner, $page);

        $viewer = $this->viewerContent($shareUid, $sessionUid, $credential, $windowToken);
        $viewer
            ->assertOk()
            ->assertSee('data-docx-preview', false)
            ->assertSee('title="Word document PDF preview"', false)
            ->assertSee('Searchable PDF preview derived from the retained Word document')
            ->assertSee('The original DOCX is not shared')
            ->assertDontSee('sandbox=', false)
            ->assertDontSee('data-artifact-preview-refresh-endpoint', false)
            ->assertDontSee('document-original', false)
            ->assertDontSee($secret);

        $context = $this->externalContext($shareUid, $sessionUid, $credential);
        $content = app(ExternalShareViewerContent::class)->forContext($context);
        $this->assertNotNull($content);
        $this->assertSame(ExternalPagePresentation::DerivedDocumentPdf, $content->presentation);
        $previewUrl = $content->artifactPreviewUrl;
        $this->assertIsString($previewUrl);

        config(['app.runtime_role' => 'artifact-host']);
        $preview = $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($previewUrl);
        $preview
            ->assertOk()
            ->assertContent($previewPdf)
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeaderMissing('Set-Cookie')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
        $csp = (string) $preview->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-ancestors http://app.example.test', $csp);
        $this->assertStringNotContainsString('sandbox', $csp);

        Storage::disk('artifacts')->put($derivative->storage_path, "%PDF-1.7\ntampered\n%%EOF\n");
        $this->withHeader('Sec-Fetch-Dest', 'iframe')
            ->get($previewUrl)
            ->assertNotFound()
            ->assertHeader('X-Frame-Options', 'DENY');

        Storage::disk('artifacts')->put($derivative->storage_path, $previewPdf);
        config(['pdf_processor.enabled' => false]);
        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($previewUrl)->assertNotFound();
    }

    public function test_pdf_uses_the_native_viewer_and_a_share_bound_pdf_response(): void
    {
        config([
            'app.artifact_frame_ancestors' => 'http://app.example.test',
            'app.artifact_url' => 'http://artifacts.example.test',
            'app.artifact_url_signing_key' => str_repeat('e', 32),
            'app.url' => 'http://app.example.test',
            'pdf_processor.enabled' => true,
        ]);
        [$owner, $page] = $this->pageFixture(PageType::Markdown, '# Placeholder');
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";
        Storage::disk('artifacts')->put($version->content_storage_path, $pdf);
        $version->forceFill([
            'byte_size' => strlen($pdf),
            'content_hash' => hash('sha256', $pdf),
        ])->save();
        $page->forceFill(['type' => PageType::Pdf])->save();
        $page = $page->refresh();
        [$shareUid, $sessionUid, $credential, $secret, $windowToken] = $this->viewCredential($owner, $page);

        $viewer = $this->viewerContent($shareUid, $sessionUid, $credential, $windowToken);

        $viewer
            ->assertOk()
            ->assertSee('data-pdf-preview', false)
            ->assertSee('title="PDF preview"', false)
            ->assertSee('Viewing this PDF is download-equivalent.')
            ->assertSee('save, print, and copy controls')
            ->assertDontSee('sandbox=', false)
            ->assertDontSee('data-artifact-preview-refresh-endpoint', false)
            ->assertDontSee("/external-shares/{$shareUid}/sessions/{$sessionUid}/artifact-preview-url", false)
            ->assertDontSee($secret);

        $context = app(ResolveExternalShareView::class)->withCredential(
            $shareUid,
            $sessionUid,
            $credential,
            static fn (ExternalShareViewContext $context): ExternalShareViewContext => $context,
        );
        $this->assertNotNull($context);
        $content = app(ExternalShareViewerContent::class)->forContext($context);
        $this->assertNotNull($content);
        $this->assertSame(ExternalPagePresentation::NativePdf, $content->presentation);
        $previewUrl = $content->artifactPreviewUrl;
        $this->assertIsString($previewUrl);
        $this->assertStringStartsWith(
            "http://artifacts.example.test/external-artifact-previews/{$shareUid}/sessions/",
            $previewUrl,
        );
        $this->assertStringNotContainsString($secret, $previewUrl);
        $this->assertStringNotContainsString($credential, $previewUrl);

        config(['app.runtime_role' => 'artifact-host']);

        $preview = $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($previewUrl);
        $preview
            ->assertOk()
            ->assertContent($pdf)
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', sprintf(
                'inline; filename="artifactflow-%s-v%d.pdf"',
                strtolower($page->uid),
                $version->version_number,
            ))
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Accept-Ranges', 'none')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('X-Frame-Options')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
        $csp = (string) $preview->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString('frame-ancestors http://app.example.test', $csp);
        $this->assertStringNotContainsString('sandbox', $csp);
        $this->assertFalse($preview->headers->has('Set-Cookie'));

        $invalidUrl = preg_replace(
            '/signature=[a-f0-9]+/D',
            'signature=' . str_repeat('0', 64),
            $previewUrl,
        );
        $this->assertIsString($invalidUrl);
        $this->withHeader('Sec-Fetch-Dest', 'iframe')
            ->get($invalidUrl)
            ->assertNotFound()
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_external_pdf_delivery_rejects_storage_bytes_that_do_not_match_the_version(): void
    {
        config([
            'app.artifact_url' => 'http://artifacts.example.test',
            'app.artifact_url_signing_key' => str_repeat('e', 32),
            'app.url' => 'http://app.example.test',
            'pdf_processor.enabled' => true,
        ]);
        [$owner, $page] = $this->pageFixture(PageType::Markdown, '# Placeholder');
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";
        Storage::disk('artifacts')->put($version->content_storage_path, $pdf);
        $version->forceFill([
            'byte_size' => strlen($pdf),
            'content_hash' => hash('sha256', $pdf),
        ])->save();
        $page->forceFill(['type' => PageType::Pdf])->save();
        $page = $page->refresh();
        [$shareUid, $sessionUid, $credential] = $this->viewCredential($owner, $page);
        $context = app(ResolveExternalShareView::class)->withCredential(
            $shareUid,
            $sessionUid,
            $credential,
            static fn (ExternalShareViewContext $context): ExternalShareViewContext => $context,
        );
        $this->assertNotNull($context);
        $content = app(ExternalShareViewerContent::class)->forContext($context);
        $this->assertNotNull($content);
        $previewUrl = $content->artifactPreviewUrl;
        $this->assertIsString($previewUrl);
        config(['app.runtime_role' => 'artifact-host']);

        $this->withHeader('Sec-Fetch-Dest', 'iframe')
            ->get($previewUrl)
            ->assertOk()
            ->assertContent($pdf);

        Storage::disk('artifacts')->put(
            $version->content_storage_path,
            str_repeat('X', strlen($pdf)),
        );
        $this->withHeader('Sec-Fetch-Dest', 'iframe')
            ->get($previewUrl)
            ->assertNotFound()
            ->assertHeader('X-Frame-Options', 'DENY');

        Storage::disk('artifacts')->put($version->content_storage_path, $pdf . 'tampered');
        $this->withHeader('Sec-Fetch-Dest', 'iframe')
            ->get($previewUrl)
            ->assertNotFound()
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_disabling_pdf_artifacts_closes_an_existing_external_pdf_view(): void
    {
        config([
            'app.artifact_url' => 'http://artifacts.example.test',
            'app.artifact_url_signing_key' => str_repeat('e', 32),
            'app.url' => 'http://app.example.test',
            'pdf_processor.enabled' => true,
        ]);
        [$owner, $page] = $this->pageFixture(PageType::Markdown, '# Placeholder');
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        Storage::disk('artifacts')->put($version->content_storage_path, "%PDF-1.4\n%%EOF\n");
        $page->forceFill(['type' => PageType::Pdf])->save();
        $page = $page->refresh();
        [$shareUid, $sessionUid, $credential, , $windowToken] = $this->viewCredential($owner, $page);
        $context = app(ResolveExternalShareView::class)->withCredential(
            $shareUid,
            $sessionUid,
            $credential,
            static fn (ExternalShareViewContext $context): ExternalShareViewContext => $context,
        );
        $this->assertNotNull($context);
        $content = app(ExternalShareViewerContent::class)->forContext($context);
        $this->assertNotNull($content);
        $previewUrl = $content->artifactPreviewUrl;
        $this->assertIsString($previewUrl);

        config(['pdf_processor.enabled' => false]);

        $this->viewerContent($shareUid, $sessionUid, $credential, $windowToken)
            ->assertNotFound()
            ->assertSee('This external artifact is unavailable.');
        config(['app.runtime_role' => 'artifact-host']);
        $this->withHeader('Sec-Fetch-Dest', 'iframe')
            ->get($previewUrl)
            ->assertNotFound()
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_markdown_is_sanitized_without_resolving_private_wiki_links(): void
    {
        [$owner, $page] = $this->pageFixture(
            PageType::Markdown,
            "# Shared\n\n[[Private runbook]]\n\n<script>document.body.dataset.leaked = 'yes'</script>",
        );
        [$shareUid, $sessionUid, $credential, , $windowToken] = $this->viewCredential($owner, $page);

        $response = $this->viewerContent($shareUid, $sessionUid, $credential, $windowToken);

        $response
            ->assertOk()
            ->assertSee('[[Private runbook]]')
            ->assertDontSee('<script>document.body.dataset.leaked', false)
            ->assertDontSee('/pages/', false)
            ->assertDontSee('Version history')
            ->assertDontSee('Download');
    }

    public function test_html_uses_an_opaque_script_sandbox_and_a_share_bound_artifact_url(): void
    {
        $openedAt = CarbonImmutable::parse('2026-07-30T10:00:00Z');
        $this->travelTo($openedAt);
        config([
            'app.artifact_frame_ancestors' => 'http://app.example.test',
            'app.artifact_url' => 'http://artifacts.example.test',
            'app.artifact_url_signing_key' => str_repeat('e', 32),
            'app.url' => 'http://app.example.test',
        ]);
        [$owner, $page] = $this->pageFixture(
            PageType::HtmlArtifact,
            '<!doctype html><html><body><h1>Shared HTML</h1><script>document.body.dataset.ready = "yes";</script></body></html>',
        );
        [$shareUid, $sessionUid, $credential, $secret, $windowToken] = $this->viewCredential($owner, $page);

        $viewer = $this->viewerContent($shareUid, $sessionUid, $credential, $windowToken);

        $viewer
            ->assertOk()
            ->assertSee('sandbox="allow-scripts"', false)
            ->assertSee('data-artifact-preview-refresh-endpoint', false)
            ->assertSee("/external-shares/{$shareUid}/sessions/{$sessionUid}/artifact-preview-url", false)
            ->assertDontSee('allow-same-origin', false)
            ->assertDontSee($secret);

        $context = app(ResolveExternalShareView::class)->withCredential(
            $shareUid,
            $sessionUid,
            $credential,
            static fn (ExternalShareViewContext $context): ExternalShareViewContext => $context,
        );
        $this->assertNotNull($context);
        $content = app(ExternalShareViewerContent::class)->forContext($context);
        $this->assertNotNull($content);
        $previewUrl = $content->artifactPreviewUrl;
        $this->assertIsString($previewUrl);
        $artifactUrls = app(ExternalArtifactPreviewUrl::class);
        $this->assertFalse($artifactUrls->hasValidSignature(
            $context,
            $content->version->uid,
            null,
            null,
        ));
        $this->assertFalse($artifactUrls->hasValidSignature(
            $context,
            $content->version->uid,
            'not-a-timestamp',
            str_repeat('a', 64),
        ));
        $this->assertFalse($artifactUrls->hasValidSignature(
            $context,
            $content->version->uid,
            $context->share->expires_at?->addSecond()->getTimestamp(),
            str_repeat('a', 64),
        ));
        $this->assertStringStartsWith(
            "http://artifacts.example.test/external-artifact-previews/{$shareUid}/sessions/",
            $previewUrl,
        );
        $this->assertStringNotContainsString($secret, $previewUrl);
        $this->assertStringNotContainsString($credential, $previewUrl);
        $appUrl = config('app.url');
        $this->assertIsString($appUrl);
        $refreshEndpoint = rtrim($appUrl, '/')
            . "/external-shares/{$shareUid}/sessions/{$sessionUid}/artifact-preview-url";
        $share = ExternalShare::query()->findOrFail($shareUid);
        $openedLastViewedAt = $share->last_viewed_at;
        $this->assertInstanceOf(CarbonImmutable::class, $openedLastViewedAt);
        $this->assertTrue($openedLastViewedAt->equalTo($openedAt));

        $previewRefreshedAt = $openedAt->addMinutes(10);
        $this->travelTo($previewRefreshedAt);

        $refreshedPreview = $this->withHeaders($this->sameOriginHeaders())
            ->withUnencryptedCookie('artifactflow_external_view', $credential)
            ->post($refreshEndpoint, [
                'window_token' => $windowToken,
            ])
            ->assertOk()
            ->assertJsonPath('url', fn (mixed $url): bool => is_string($url)
                && str_starts_with(
                    $url,
                    "http://artifacts.example.test/external-artifact-previews/{$shareUid}/sessions/",
                ));
        $refreshedPreviewUrl = $refreshedPreview->json('url');
        $this->assertIsString($refreshedPreviewUrl);
        $refreshedLastViewedAt = $share->refresh()->last_viewed_at;
        $this->assertInstanceOf(CarbonImmutable::class, $refreshedLastViewedAt);
        $this->assertTrue($refreshedLastViewedAt->equalTo($previewRefreshedAt));

        $this->travelTo($previewRefreshedAt->addMinutes(10));
        $this->withHeaders($this->sameOriginHeaders())
            ->withUnencryptedCookie('artifactflow_external_view', $credential)
            ->post($refreshEndpoint, [
                'window_token' => str_repeat('0', 64),
            ])
            ->assertNotFound()
            ->assertExactJson(['state' => 'unavailable']);
        $invalidRequestLastViewedAt = $share->refresh()->last_viewed_at;
        $this->assertInstanceOf(CarbonImmutable::class, $invalidRequestLastViewedAt);
        $this->assertTrue($invalidRequestLastViewedAt->equalTo($previewRefreshedAt));

        $this->travelTo($previewRefreshedAt);
        config(['app.runtime_role' => 'artifact-host']);

        $preview = $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($refreshedPreviewUrl);
        $preview
            ->assertOk()
            ->assertSee('<h1>Shared HTML</h1>', false)
            ->assertSee('data-artifactflow-preview-guard', false)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $csp = (string) $preview->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('sandbox allow-scripts', $csp);
        $this->assertStringNotContainsString('allow-same-origin', $csp);
        $this->assertStringContainsString("connect-src 'none'", $csp);
        $this->assertStringContainsString('frame-ancestors http://app.example.test', $csp);
        $this->assertFalse($preview->headers->has('Set-Cookie'));
    }

    public function test_image_uses_an_empty_iframe_sandbox_and_scriptless_artifact_response(): void
    {
        config([
            'app.artifact_frame_ancestors' => 'http://app.example.test',
            'app.artifact_url' => 'http://artifacts.example.test',
            'app.artifact_url_signing_key' => str_repeat('e', 32),
            'app.url' => 'http://app.example.test',
        ]);
        [$owner, $page] = $this->pageFixture(PageType::Markdown, '# Placeholder');
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $png = $this->png();
        Storage::disk('artifacts')->put($version->content_storage_path, $png);
        $page->forceFill(['type' => PageType::Image])->save();
        $page = $page->refresh();
        [$shareUid, $sessionUid, $credential, , $windowToken] = $this->viewCredential($owner, $page);

        $viewer = $this->viewerContent($shareUid, $sessionUid, $credential, $windowToken);

        $viewer
            ->assertOk()
            ->assertSee('sandbox=""', false)
            ->assertDontSee('sandbox="allow-scripts"', false)
            ->assertDontSee('data-artifact-preview-refresh-endpoint', false)
            ->assertDontSee("/external-shares/{$shareUid}/sessions/{$sessionUid}/artifact-preview-url", false);

        $context = app(ResolveExternalShareView::class)->withCredential(
            $shareUid,
            $sessionUid,
            $credential,
            static fn (ExternalShareViewContext $context): ExternalShareViewContext => $context,
        );
        $this->assertNotNull($context);
        $content = app(ExternalShareViewerContent::class)->forContext($context);
        $this->assertNotNull($content);
        $previewUrl = $content->artifactPreviewUrl;
        $this->assertIsString($previewUrl);

        config(['app.runtime_role' => 'artifact-host']);

        $preview = $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($previewUrl);
        $preview->assertOk();
        $html = $preview->streamedContent();
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringNotContainsString('<script', $html);
        $csp = (string) $preview->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('sandbox', $csp);
        $this->assertStringNotContainsString('sandbox allow-scripts', $csp);
        $this->assertStringContainsString("script-src 'none'", $csp);
        $this->assertStringContainsString('img-src data:', $csp);
    }

    private function configureOfficePreviewOrigins(): void
    {
        config([
            'app.artifact_frame_ancestors' => 'http://app.example.test',
            'app.artifact_url' => 'http://artifacts.example.test',
            'app.artifact_url_signing_key' => str_repeat('e', 32),
            'app.url' => 'http://app.example.test',
        ]);

        ViteFacade::clearResolvedInstance();
        $this->app->instance(Vite::class, new class() extends Vite {
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

    /**
     * @return array{User, Page, PageVersion, PageVersionDerivative}
     */
    private function officePageFixture(PageType $type, string $original, string $derivativeBytes): array
    {
        [$owner, $page] = $this->pageFixture(PageType::Markdown, '# Placeholder');
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        Storage::disk('artifacts')->put($version->content_storage_path, $original);
        $version->forceFill([
            'byte_size' => strlen($original),
            'content_hash' => hash('sha256', $original),
            'extracted_text' => $type === PageType::Docx ? 'Searchable shared Word preview' : 'Externally visible cell',
        ])->save();
        $page->forceFill(['type' => $type])->save();
        $page = $page->refresh();
        $derivativePath = sprintf(
            'pages/%s/versions/%d/external-share-%s',
            $page->uid,
            $version->version_number,
            $type === PageType::Xlsx ? 'manifest.json' : 'preview.pdf',
        );
        Storage::disk('artifacts')->put($derivativePath, $derivativeBytes);
        $kind = $type === PageType::Xlsx
            ? ArtifactDerivativeKind::XlsxManifest
            : ArtifactDerivativeKind::DocxPreviewPdf;
        $derivative = PageVersionDerivative::query()->forceCreate([
            'page_version_uid' => $version->uid,
            'kind' => $kind,
            'storage_path' => $derivativePath,
            'content_hash' => hash('sha256', $derivativeBytes),
            'byte_size' => strlen($derivativeBytes),
        ]);

        if ($type === PageType::Xlsx) {
            XlsxVersionFact::query()->forceCreate([
                'page_version_uid' => $version->uid,
                'manifest_derivative_uid' => $derivative->uid,
                'processor_profile' => XlsxProcessorProtocol::PROCESSOR_PROFILE,
                'manifest_schema' => 'xlsx-view-manifest-v1',
                'engine_name' => XlsxProcessorProtocol::ENGINE_NAME,
                'engine_version' => XlsxProcessorProtocol::ENGINE_VERSION,
                'package_entry_count' => 8,
                'expanded_bytes' => 1_024,
                'visible_sheet_count' => 1,
                'omitted_hidden_sheet_count' => 0,
                'projected_row_extent_count' => 1,
                'projected_column_extent_count' => 1,
                'omitted_hidden_row_count' => 0,
                'omitted_hidden_column_count' => 0,
                'cell_count' => 1,
                'formula_count' => 0,
                'uncached_formula_count' => 0,
                'link_count' => 0,
                'merge_count' => 0,
                'truncated' => false,
                'processed_at' => CarbonImmutable::now(),
            ]);
        } else {
            DocxVersionFact::query()->forceCreate([
                'page_version_uid' => $version->uid,
                'preview_derivative_uid' => $derivative->uid,
                'docx_processor_profile' => DocxProcessorProtocol::PROCESSOR_PROFILE,
                'pdf_processor_profile' => PdfProcessingResult::DOCX_PREVIEW_PROCESSOR_PROFILE,
                'engine_name' => DocxProcessorProtocol::ENGINE_NAME,
                'engine_version' => DocxProcessorProtocol::ENGINE_VERSION,
                'package_entry_count' => 7,
                'expanded_bytes' => 2_048,
                'relationship_count' => 2,
                'media_count' => 0,
                'external_hyperlink_count' => 1,
                'page_count' => 1,
                'pdf_version' => '1.7',
                'extraction_state' => 'indexed',
                'processed_at' => CarbonImmutable::now(),
            ]);
        }

        return [$owner, $page, $version, $derivative];
    }

    private function xlsxManifest(string $value): string
    {
        return app(XlsxManifestValidator::class)->validate([
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
        ])->manifestJson;
    }

    private function externalContext(
        string $shareUid,
        string $sessionUid,
        string $credential,
    ): ExternalShareViewContext {
        $context = app(ResolveExternalShareView::class)->withCredential(
            $shareUid,
            $sessionUid,
            $credential,
            static fn (ExternalShareViewContext $context): ExternalShareViewContext => $context,
        );
        $this->assertNotNull($context);

        return $context;
    }

    private function embeddedXlsxManifest(string $html): string
    {
        $matched = preg_match(
            '/<script id="xlsx-manifest" type="application\/octet-stream">([A-Za-z0-9_-]+)<\/script>/D',
            $html,
            $matches,
        );
        $this->assertSame(1, $matched);
        $encoded = $matches[1];
        $standard = strtr($encoded, '-_', '+/');
        $padding = (4 - strlen($standard) % 4) % 4;
        $decoded = base64_decode($standard . str_repeat('=', $padding), true);
        $this->assertIsString($decoded);

        return $decoded;
    }

    /**
     * @return array{User, Page}
     */
    private function pageFixture(PageType $type, string $content): array
    {
        Storage::fake('artifacts');
        $owner = app(CreateUser::class)->handle(
            'External Presentation Owner',
            'external-share-presentation-owner@example.test',
            'correct horse battery staple',
        );
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'External Presentation Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: $type,
            title: 'Externally presented artifact',
            description: 'Internal description',
            content: $content,
        ));
        $this->configureExternalSharing();

        return [$owner, $page];
    }

    /**
     * @return array{string, string, string, string, string}
     */
    private function viewCredential(User $owner, Page $page): array
    {
        $issued = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand(
                $page->uid,
                ExternalShareMode::ExpiresAt,
                CarbonImmutable::now()->addHour(),
            ),
        );
        $exchange = app(ExchangeExternalShare::class)->handle($issued->share->uid, $issued->secret());
        $this->assertNotNull($exchange);
        $this->assertSame(
            ExternalShareSessionKind::View->value,
            $exchange->issuedSession->session->kind,
        );

        return [
            $issued->share->uid,
            $exchange->issuedSession->session->uid,
            $exchange->issuedSession->credential(),
            $issued->secret(),
            app(ExternalShareWindowToken::class)->issue(
                $exchange->issuedSession->credential(),
            ),
        ];
    }

    /**
     * @return TestResponse<SymfonyResponse>
     */
    private function viewerContent(
        string $shareUid,
        string $sessionUid,
        string $viewCredential,
        string $windowToken,
    ): TestResponse {
        $appUrl = config('app.url');
        $this->assertIsString($appUrl);

        return $this->withHeaders([
            'Origin' => rtrim($appUrl, '/'),
            'Sec-Fetch-Site' => 'same-origin',
        ])
            ->withUnencryptedCookie('artifactflow_external_view', $viewCredential)
            ->post(
                rtrim($appUrl, '/') . "/external-shares/{$shareUid}/sessions/{$sessionUid}/viewer/content",
                [
                'window_token' => $windowToken,
                ],
            );
    }

    /**
     * @return array<string, string>
     */
    private function sameOriginHeaders(): array
    {
        $appUrl = config('app.url');
        $this->assertIsString($appUrl);

        return [
            'Origin' => rtrim($appUrl, '/'),
            'Sec-Fetch-Site' => 'same-origin',
        ];
    }

    private function configureExternalSharing(): void
    {
        $values = app(InstallationLimitSettings::class)->current();

        InstallationSettings::query()->forceCreate(array_merge($values->toPersistenceArray(), [
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'external_sharing_enabled' => true,
            'external_share_acknowledgement_required' => false,
            'external_share_max_expiry_hours' => 168,
        ]));
    }

    private function png(): string
    {
        $scanlines = "\x00\x23\x78\xdd\xff";
        $compressed = gzcompress($scanlines);
        $this->assertIsString($compressed);

        return "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0))
            . $this->pngChunk('IDAT', $compressed)
            . $this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            . $type
            . $data
            . pack('N', crc32($type . $data));
    }
}
