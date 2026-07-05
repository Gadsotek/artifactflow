<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class MarkdownPreviewHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_can_render_a_safe_markdown_preview_without_persisting_content(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Preview Page',
            description: null,
            content: '# Stored Content',
        ));
        $versionCount = PageVersion::query()->where('page_uid', $page->uid)->count();
        $eventCount = DomainEvent::query()->count();
        $auditCount = AuditEntry::query()->count();

        $response = $this->actingAs($editor)
            ->postJson("/pages/{$page->uid}/markdown-preview", [
                'content' => <<<'MARKDOWN'
                    # Safe Preview

                    <script>window.evil = true</script>
                    [unsafe](javascript:alert(1))

                    ```mermaid
                    graph TD
                      App --> DB
                    ```
                    MARKDOWN,
            ])
            ->assertOk()
            ->assertJsonStructure(['html']);

        $html = $response->json('html');

        $this->assertIsString($html);
        $this->assertStringContainsString('<h1>Safe Preview</h1>', $html);
        $this->assertStringContainsString('data-mermaid-diagram', $html);
        $this->assertStringContainsString('data-mermaid-canvas', $html);
        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('Diagram source', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertSame($versionCount, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame($eventCount, DomainEvent::query()->count());
        $this->assertSame($auditCount, AuditEntry::query()->count());
    }

    public function test_reader_cannot_render_editor_preview(): void
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
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Protected Preview',
            description: null,
            content: '# Protected',
        ));

        $this->actingAs($reader)
            ->postJson("/pages/{$page->uid}/markdown-preview", [
                'content' => '# Unauthorized',
            ])
            ->assertForbidden();
    }

    public function test_preview_rejects_html_artifact_pages_blank_content_and_oversized_markdown(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $htmlPage = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'HTML Page',
            description: null,
            content: '<!doctype html><html><body>HTML</body></html>',
        ));
        $markdownPage = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Markdown Page',
            description: null,
            content: '# Markdown',
        ));

        $this->actingAs($editor)
            ->postJson("/pages/{$htmlPage->uid}/markdown-preview", [
                'content' => '# Wrong page type',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');

        $this->actingAs($editor)
            ->postJson("/pages/{$markdownPage->uid}/markdown-preview", [
                'content' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');

        config(['pages.max_markdown_bytes' => 5]);

        $this->actingAs($editor)
            ->postJson("/pages/{$markdownPage->uid}/markdown-preview", [
                'content' => '123456',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');
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
