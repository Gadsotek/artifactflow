<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\ArtifactPreviewUrl;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\RasterImageNormalizer;
use App\Application\PageCatalog\RestorePageVersion;
use App\Application\PageCatalog\RestorePageVersionCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesImageParser;
use Tests\Support\RecordingLogger;
use Tests\TestCase;

final class ImageArtifactHttpTest extends TestCase
{
    use RefreshDatabase;
    use FakesImageParser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageParser();
    }

    public function test_disabled_image_artifacts_reject_new_uploads_without_contacting_the_parser(): void
    {
        Storage::fake('artifacts');
        config(['image_parser.enabled' => false]);

        $editor = $this->createUser('Disabled Image Editor', 'disabled-image-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Text Only Team');

        $this->actingAs($editor)
            ->from('/pages/create')
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'image',
                'mode' => 'image_upload',
                'title' => 'Disabled Screenshot',
                'status' => 'draft',
                'image_file' => $this->imageUpload('disabled.png', $this->png(), 'image/png'),
            ])
            ->assertRedirect('/pages/create')
            ->assertSessionHasErrors([
                'image_file' => 'Image artifacts are disabled for this installation.',
            ]);

        Http::assertNothingSent();
        $this->assertSame(0, Page::query()->count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_png_screenshot_is_normalized_stored_and_previewed_only_from_the_artifact_origin(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_url' => 'http://artifacts.example.test',
            'pages.max_image_bytes' => 1024 * 1024,
            'pages.max_image_pixels' => 100,
        ]);

        $editor = $this->createUser('Screenshot Editor', 'screenshot-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $rawUpload = $this->png(
            width: 2,
            height: 1,
            ancillaryText: 'GPS=50.087,14.421;payload=<script>alert(1)</script>',
            appendedBytes: '<?php payload(); ?>',
        );

        $this->actingAs($editor)
            ->get('/pages/create')
            ->assertOk()
            ->assertSee('Image / screenshot')
            ->assertSee('value="image_upload"', false)
            ->assertSee('data-create-page-image-upload-fields', false)
            ->assertSee('name="image_file"', false)
            ->assertSee('Metadata and non-pixel payloads are discarded.');

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'image',
                'mode' => 'image_upload',
                'title' => 'Deployment Screenshot',
                'description' => 'Production dashboard after the deployment.',
                'status' => 'draft',
                'image_file' => $this->imageUpload('deployment.png', $rawUpload, 'image/png'),
            ])
            ->assertRedirect();

        $page = Page::query()->where('title', 'Deployment Screenshot')->sole();
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $stored = Storage::disk('artifacts')->get($version->content_storage_path);
        $this->assertIsString($stored);

        $this->assertSame('image', $page->type->value);
        $this->assertSame('upload', $version->source->value);
        $this->assertStringEndsWith('/preview.png', $version->content_storage_path);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($stored, 0, 8));
        $this->assertNotSame($rawUpload, $stored);
        $this->assertStringNotContainsString('GPS=', $stored);
        $this->assertStringNotContainsString('<script>', $stored);
        $this->assertStringNotContainsString('<?php', $stored);
        $this->assertSame(hash('sha256', $stored), $version->content_hash);
        $this->assertSame(strlen($stored), $version->byte_size);
        $this->assertNull($version->extracted_text);
        $this->assertNull($version->source_text);

        $pageResponse = $this->actingAs($editor)->get("/pages/{$page->uid}");
        $pageResponse
            ->assertOk()
            ->assertSee('Image artifact')
            ->assertSee('data-image-preview', false)
            ->assertDontSee('data-artifact-preview-refresh-mode', false)
            ->assertDontSee("/pages/{$page->uid}/artifact-preview-url", false)
            ->assertSee('data-artifact-preview-frame', false)
            ->assertSee('loading="eager"', false)
            ->assertSee('sandbox=""', false)
            ->assertDontSee('sandbox="allow-scripts"', false)
            ->assertDontSee('Edit HTML source')
            ->assertDontSee('Edit Markdown');

        $previewUrl = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);

        config(['app.runtime_role' => 'artifact-host']);

        $previewResponse = $this
            ->withHeader('Sec-Fetch-Dest', 'iframe')
            ->get($previewUrl);

        $previewResponse
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertInstanceOf(
            \Symfony\Component\HttpFoundation\StreamedResponse::class,
            $previewResponse->baseResponse,
        );
        $previewHtml = $previewResponse->streamedContent();
        $this->assertStringContainsString('data-artifactflow-image-preview', $previewHtml);
        $this->assertStringContainsString('data:image/png;base64,', $previewHtml);
        $this->assertStringNotContainsString('allow-scripts', $previewHtml);
        $this->assertStringNotContainsString('GPS=', $previewHtml);
        $this->assertStringNotContainsString('<?php', $previewHtml);

        $csp = (string) $previewResponse->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString('sandbox', $csp);
        $this->assertStringNotContainsString('sandbox allow-scripts', $csp);
        $this->assertStringContainsString('img-src data:', $csp);
        $this->assertStringContainsString("script-src 'none'", $csp);
        $this->assertStringContainsString("connect-src 'none'", $csp);
    }

    public function test_image_creation_rejects_svg_malformed_images_mime_extension_mismatches_and_pixel_bombs(): void
    {
        Storage::fake('artifacts');
        config([
            'pages.max_image_bytes' => 1024 * 1024,
            'pages.max_image_pixels' => 4,
        ]);

        $editor = $this->createUser('Screenshot Validator', 'screenshot-validator@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Validation Team');
        $base = [
            'workspace_uid' => $workspace->uid,
            'type' => 'image',
            'mode' => 'image_upload',
            'title' => 'Rejected Screenshot',
            'status' => 'draft',
        ];

        $this->actingAs($editor)
            ->from('/pages/create')
            ->post('/pages', $base + [
                'image_file' => $this->imageUpload(
                    'active.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
                    'image/svg+xml',
                ),
            ])
            ->assertRedirect('/pages/create')
            ->assertSessionHasErrors('image_file');

        $this->actingAs($editor)
            ->from('/pages/create')
            ->post('/pages', $base + [
                'image_file' => $this->imageUpload('broken.png', 'not an image', 'image/png'),
            ])
            ->assertRedirect('/pages/create')
            ->assertSessionHasErrors('image_file');

        $corruptRaster = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z4eQAAAAASUVORK5CYII=',
            true,
        );
        $this->assertIsString($corruptRaster);

        $this->actingAs($editor)
            ->from('/pages/create')
            ->post('/pages', $base + [
                'image_file' => $this->imageUpload('corrupt-raster.png', $corruptRaster, 'image/png'),
            ])
            ->assertRedirect('/pages/create')
            ->assertSessionHasErrors('image_file')
            ->assertSessionDoesntHaveErrors('content');

        $this->actingAs($editor)
            ->from('/pages/create')
            ->post('/pages', $base + [
                'image_file' => $this->imageUpload('wrong.jpg', $this->png(), 'image/png'),
            ])
            ->assertRedirect('/pages/create')
            ->assertSessionHasErrors('image_file');

        $this->actingAs($editor)
            ->from('/pages/create')
            ->post('/pages', $base + [
                'image_file' => $this->imageUpload('too-many-pixels.png', $this->png(3, 2), 'image/png'),
            ])
            ->assertRedirect('/pages/create')
            ->assertSessionHasErrors('image_file');

        $this->assertSame(0, Page::query()->count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_jpeg_upload_is_reencoded_without_comments_or_appended_bytes(): void
    {
        Storage::fake('artifacts');
        config([
            'pages.max_image_bytes' => 1024 * 1024,
            'pages.max_image_pixels' => 100,
        ]);

        $editor = $this->createUser('JPEG Editor', 'jpeg-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'JPEG Team');
        $jpeg = $this->jpeg(2, 1);
        $comment = 'GPS=50.087,14.421;<script>alert(1)</script>';
        $rawUpload = substr($jpeg, 0, 2)
            . "\xff\xfe"
            . pack('n', strlen($comment) + 2)
            . $comment
            . substr($jpeg, 2)
            . '<?php payload(); ?>';

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'image',
                'mode' => 'image_upload',
                'title' => 'JPEG Screenshot',
                'status' => 'draft',
                'image_file' => $this->imageUpload('screenshot.jpeg', $rawUpload, 'image/jpeg'),
            ])
            ->assertRedirect();

        $page = Page::query()->where('title', 'JPEG Screenshot')->sole();
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $stored = Storage::disk('artifacts')->get($version->content_storage_path);
        $this->assertIsString($stored);

        $this->assertStringEndsWith('/preview.jpg', $version->content_storage_path);
        $this->assertSame("\xff\xd8", substr($stored, 0, 2));
        $this->assertNotSame($rawUpload, $stored);
        $this->assertStringNotContainsString('GPS=', $stored);
        $this->assertStringNotContainsString('<script>', $stored);
        $this->assertStringNotContainsString('<?php', $stored);
    }

    public function test_jpeg_exif_orientation_is_applied_before_metadata_is_discarded(): void
    {
        config([
            'pages.max_image_bytes' => 1024 * 1024,
            'pages.max_image_pixels' => 10_000,
        ]);

        $source = $this->splitColorJpeg(40, 20);
        $normalizer = app(RasterImageNormalizer::class);
        $verticallyFlipped = $normalizer->normalize(
            $this->withExifOrientation($source, 4),
            'actor-orientation-flip',
        );
        $rotatedClockwise = $normalizer->normalize(
            $this->withExifOrientation($source, 6),
            'actor-orientation-rotate',
        );
        $rotatedFromStringTag = $normalizer->normalize(
            $this->withAsciiExifOrientation($source, '6'),
            'actor-orientation-string',
        );

        $flippedImage = imagecreatefromstring($verticallyFlipped);
        $this->assertInstanceOf(\GdImage::class, $flippedImage);
        $topColor = imagecolorat($flippedImage, 20, 2);
        $bottomColor = imagecolorat($flippedImage, 20, 17);
        $this->assertIsInt($topColor);
        $this->assertIsInt($bottomColor);
        $top = imagecolorsforindex($flippedImage, $topColor);
        $bottom = imagecolorsforindex($flippedImage, $bottomColor);
        imagedestroy($flippedImage);

        $this->assertGreaterThan($top['red'], $top['blue']);
        $this->assertGreaterThan($bottom['blue'], $bottom['red']);
        $rotatedDimensions = getimagesizefromstring($rotatedClockwise);
        $stringTagDimensions = getimagesizefromstring($rotatedFromStringTag);
        $this->assertIsArray($stringTagDimensions);
        $this->assertSame([20, 40], array_slice($stringTagDimensions, 0, 2));
        $this->assertIsArray($rotatedDimensions);
        $this->assertSame([20, 40], array_slice($rotatedDimensions, 0, 2));
        $this->assertStringNotContainsString('Exif', $verticallyFlipped);
        $this->assertStringNotContainsString('Exif', $rotatedClockwise);
    }

    public function test_image_upload_can_be_replaced_and_historical_versions_keep_isolated_previews(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_url' => 'http://artifacts.example.test',
            'pages.max_image_bytes' => 1024 * 1024,
            'pages.max_image_pixels' => 100,
        ]);

        $editor = $this->createUser('Versioned Screenshot Editor', 'versioned-screenshot@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Versioned Images');

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'image',
                'mode' => 'image_upload',
                'title' => 'Versioned Screenshot',
                'status' => 'approved',
                'image_file' => $this->imageUpload('first.png', $this->png(1, 1), 'image/png'),
            ])
            ->assertRedirect();

        $page = Page::query()->where('title', 'Versioned Screenshot')->sole();
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions", [
                'mode' => 'upload',
                'base_version_uid' => $firstVersion->uid,
                'image_file' => $this->imageUpload(
                    'second.png',
                    $this->png(2, 1, ancillaryText: 'must be removed'),
                    'image/png',
                ),
            ])
            ->assertRedirect("/pages/{$page->uid}");

        $page->refresh();
        $secondVersion = PageVersion::query()->findOrFail($page->current_version_uid);

        $this->assertSame(2, $secondVersion->version_number);
        $this->assertSame('draft', $page->status->value);
        $this->assertNotSame($firstVersion->content_hash, $secondVersion->content_hash);
        $storedSecondVersion = Storage::disk('artifacts')->get($secondVersion->content_storage_path);
        $this->assertIsString($storedSecondVersion);
        $this->assertStringNotContainsString(
            'must be removed',
            $storedSecondVersion,
        );

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Replace image')
            ->assertSee('name="image_file"', false)
            ->assertSee('sandbox=""', false);

        $historyResponse = $this->actingAs($editor)
            ->get("/pages/{$page->uid}/versions/{$firstVersion->uid}");

        $historyResponse
            ->assertOk()
            ->assertSee('Historical image')
            ->assertSee('sandbox=""', false)
            ->assertDontSee('data-artifact-preview-refresh-mode', false)
            ->assertDontSee(
                "/pages/{$page->uid}/versions/{$firstVersion->uid}/artifact-preview-url",
                false,
            )
            ->assertSee('data-artifact-preview-frame', false)
            ->assertSee('loading="eager"', false)
            ->assertDontSee('Source changes')
            ->assertDontSee('href="#version-changes"', false)
            ->assertSee('Binary image versions do not have a source diff.');

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions/{$firstVersion->uid}/restore", [
                'current_version_uid' => $secondVersion->uid,
            ])
            ->assertRedirect("/pages/{$page->uid}");

        $restored = PageVersion::query()->findOrFail($page->refresh()->current_version_uid);

        $this->assertSame($firstVersion->content_hash, $restored->content_hash);
        $this->assertSame(
            Storage::disk('artifacts')->get($firstVersion->content_storage_path),
            Storage::disk('artifacts')->get($restored->content_storage_path),
        );
    }

    public function test_image_detail_and_history_do_not_read_binary_bodies_on_the_app_origin(): void
    {
        Storage::fake('artifacts');
        config(['app.artifact_url' => 'http://artifacts.example.test']);
        $editor = $this->createUser('Binary Read Editor', 'binary-read-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Binary Read Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Image,
            title: 'Binary Read Screenshot',
            description: null,
            content: $this->png(1, 1),
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: $this->png(2, 1),
            source: PageVersionSource::Upload,
            baseVersionUid: $firstVersion->uid,
        ));

        /** @var \Illuminate\Filesystem\FilesystemAdapter&\Mockery\MockInterface $disk */
        $disk = \Mockery::spy(Storage::disk('artifacts'));
        Storage::set('artifacts', $disk);

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('data-image-preview', false);
        $this->actingAs($editor)
            ->get("/pages/{$page->uid}/versions/{$firstVersion->uid}")
            ->assertOk()
            ->assertSee('Historical image');

        $disk->shouldNotHaveReceived('get');
    }

    public function test_lowering_the_upload_cap_does_not_invalidate_stored_previews_or_restores(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_url' => 'http://artifacts.example.test',
            'pages.max_image_bytes' => 1024 * 1024,
            'pages.max_image_pixels' => 100,
        ]);

        $editor = $this->createUser('Retained Screenshot Editor', 'retained-screenshot@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Retained Screenshots');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Image,
            title: 'Retained Screenshot',
            description: null,
            content: $this->png(1, 1),
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: $this->png(2, 1),
            baseVersionUid: $firstVersion->uid,
            source: PageVersionSource::Upload,
        ));

        config(['pages.max_image_bytes' => 1]);

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('data-image-preview', false)
            ->assertDontSee('Stored page content is unavailable.');

        $page->refresh();
        $previewUrl = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $secondVersion);
        config(['app.runtime_role' => 'artifact-host']);
        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($previewUrl)->assertOk();
        config(['app.runtime_role' => 'app']);

        $restored = app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
            pageUid: $page->uid,
            versionUid: $firstVersion->uid,
            expectedCurrentVersionUid: $secondVersion->uid,
        ));

        $this->assertSame($firstVersion->content_hash, $restored->content_hash);
    }

    public function test_busy_normalizer_returns_immediate_retryable_service_unavailable_without_creating_a_page(): void
    {
        Storage::fake('artifacts');
        $editor = $this->createUser('Busy Screenshot Editor', 'busy-screenshot@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Busy Screenshots');
        $lock = Cache::lock('artifactflow:image-normalization:slot:v1', 30);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($editor)
                ->post('/pages', [
                    'workspace_uid' => $workspace->uid,
                    'type' => 'image',
                    'mode' => 'image_upload',
                    'title' => 'Busy Screenshot',
                    'status' => 'draft',
                    'image_file' => $this->imageUpload('busy.png', $this->png(), 'image/png'),
                ])
                ->assertStatus(503)
                ->assertHeader('Retry-After', '17');
        } finally {
            $lock->release();
        }

        $this->assertSame(0, Page::query()->count());
    }

    public function test_parser_outage_returns_retryable_service_unavailable_without_creating_a_page(): void
    {
        Storage::fake('artifacts');
        Http::fake(static function (): never {
            throw new ConnectionException('private transport detail');
        });
        $editor = $this->createUser('Unavailable Screenshot Editor', 'unavailable-screenshot@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Unavailable Screenshots');

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'image',
                'mode' => 'image_upload',
                'title' => 'Unavailable Screenshot',
                'status' => 'draft',
                'image_file' => $this->imageUpload('unavailable.png', $this->png(), 'image/png'),
            ])
            ->assertStatus(503)
            ->assertHeader('Retry-After', '5')
            ->assertSee('Image normalization service is unavailable. Try again shortly.');

        $this->assertSame(0, Page::query()->count());
    }

    public function test_actor_pixel_budget_returns_retryable_too_many_requests(): void
    {
        Storage::fake('artifacts');
        config([
            'pages.max_image_pixels' => 1,
            'image_parser.user_pixel_budget_per_minute' => 1,
            'image_parser.installation_pixel_budget_per_minute' => 100,
        ]);
        $editor = $this->createUser('Budgeted Screenshot Editor', 'budgeted-screenshot@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Budgeted Screenshots');
        $base = [
            'workspace_uid' => $workspace->uid,
            'type' => 'image',
            'mode' => 'image_upload',
            'status' => 'draft',
        ];

        $this->actingAs($editor)
            ->post('/pages', $base + [
                'title' => 'First Budgeted Screenshot',
                'image_file' => $this->imageUpload('first.png', $this->png(), 'image/png'),
            ])
            ->assertRedirect();

        $this->actingAs($editor)
            ->post('/pages', $base + [
                'title' => 'Second Budgeted Screenshot',
                'image_file' => $this->imageUpload('second.png', $this->png(), 'image/png'),
            ])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->assertSame(1, Page::query()->count());
    }

    public function test_busy_normalizer_rejects_an_image_replacement_without_appending_a_version(): void
    {
        Storage::fake('artifacts');
        $editor = $this->createUser('Busy Replacement Editor', 'busy-replacement@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Busy Replacements');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Image,
            title: 'Busy Replacement',
            description: null,
            content: $this->png(),
            source: PageVersionSource::Upload,
        ));
        $lock = Cache::lock('artifactflow:image-normalization:slot:v1', 30);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($editor)
                ->post("/pages/{$page->uid}/versions", [
                    'mode' => 'upload',
                    'base_version_uid' => $page->current_version_uid,
                    'image_file' => $this->imageUpload('replacement.png', $this->png(), 'image/png'),
                ])
                ->assertStatus(503)
                ->assertHeader('Retry-After', '17');
        } finally {
            $lock->release();
        }

        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_missing_image_content_surfaces_the_unavailable_banner_without_issuing_a_preview(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Missing Screenshot Editor', 'missing-screenshot@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Missing Screenshots');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Image,
            title: 'Missing Screenshot',
            description: null,
            content: $this->png(),
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        Storage::disk('artifacts')->delete($version->content_storage_path);

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Stored page content is unavailable.')
            ->assertDontSee('data-image-preview', false);
    }

    public function test_unreadable_image_content_surfaces_the_unavailable_banner_without_issuing_a_preview(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Unreadable Screenshot Editor', 'unreadable-screenshot@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Unreadable Screenshots');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Image,
            title: 'Unreadable Screenshot',
            description: null,
            content: $this->png(),
            source: PageVersionSource::Upload,
        ));

        /** @var \Illuminate\Filesystem\FilesystemAdapter&\Mockery\MockInterface $disk */
        $disk = \Mockery::spy(Storage::disk('artifacts'));
        /** @var \Mockery\Expectation $readStreamExpectation */
        $readStreamExpectation = $disk->shouldReceive('readStream');
        $readStreamExpectation
            ->once()
            ->andThrow(\League\Flysystem\UnableToReadFile::fromLocation('unreadable'));
        Storage::set('artifacts', $disk);

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Stored page content is unavailable.')
            ->assertDontSee('data-image-preview', false);
    }

    public function test_rejected_image_preview_is_not_logged_as_served(): void
    {
        Storage::fake('artifacts');
        config(['app.artifact_url' => 'http://artifacts.example.test']);

        $editor = $this->createUser('Invalid Screenshot Editor', 'invalid-screenshot@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Invalid Screenshots');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Image,
            title: 'Invalid Stored Screenshot',
            description: null,
            content: $this->png(),
            source: PageVersionSource::Upload,
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        Storage::disk('artifacts')->put($version->content_storage_path, $this->png(20000, 1));
        $previewUrl = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);

        $logger = new RecordingLogger();
        Log::swap($logger);
        config(['app.runtime_role' => 'artifact-host']);

        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($previewUrl)->assertNotFound();

        $this->assertTrue(collect($logger->records)->contains(
            static fn (array $record): bool => $record['level'] === 'warning'
                && $record['message'] === 'artifact_preview.rejected'
                && ($record['context']['reason'] ?? null) === 'invalid_image_content',
        ));
        $this->assertFalse(collect($logger->records)->contains(
            static fn (array $record): bool => $record['level'] === 'info'
                && $record['message'] === 'artifact_preview.served',
        ));
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function imageUpload(string $name, string $content, string $detectedMime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'artifactflow-image-');
        $this->assertIsString($path);
        file_put_contents($path, $content);

        return new class($path, $name, $detectedMime) extends UploadedFile {
            public function __construct(
                string $path,
                string $name,
                private readonly string $detectedMime,
            ) {
                parent::__construct($path, $name, null, null, true);
            }

            public function getMimeType(): string
            {
                return $this->detectedMime;
            }
        };
    }

    private function png(
        int $width = 1,
        int $height = 1,
        string $ancillaryText = '',
        string $appendedBytes = '',
    ): string {
        $scanlines = '';

        for ($row = 0; $row < $height; $row++) {
            $scanlines .= "\x00" . str_repeat("\x23\x78\xdd\xff", $width);
        }

        $png = "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0));

        if ($ancillaryText !== '') {
            $png .= $this->pngChunk('tEXt', "Comment\x00" . $ancillaryText);
        }

        $compressed = gzcompress($scanlines);
        $this->assertIsString($compressed);

        return $png
            . $this->pngChunk('IDAT', $compressed)
            . $this->pngChunk('IEND', '')
            . $appendedBytes;
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            . $type
            . $data
            . pack('N', crc32($type . $data));
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertInstanceOf(\GdImage::class, $image);
        $color = imagecolorallocate($image, 35, 120, 221);
        $this->assertIsInt($color);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagejpeg($image, null, 95);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function splitColorJpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertInstanceOf(\GdImage::class, $image);
        $red = imagecolorallocate($image, 240, 20, 20);
        $blue = imagecolorallocate($image, 20, 20, 240);
        $this->assertIsInt($red);
        $this->assertIsInt($blue);
        imagefilledrectangle($image, 0, 0, $width - 1, intdiv($height, 2) - 1, $red);
        imagefilledrectangle($image, 0, intdiv($height, 2), $width - 1, $height - 1, $blue);
        ob_start();
        imagejpeg($image, null, 100);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function withExifOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "II\x2a\x00\x08\x00\x00\x00"
            . "\x01\x00"
            . "\x12\x01\x03\x00\x01\x00\x00\x00"
            . pack('v', $orientation)
            . "\x00\x00"
            . "\x00\x00\x00\x00";
        $exif = "Exif\x00\x00" . $tiff;

        return substr($jpeg, 0, 2)
            . "\xff\xe1"
            . pack('n', strlen($exif) + 2)
            . $exif
            . substr($jpeg, 2);
    }

    private function withAsciiExifOrientation(string $jpeg, string $orientation): string
    {
        $this->assertMatchesRegularExpression('/^[1-8]$/', $orientation);
        $tiff = "II\x2a\x00\x08\x00\x00\x00"
            . "\x01\x00"
            . "\x12\x01\x02\x00\x02\x00\x00\x00"
            . $orientation . "\x00\x00\x00"
            . "\x00\x00\x00\x00";
        $exif = "Exif\x00\x00" . $tiff;

        return substr($jpeg, 0, 2)
            . "\xff\xe1"
            . pack('n', strlen($exif) + 2)
            . $exif
            . substr($jpeg, 2);
    }
}
