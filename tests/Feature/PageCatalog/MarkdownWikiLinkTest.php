<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageType;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class MarkdownWikiLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_same_workspace_wiki_links_render_as_internal_page_links(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $target = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Runtime Notes',
            description: null,
            content: '# Runtime Notes',
        ));
        $source = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'System Map',
            description: null,
            content: <<<'MARKDOWN'
                See [[Runtime Notes]] for details.

                `[[Runtime Notes]]`

                ```text
                [[Runtime Notes]]
                ```
                MARKDOWN,
        ));

        $response = $this->actingAs($reader)
            ->get("/pages/{$source->uid}")
            ->assertOk()
            ->assertSee('href="/pages/' . $target->uid . '"', false)
            ->assertSee('>Runtime Notes</a>', false)
            ->assertSee('<code>[[Runtime Notes]]</code>', false);
        $content = $response->getContent();

        $this->assertIsString($content);
        $this->assertSame(1, substr_count($content, 'href="/pages/' . $target->uid . '"'));
    }

    public function test_wiki_links_do_not_disclose_restricted_or_cross_workspace_pages(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($editor, 'Other Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $editor->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);
        $restrictedTarget = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Restricted Notes',
            description: null,
            content: '# Restricted Notes',
        ));
        $restrictedTarget->forceFill(['access_mode' => PageAccessMode::Restricted])->save();
        $crossWorkspaceTarget = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $otherWorkspace->uid,
            type: PageType::Markdown,
            title: 'Other Workspace Notes',
            description: null,
            content: '# Other Workspace Notes',
        ));
        $source = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Visible Source',
            description: null,
            content: 'See [[Restricted Notes]] and [[Other Workspace Notes]].',
        ));

        $this->actingAs($editor)
            ->get("/pages/{$source->uid}")
            ->assertOk()
            ->assertSee('[[Restricted Notes]]')
            ->assertSee('[[Other Workspace Notes]]')
            ->assertDontSee($restrictedTarget->uid)
            ->assertDontSee($crossWorkspaceTarget->uid);
    }

    public function test_authorized_live_preview_resolves_wiki_links_without_persisting_content(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $target = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Preview Target',
            description: null,
            content: '# Preview Target',
        ));
        $source = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Preview Source',
            description: null,
            content: '# Preview Source',
        ));

        $response = $this->actingAs($owner)
            ->postJson("/pages/{$source->uid}/markdown-preview", [
                'content' => 'Open [[Preview Target]].',
            ])
            ->assertOk();
        $html = $response->json('html');

        $this->assertIsString($html);
        $this->assertStringContainsString('href="/pages/' . $target->uid . '"', $html);
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
