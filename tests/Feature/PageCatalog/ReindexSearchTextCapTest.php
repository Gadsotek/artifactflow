<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\PageSearchVectorUpdater;
use App\Application\PageCatalog\ReindexSearchText;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ReindexSearchTextCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_reindex_keeps_extracted_text_capped_and_does_not_phantom_report_an_unchanged_page(): void
    {
        Storage::fake('artifacts');

        [$editor, $workspace] = $this->createEditorWithWorkspace('reindex-cap@example.test');

        $cap = PageSearchVectorUpdater::MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS;
        $page = $this->createMarkdownPage(
            $editor,
            $workspace,
            'Oversized Reindex Page',
            '# Body ' . str_repeat('word ', (int) ($cap / 4)),
        );

        // The page never drifted, so a dry run must report zero changed versions. Without the
        // cap, versionNeedsUpdate compares the stored (capped) value against a fresh uncapped
        // extraction and reports a phantom change.
        $dryRun = app(ReindexSearchText::class)->handle(pageUid: $page->uid, dryRun: true);
        $this->assertSame(0, $dryRun->versionsChanged);

        // A real reindex must keep extracted_text at the cap, never resurrect the uncapped text.
        app(ReindexSearchText::class)->handle(pageUid: $page->uid);
        $this->assertSame($cap, mb_strlen((string) $this->currentVersion($page)->extracted_text));
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createEditorWithWorkspace(string $email): array
    {
        $editor = app(CreateUser::class)->handle('Reindex User', $email, 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Reindex Team ' . $email);

        return [$editor, $workspace];
    }

    private function createMarkdownPage(User $actor, Workspace $workspace, string $title, string $content): Page
    {
        return app(CreatePage::class)->handle($actor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: $title,
            description: null,
            content: $content,
        ));
    }

    private function currentVersion(Page $page): PageVersion
    {
        return PageVersion::query()->whereKey($page->refresh()->current_version_uid)->sole();
    }
}
