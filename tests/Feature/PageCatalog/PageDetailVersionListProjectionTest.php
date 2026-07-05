<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\PageDetailViewData;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\PageCatalog\PageType;
use App\Models\PageVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageDetailVersionListProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_version_history_list_does_not_hydrate_stored_content_columns(): void
    {
        Storage::fake('artifacts');

        $editor = app(CreateUser::class)->handle(
            'Projection User',
            'projection@example.test',
            'correct horse battery staple',
        );
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Projection Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Projection Page',
            description: null,
            content: '# First body needleone',
        ));
        $firstVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Second body needletwo',
            baseVersionUid: $firstVersion->uid,
        ));

        $versions = app(PageDetailViewData::class)->forPage($editor, $page->refresh())['versions'];

        $this->assertCount(2, $versions);

        foreach ($versions as $historyVersion) {
            $attributes = $historyVersion->getAttributes();

            // The version-history dialog never renders source_text/extracted_text; loading
            // them hydrates up to MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS per version on every
            // page-show request, which is the memory-bomb this projection prevents.
            $this->assertArrayNotHasKey('source_text', $attributes);
            $this->assertArrayNotHasKey('extracted_text', $attributes);

            // The columns the dialog does render must stay available.
            $this->assertArrayHasKey('version_number', $attributes);
            $this->assertArrayHasKey('content_hash', $attributes);
            $this->assertArrayHasKey('byte_size', $attributes);
            $this->assertArrayHasKey('scan_status', $attributes);
            $this->assertSame($editor->name, $historyVersion->creator->name);
        }
    }
}
