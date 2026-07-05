<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageSharingLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_viewer_can_copy_the_stable_authenticated_page_url_without_preview_credentials(): void
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
            type: PageType::HtmlArtifact,
            title: 'Shareable Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Shareable</h1></body></html>',
        ));
        $pageUrl = route('pages.show', $page);

        $response = $this->actingAs($reader)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Copy page link')
            ->assertSee('data-copy-page-link', false)
            ->assertSee('data-copy-page-link-url="' . $pageUrl . '"', false)
            ->assertSee('aria-live="polite"', false);

        $content = $response->getContent();

        $this->assertIsString($content);
        $this->assertStringNotContainsString('data-copy-page-link-url="http://localhost/artifact-previews/', $content);
        $this->assertStringNotContainsString('data-copy-page-link-url="http://artifacts.', $content);
        $this->assertStringNotContainsString('data-copy-page-link-url="' . $pageUrl . '?', $content);
    }

    public function test_unauthorized_user_cannot_receive_the_page_copy_link(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $outsider = $this->createUser('Outsider User', 'outsider@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Private Runbook',
            description: null,
            content: '# Private',
        ));

        $this->actingAs($outsider)
            ->get("/pages/{$page->uid}")
            ->assertNotFound()
            ->assertDontSee('Copy page link')
            ->assertDontSee('data-copy-page-link', false);
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
