<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\PageCatalog\PageType;
use App\Models\PageVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageStorageQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_version_append_is_rejected_beyond_workspace_storage_quota(): void
    {
        Storage::fake('artifacts');
        config([
            'pages.max_markdown_bytes' => 100,
            'pages.max_workspace_storage_bytes' => 17,
        ]);

        $editor = app(CreateUser::class)->handle('Editor User', 'quota-editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Quota Page',
            description: null,
            content: '123456789',
        ));
        $existingVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions", [
                'content' => 'abcdefghi',
                'base_version_uid' => $existingVersion->uid,
            ])
            ->assertSessionHasErrors('content');

        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
        Storage::disk('artifacts')->assertExists($existingVersion->content_storage_path);
    }

    public function test_version_append_is_rejected_beyond_per_page_storage_quota(): void
    {
        Storage::fake('artifacts');
        config([
            'pages.max_markdown_bytes' => 100,
            'pages.max_page_storage_bytes' => 17,
            'pages.max_workspace_storage_bytes' => 100,
        ]);

        $editor = app(CreateUser::class)->handle('Editor User', 'page-quota-editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Page Quota',
            description: null,
            content: '123456789',
        ));
        $existingVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions", [
                'content' => 'abcdefghi',
                'base_version_uid' => $existingVersion->uid,
            ])
            ->assertSessionHasErrors('content');

        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
        Storage::disk('artifacts')->assertExists($existingVersion->content_storage_path);
    }

    public function test_version_append_beyond_version_count_prunes_oldest_instead_of_rejecting(): void
    {
        // The per-page version count is a retention cap, not a hard wall: an
        // append at the cap succeeds and prunes the oldest version rather than
        // blocking the edit (see PageVersionPruningTest for the full matrix).
        Storage::fake('artifacts');
        config([
            'pages.max_markdown_bytes' => 100,
            'pages.max_page_versions' => 1,
            'pages.max_workspace_storage_bytes' => 100,
        ]);

        $editor = app(CreateUser::class)->handle('Editor User', 'page-version-quota-editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Page Version Quota',
            description: null,
            content: 'first',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions", [
                'content' => 'second',
                'base_version_uid' => $page->current_version_uid,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertDatabaseMissing('page_versions', ['uid' => $firstVersion->uid]);
        Storage::disk('artifacts')->assertMissing($firstVersion->content_storage_path);
    }
}
