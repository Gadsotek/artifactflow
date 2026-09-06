<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\PageSearch;
use App\Application\PageCatalog\PageSearchFilters;
use App\Application\PageCatalog\PageSearchSort;
use App\Domain\PageCatalog\PageType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageSearchMemoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_memory_uses_capped_projections_instead_of_full_uploaded_content(): void
    {
        Storage::fake('artifacts');
        $actor = app(CreateUser::class)->handle('Search Owner', 'search-memory@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($actor, 'Bounded Search');
        foreach (range(1, 3) as $number) {
            app(CreatePage::class)->handle($actor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Markdown,
                title: 'Needle large page ' . $number,
                description: null,
                content: 'needle ' . str_repeat('ordinary prose ', 75_000),
            ));
        }

        foreach ([false, true] as $includeSnippets) {
            $before = memory_get_usage();
            $results = app(PageSearch::class)->search(
                $actor,
                new PageSearchFilters(
                    query: 'needle',
                    workspaceUid: $workspace->uid,
                    type: null,
                    statuses: PageSearchFilters::activeStatuses(),
                    categoryUids: [],
                    tagUids: [],
                    ownerUserUid: null,
                    sort: PageSearchSort::Relevance,
                ),
                includeSnippets: $includeSnippets,
            );
            $retainedBytes = memory_get_usage() - $before;
            $this->assertCount(3, $results);
            $this->assertLessThan(2 * 1024 * 1024, $retainedBytes, 'Search retained full multi-megabyte version bodies.');
            foreach ($results as $result) {
                $this->assertSame(
                    $includeSnippets ? substr('needle ' . str_repeat('ordinary prose ', 20), 0, 177) . '...' : null,
                    $result->snippet,
                );
            }
            unset($results);
        }
    }
}
