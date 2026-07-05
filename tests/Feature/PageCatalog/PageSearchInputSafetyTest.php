<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\PageSearchVectorUpdater;
use App\Domain\PageCatalog\PageType;
use App\Models\PageVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageSearchInputSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_query_with_sql_metacharacters_and_unknown_sort_is_handled_safely(): void
    {
        Storage::fake('artifacts');

        $editor = app(CreateUser::class)->handle('Editor User', 'search-editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Searchable Page',
            description: null,
            content: '# Searchable Page',
        ));

        $this->actingAs($editor)
            ->get('/pages?workspace_uid=' . $workspace->uid . '&q=%27%29%20OR%201%3D1--&sort=__bogus__')
            ->assertOk()
            ->assertSee('Pages');

        $this->actingAs($editor)
            ->get('/pages?workspace_uid=' . $workspace->uid . '&q=' . urlencode(str_repeat('search ', 2000)))
            ->assertOk()
            ->assertSee('Pages');
    }

    public function test_search_vector_refresh_caps_large_extracted_text(): void
    {
        Storage::fake('artifacts');

        $editor = app(CreateUser::class)->handle('Editor User', 'large-search-editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Large Search Vector Page',
            description: null,
            content: '# Large Search Vector Page',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $version->forceFill(['extracted_text' => str_repeat('searchable ', 150000)])->save();

        app(PageSearchVectorUpdater::class)->refreshPage($page->uid);

        $searchVector = $page->refresh()->getAttribute('search_vector');

        $this->assertIsString($searchVector);
        $this->assertNotSame('', $searchVector);
    }

    public function test_search_vector_refresh_caps_large_source_text(): void
    {
        Storage::fake('artifacts');

        $editor = app(CreateUser::class)->handle('Editor User', 'large-source-editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Large Source Vector Page',
            description: null,
            content: '<!doctype html><html><body><h1>Large Source Vector Page</h1></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $version->forceFill(['source_text' => str_repeat('sourceneedle ', 150000)])->save();

        app(PageSearchVectorUpdater::class)->refreshPage($page->uid);

        $searchVector = $page->refresh()->getAttribute('search_vector');

        $this->assertIsString($searchVector);
        $this->assertNotSame('', $searchVector);
    }
}
