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
use App\Domain\ExternalSharing\ExternalPagePresentation;
use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\ExternalSharing\ExternalShareSessionKind;
use App\Domain\PageCatalog\PageType;
use App\Models\InstallationSettings;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
        ], $presentations);
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

        $this->withHeaders($this->sameOriginHeaders())
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

        $this->withHeaders($this->sameOriginHeaders())
            ->withUnencryptedCookie('artifactflow_external_view', $credential)
            ->post($refreshEndpoint, [
                'window_token' => str_repeat('0', 64),
            ])
            ->assertNotFound()
            ->assertExactJson(['state' => 'unavailable']);

        config(['app.runtime_role' => 'artifact-host']);

        $preview = $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($previewUrl);
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
