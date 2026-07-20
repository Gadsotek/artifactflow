<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\ArchivePage;
use App\Application\PageCatalog\ArchivePageCommand;
use App\Application\PageCatalog\ArtifactPreviewUrl;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ArtifactPreviewRefreshHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_viewer_can_mint_a_fresh_short_lived_url_for_the_current_artifact(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_preview_url_ttl_seconds' => 1,
            'app.artifact_url' => 'http://artifacts.example.test',
        ]);

        $editor = $this->createUser('Preview Editor', 'preview-refresh@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Prototype Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Refreshing Prototype',
            description: null,
            content: '<!doctype html><html><body><h1>Refreshing Prototype</h1></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $expiredUrl = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);

        $this->travel(2)->seconds();

        $response = $this->actingAs($editor)
            ->getJson("/pages/{$page->uid}/artifact-preview-url")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['url']);

        $freshUrl = $response->json('url');
        $this->assertIsString($freshUrl);
        $this->assertNotSame($expiredUrl, $freshUrl);
        $this->assertTrue($this->signatureIsValid($page, $version, $freshUrl));
    }

    public function test_preview_url_refresh_hides_pages_from_unauthorized_users(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Preview Owner', 'preview-owner@example.test');
        $outsider = $this->createUser('Preview Outsider', 'preview-outsider@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Private Prototype Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Private Refreshing Prototype',
            description: null,
            content: '<!doctype html><html><body><h1>Private</h1></body></html>',
        ));

        $this->actingAs($outsider)
            ->getJson("/pages/{$page->uid}/artifact-preview-url")
            ->assertNotFound();
    }

    public function test_archiving_invalidates_existing_preview_urls_and_requires_authorized_renewal(): void
    {
        Storage::fake('artifacts');
        config(['app.artifact_url' => 'http://artifacts.example.test']);

        $editor = $this->createUser('Preview Owner', 'archive-preview-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Archived Prototype Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Archived Prototype',
            description: null,
            content: '<!doctype html><html><body><h1>Archived Prototype</h1></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $issuedUrl = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);
        $previousRevision = $page->preview_access_revision;

        app(ArchivePage::class)->handle(
            $editor,
            new ArchivePageCommand($page->uid, confirmed: true),
        );

        $this->assertSame($previousRevision + 1, $page->refresh()->preview_access_revision);

        $response = $this->actingAs($editor)
            ->getJson("/pages/{$page->uid}/artifact-preview-url")
            ->assertOk()
            ->assertJsonStructure(['url']);

        $renewedUrl = $response->json('url');
        $this->assertIsString($renewedUrl);
        $this->assertNotSame($issuedUrl, $renewedUrl);
        $this->assertTrue($this->signatureIsValid($page, $version, $renewedUrl));

        config(['app.runtime_role' => 'artifact-host']);

        $this->get($issuedUrl)
            ->assertNotFound();
    }

    private function signatureIsValid(Page $page, PageVersion $version, string $url): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $parameters);

        return app(ArtifactPreviewUrl::class)->hasValidSignature(
            $page,
            $version->uid,
            is_string($parameters['expires'] ?? null) ? $parameters['expires'] : null,
            is_string($parameters['signature'] ?? null) ? $parameters['signature'] : null,
        );
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }
}
