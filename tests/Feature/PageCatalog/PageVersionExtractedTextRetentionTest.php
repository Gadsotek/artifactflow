<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\PageSearchVectorUpdater;
use App\Application\PageCatalog\RestorePageVersion;
use App\Application\PageCatalog\RestorePageVersionCommand;
use App\Application\PageCatalog\RevertToPreviousVersion;
use App\Application\PageCatalog\RevertToPreviousVersionCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageVersionExtractedTextRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_previous_version_extracted_text_is_cleared_when_a_new_version_becomes_current(): void
    {
        Storage::fake('artifacts');

        [$editor, $workspace] = $this->createEditorWithWorkspace('retention-clear@example.test');
        $page = $this->createMarkdownPage($editor, $workspace, 'Retention Page', '# First Body firstneedle');
        $firstVersion = $this->currentVersion($page);
        $this->assertStringContainsString('First Body', (string) $firstVersion->extracted_text);

        app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Second Body secondneedle',
            baseVersionUid: $firstVersion->uid,
        ));

        $firstVersion->refresh();
        $currentVersion = $this->currentVersion($page->refresh());
        $this->assertNull($firstVersion->extracted_text);
        $this->assertNotNull($firstVersion->source_text);
        $this->assertStringContainsString('Second Body', (string) $currentVersion->extracted_text);
    }

    public function test_restore_of_an_old_version_rebuilds_search_text_from_stored_content(): void
    {
        Storage::fake('artifacts');

        [$editor, $workspace] = $this->createEditorWithWorkspace('retention-restore@example.test');
        $page = $this->createMarkdownPage($editor, $workspace, 'Restore Retention Page', '# First Body restoreneedle');
        $firstVersion = $this->currentVersion($page);
        app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Second Body othertext',
            baseVersionUid: $firstVersion->uid,
        ));

        $restoredVersion = app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
            pageUid: $page->uid,
            versionUid: $firstVersion->uid,
        ));

        $this->assertStringContainsString('restoreneedle', (string) $restoredVersion->extracted_text);
        $secondVersion = PageVersion::query()
            ->where('page_uid', $page->uid)
            ->where('version_number', 2)
            ->sole();
        $this->assertNull($secondVersion->extracted_text);

        $this->actingAs($editor)
            ->get('/pages?workspace_uid=all&q=restoreneedle')
            ->assertOk()
            ->assertSee('Restore Retention Page');
    }

    public function test_revert_to_previous_version_rebuilds_search_text_from_stored_content(): void
    {
        Storage::fake('artifacts');

        [$editor, $workspace] = $this->createEditorWithWorkspace('retention-revert@example.test');
        $page = $this->createMarkdownPage($editor, $workspace, 'Revert Retention Page', '# First Body revertneedle');
        $firstVersion = $this->currentVersion($page);
        app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Second Body replacement',
            baseVersionUid: $firstVersion->uid,
        ));
        $page->refresh();
        $this->assertNotNull($page->current_version_uid);

        $result = app(RevertToPreviousVersion::class)->handle($editor, new RevertToPreviousVersionCommand(
            pageUid: $page->uid,
            baseVersionUid: $page->current_version_uid,
        ));

        $this->assertStringContainsString('revertneedle', (string) $result->restoredVersion->extracted_text);

        $this->actingAs($editor)
            ->get('/pages?workspace_uid=all&q=revertneedle')
            ->assertOk()
            ->assertSee('Revert Retention Page');
    }

    public function test_extracted_text_is_capped_at_the_search_character_limit(): void
    {
        [$editor, $workspace] = $this->createEditorWithWorkspace('cap-retention@example.test');

        $cap = PageSearchVectorUpdater::MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS;
        $page = $this->createMarkdownPage(
            $editor,
            $workspace,
            'Oversized Retention Page',
            '# Body ' . str_repeat('word ', (int) ($cap / 4)),
        );

        $extracted = (string) $this->currentVersion($page)->extracted_text;

        $this->assertSame($cap, mb_strlen($extracted));
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createEditorWithWorkspace(string $email): array
    {
        $editor = app(CreateUser::class)->handle('Retention User', $email, 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Retention Team ' . $email);

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
        $this->assertNotNull($page->current_version_uid);

        return PageVersion::query()->whereKey($page->current_version_uid)->sole();
    }
}
