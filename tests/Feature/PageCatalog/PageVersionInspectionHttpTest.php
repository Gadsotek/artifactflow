<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\ArtifactPreviewUrl;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageVersionInspectionHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_inspect_and_compare_a_historical_markdown_version(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Version Owner', 'version-owner@example.test');
        $reader = $this->createUser('Version Reader', 'version-reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Version Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Version Inspector',
            description: null,
            content: "# Version One\n\nShared line\n\nOld ending",
        ));
        $firstVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($owner, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: "# Version Two\n\nShared line\n\nNew ending",
            baseVersionUid: $firstVersion->uid,
        ));

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee("/pages/{$page->uid}/versions/{$firstVersion->uid}", false)
            ->assertSee('Inspect');

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}/versions/{$firstVersion->uid}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Historical version 1')
            ->assertSee('Current version is 2')
            ->assertSee('<h1>Version One</h1>', false)
            ->assertDontSee('<h1>Version Two</h1>', false)
            ->assertSee('data-version-diff', false)
            ->assertSee('data-diff-kind="removed"', false)
            ->assertSee('data-diff-kind="added"', false)
            ->assertSee('Old ending')
            ->assertSee('New ending')
            ->assertSee("/pages/{$page->uid}/versions/{$firstVersion->uid}/restore", false)
            ->assertSee('value="' . $secondVersion->uid . '"', false);

        $this->actingAs($reader)
            ->get("/pages/{$page->uid}/versions/{$firstVersion->uid}")
            ->assertOk()
            ->assertSee('<h1>Version One</h1>', false)
            ->assertDontSee("/pages/{$page->uid}/versions/{$firstVersion->uid}/restore", false);
    }

    public function test_historical_html_preview_keeps_the_isolated_signed_sandbox_boundary(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_url' => 'http://artifacts.example.test',
            'app.runtime_role' => 'app',
        ]);

        $owner = $this->createUser('Artifact Owner', 'artifact-history@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Artifact History Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Historical Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Historical Cage</h1><script>window.historyMarker = true;</script></body></html>',
        ));
        $firstVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        app(UpdatePageContent::class)->handle($owner, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '<!doctype html><html><body><h1>Current Artifact</h1></body></html>',
            baseVersionUid: $firstVersion->uid,
        ));
        $page->refresh();

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}/versions/{$firstVersion->uid}")
            ->assertOk()
            ->assertSee('sandbox="allow-scripts"', false)
            ->assertSee('allow=""', false)
            ->assertDontSee('allow-same-origin', false)
            ->assertSee("/pages/{$page->uid}/versions/{$firstVersion->uid}/artifact-preview-url", false)
            ->assertSee("/artifact-previews/{$page->uid}/versions/{$firstVersion->uid}", false)
            ->assertSee('purpose=history', false)
            ->assertDontSee('<script>', false)
            ->assertSee('&lt;script&gt;window.historyMarker', false);

        $refreshedUrl = $this->actingAs($owner)
            ->getJson("/pages/{$page->uid}/versions/{$firstVersion->uid}/artifact-preview-url")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('url', fn (mixed $url): bool => is_string($url)
                && str_contains($url, "/artifact-previews/{$page->uid}/versions/{$firstVersion->uid}")
                && str_contains($url, 'purpose=history'))
            ->json('url');
        $this->assertIsString($refreshedUrl);

        $historyUrl = app(ArtifactPreviewUrl::class)->temporaryHistoryUrl($page, $firstVersion);
        $currentOnlyUrl = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $firstVersion);

        config(['app.runtime_role' => 'artifact-host']);

        $this->withHeader('Sec-Fetch-Dest', 'iframe')
            ->get($historyUrl)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox allow-scripts; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; font-src data:; media-src data: blob:; connect-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-src 'none'; child-src 'none'; worker-src 'none'; webrtc 'block'; frame-ancestors http://localhost:18080")
            ->assertSee('Historical Cage')
            ->assertSee('window.historyMarker', false);

        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($currentOnlyUrl)->assertNotFound();

        $tamperedPurposeUrl = str_replace('purpose=history', 'purpose=current', $historyUrl);
        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($tamperedPurposeUrl)->assertNotFound();

        $page->forceFill(['preview_access_revision' => $page->preview_access_revision + 1])->save();
        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get($historyUrl)->assertNotFound();
    }

    public function test_version_inspection_hides_foreign_versions_and_pages_from_unauthorized_users(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Inspection Owner', 'inspection-owner@example.test');
        $outsider = $this->createUser('Inspection Outsider', 'inspection-outsider@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Inspection Team');
        $pageA = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Inspection A',
            description: null,
            content: '# Inspection A',
        ));
        $pageB = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Inspection B',
            description: null,
            content: '<!doctype html><html><body>Inspection B</body></html>',
        ));
        $versionA = PageVersion::query()->whereKey($pageA->current_version_uid)->sole();
        $versionB = PageVersion::query()->whereKey($pageB->current_version_uid)->sole();

        $this->actingAs($owner)
            ->get("/pages/{$pageA->uid}/versions/{$versionB->uid}")
            ->assertNotFound();
        $this->actingAs($owner)
            ->getJson("/pages/{$pageA->uid}/versions/{$versionB->uid}/artifact-preview-url")
            ->assertNotFound();
        $this->actingAs($outsider)
            ->get("/pages/{$pageA->uid}/versions/{$versionA->uid}")
            ->assertNotFound();
        $this->actingAs($outsider)
            ->getJson("/pages/{$pageB->uid}/versions/{$versionB->uid}/artifact-preview-url")
            ->assertNotFound();
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
