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
use App\Models\PageVersionIngest;
use App\Models\ProducerAssertion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_search_vector_refresh_bounds_retained_historical_provenance(): void
    {
        Storage::fake('artifacts');

        $editor = app(CreateUser::class)->handle(
            'Provenance Search Editor',
            'provenance-search-safety@example.test',
            'correct horse battery staple',
        );
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Provenance Search Safety');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Bounded Provenance Search',
            description: null,
            content: '# Bounded provenance search',
        ));

        $ingests = [];
        $assertions = [];
        $now = now();

        for ($version = 2; $version <= 481; ++$version) {
            $ingestUid = (string) Str::ulid();
            $versionUid = (string) Str::ulid();
            $ingests[] = [
                'uid' => $ingestUid,
                'page_uid' => $page->uid,
                'page_version_uid' => $versionUid,
                'content_origin_version_uid' => $versionUid,
                'version_number' => $version,
                'content_hash' => hash('sha256', 'version-' . $version),
                'operation' => 'update',
                'ingest_method' => 'mcp',
                'actor_user_uid' => $editor->uid,
                'mcp_access_token_uid' => null,
                'mcp_transport_session_id' => null,
                'mcp_client_reported_name' => null,
                'mcp_client_reported_version' => null,
                'provenance_supplied_at_ingest' => true,
                'derived_from_version_uid' => null,
                'content_equivalent_to_version_uid' => null,
                'recorded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            for ($producer = 1; $producer <= 8; ++$producer) {
                $assertions[] = [
                    'uid' => (string) Str::ulid(),
                    'page_version_ingest_uid' => $ingestUid,
                    'producer_kind' => 'ai',
                    'producer_name' => null,
                    'producer_version' => null,
                    'provider_key' => str_pad("p{$version}x{$producer}", 80, 'x'),
                    'model_id' => "model-{$version}-{$producer}",
                    'model_label' => str_pad("m{$version}-{$producer}", 191, 'y'),
                    'model_version' => null,
                    'generated_at' => null,
                    'evidence_type' => 'self_reported',
                    'asserted_by_user_uid' => $editor->uid,
                    'supersedes_assertion_uid' => null,
                    'asserted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table((new PageVersionIngest())->getTable())->insert($ingests);

        foreach (array_chunk($assertions, 500) as $assertionChunk) {
            DB::table((new ProducerAssertion())->getTable())->insert($assertionChunk);
        }

        app(PageSearchVectorUpdater::class)->refreshPage($page->uid);

        $searchVector = $page->refresh()->getAttribute('search_vector');

        $this->assertIsString($searchVector);
        $this->assertNotSame('', $searchVector);
    }
}
