<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\ArtifactPreviewUrl;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\PageCatalog\PageType;
use App\Models\PageVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_creation_is_rate_limited_per_authenticated_user(): void
    {
        Storage::fake('artifacts');
        config(['rate_limits.page_writes_per_minute' => 2]);

        $editor = app(CreateUser::class)->handle('Editor User', 'editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->actingAs($editor)
                ->post('/pages', [
                    'workspace_uid' => $workspace->uid,
                    'type' => 'markdown',
                    'title' => 'Rate Limited Page ' . $attempt,
                    'status' => 'draft',
                    'content' => '# Content ' . $attempt,
                ])
                ->assertRedirect();
        }

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'markdown',
                'title' => 'Rate Limited Page 3',
                'status' => 'draft',
                'content' => '# Content 3',
            ])
            ->assertTooManyRequests();
    }

    public function test_sensitive_page_mutation_routes_use_the_page_write_limiter(): void
    {
        foreach ([
            'pages.metadata.update',
            'pages.workspace.update',
            'pages.access.store',
            'pages.access-mode.update',
            'pages.access.destroy',
            'pages.archive',
            'pages.unarchive',
            'pages.mark-approved',
            'pages.return-to-draft',
            'pages.deprecate',
            'pages.restore-to-draft',
            'pages.destroy',
            'pages.versions.store',
            'pages.versions.restore',
            'workspace-memberships.update',
            'workspace-memberships.destroy',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, sprintf('Expected route [%s] to exist.', $routeName));
            $this->assertContains(
                'throttle:artifactflow-page-writes',
                $route->gatherMiddleware(),
                sprintf('Expected route [%s] to use the page write limiter.', $routeName),
            );
        }
    }

    public function test_page_presence_uses_a_dedicated_limiter_so_heartbeats_do_not_starve_saves(): void
    {
        $route = Route::getRoutes()->getByName('pages.presence.update');

        $this->assertNotNull($route);
        $this->assertContains('throttle:artifactflow-page-presence', $route->gatherMiddleware());
        $this->assertNotContains('throttle:artifactflow-page-writes', $route->gatherMiddleware());
    }

    public function test_markdown_preview_is_rate_limited(): void
    {
        Storage::fake('artifacts');
        config(['rate_limits.markdown_previews_per_minute' => 1]);

        $editor = app(CreateUser::class)->handle('Editor User', 'preview-editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Preview Rate Limit',
            description: null,
            content: '# Stored',
        ));

        $this->actingAs($editor)
            ->postJson("/pages/{$page->uid}/markdown-preview", ['content' => '# One'])
            ->assertOk();

        $this->actingAs($editor)
            ->postJson("/pages/{$page->uid}/markdown-preview", ['content' => '# Two'])
            ->assertTooManyRequests();
    }

    public function test_artifact_preview_route_is_rate_limited(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_url' => 'http://localhost',
            'app.runtime_role' => 'artifact-host',
            'rate_limits.artifact_previews_per_minute' => 1,
        ]);

        $editor = app(CreateUser::class)->handle('Editor User', 'artifact-preview-editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Preview Rate Limit',
            description: null,
            content: '<!doctype html><html><body><h1>Limited</h1></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);

        $this->get($url)->assertOk();
        $this->get($url)->assertTooManyRequests();
    }

    public function test_artifact_preview_route_has_an_ip_wide_limit_across_distinct_paths(): void
    {
        config([
            'app.artifact_url' => 'http://localhost',
            'app.runtime_role' => 'artifact-host',
            'rate_limits.artifact_previews_per_minute' => 2,
        ]);

        $paths = [
            '/artifact-previews/01K00000000000000000000000/versions/01K00000000000000000000010?expires=1&signature=x',
            '/artifact-previews/01K00000000000000000000001/versions/01K00000000000000000000011?expires=1&signature=x',
            '/artifact-previews/01K00000000000000000000002/versions/01K00000000000000000000012?expires=1&signature=x',
        ];

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
            ->get($paths[0])
            ->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
            ->get($paths[1])
            ->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
            ->get($paths[2])
            ->assertTooManyRequests();
    }
}
