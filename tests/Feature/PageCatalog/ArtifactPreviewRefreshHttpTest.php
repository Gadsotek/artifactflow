<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
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
