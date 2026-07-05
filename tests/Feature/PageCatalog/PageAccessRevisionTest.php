<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\PageCatalog\PageAccessRevision;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PageAccessRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_access_revision_bumps_are_atomic_for_stale_models(): void
    {
        $page = Page::factory()->create();
        $firstReader = Page::query()->findOrFail($page->uid);
        $secondReader = Page::query()->findOrFail($page->uid);

        $revisions = app(PageAccessRevision::class);
        $revisions->bump($firstReader);
        $revisions->bump($secondReader);

        $this->assertSame(2, Page::query()->findOrFail($page->uid)->preview_access_revision);
        $this->assertSame(0, $firstReader->preview_access_revision);
        $this->assertSame(0, $secondReader->preview_access_revision);
    }

    public function test_workspace_revision_bump_only_updates_pages_in_that_workspace(): void
    {
        $page = Page::factory()->create();
        $sameWorkspacePage = Page::factory()->create(['workspace_uid' => $page->workspace_uid]);
        $otherWorkspacePage = Page::factory()->create();

        $updated = app(PageAccessRevision::class)->bumpWorkspace($page->workspace_uid);

        $this->assertSame(2, $updated);
        $this->assertSame(1, $page->refresh()->preview_access_revision);
        $this->assertSame(1, $sameWorkspacePage->refresh()->preview_access_revision);
        $this->assertSame(0, $otherWorkspacePage->refresh()->preview_access_revision);
    }
}
