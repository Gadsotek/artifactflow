<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\SeedDemoContent;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DemoContentSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_hello_world_markdown_and_html_artifact_pages_for_a_user(): void
    {
        Storage::fake('artifacts');

        $user = app(CreateUser::class)->handle(
            name: 'Demo User',
            email: 'demo@example.test',
            password: 'correct horse battery staple',
        );

        $pages = app(SeedDemoContent::class)->handle($user);

        $this->assertCount(2, $pages);

        $markdownPage = Page::query()->where('title', 'Hello World Markdown')->sole();
        $htmlPage = Page::query()->where('title', 'Hello World HTML Artifact')->sole();

        $this->assertSame(PageType::Markdown, $markdownPage->type);
        $this->assertSame(PageType::HtmlArtifact, $htmlPage->type);

        $markdownVersion = PageVersion::query()->where('page_uid', $markdownPage->uid)->sole();
        $htmlVersion = PageVersion::query()->where('page_uid', $htmlPage->uid)->sole();

        $this->assertStringContainsString('graph TD', (string) $markdownVersion->extracted_text);
        $this->assertStringContainsString('Hello HTML Artifact', (string) $htmlVersion->extracted_text);
        $this->assertSame('warnings', $htmlVersion->scan_status->value);

        Storage::disk('artifacts')->assertExists($markdownVersion->content_storage_path);
        Storage::disk('artifacts')->assertExists($htmlVersion->content_storage_path);

        $this->assertSame(2, DomainEvent::query()->where('event_type', 'page.created')->count());
        $this->assertSame(2, AuditEntry::query()->where('action', 'page.created')->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.security_warnings.recorded')->count());
    }

    public function test_demo_content_seed_is_idempotent_for_the_same_user(): void
    {
        Storage::fake('artifacts');

        $user = app(CreateUser::class)->handle(
            name: 'Demo User',
            email: 'demo@example.test',
            password: 'correct horse battery staple',
        );

        app(SeedDemoContent::class)->handle($user);
        app(SeedDemoContent::class)->handle($user);

        $this->assertSame(1, Page::query()->where('title', 'Hello World Markdown')->count());
        $this->assertSame(1, Page::query()->where('title', 'Hello World HTML Artifact')->count());
        $this->assertSame(2, PageVersion::query()->count());
        $this->assertSame(2, DomainEvent::query()->where('event_type', 'page.created')->count());
    }

    public function test_demo_content_command_seeds_existing_user_without_printing_content(): void
    {
        Storage::fake('artifacts');

        app(CreateUser::class)->handle(
            name: 'Demo User',
            email: 'demo@example.test',
            password: 'correct horse battery staple',
        );

        $exitCode = Artisan::call('artifactflow:seed-demo-content', [
            '--email' => 'demo@example.test',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Demo content ready for demo@example.test', $output);
        $this->assertStringNotContainsString('<script>', $output);
        $this->assertSame(2, Page::query()->count());
    }

    public function test_fresh_personal_dashboard_offers_and_seeds_demo_content_through_the_authenticated_ui(): void
    {
        Storage::fake('artifacts');

        $user = app(CreateUser::class)->handle(
            name: 'Demo User',
            email: 'demo@example.test',
            password: 'correct horse battery staple',
        );
        $personalWorkspace = Workspace::query()->where('personal_owner_uid', $user->uid)->sole();

        $this->actingAs($user)
            ->withSession(['current_workspace_uid' => $personalWorkspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Add Hello World examples')
            ->assertSee('Mermaid')
            ->assertSee('isolated HTML artifact');

        $this->actingAs($user)
            ->post('/demo-content')
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'Hello World examples are ready in your personal workspace.')
            ->assertSessionHas('current_workspace_uid', $personalWorkspace->uid);

        $this->assertSame(2, Page::query()->where('workspace_uid', $personalWorkspace->uid)->count());
        $this->assertSame(2, DomainEvent::query()->where('event_type', 'page.created')->count());
        $this->assertSame(2, AuditEntry::query()->where('action', 'page.created')->count());

        $this->actingAs($user)
            ->post('/demo-content')
            ->assertRedirect('/dashboard');

        $this->assertSame(2, Page::query()->where('workspace_uid', $personalWorkspace->uid)->count());
        $this->assertSame(2, DomainEvent::query()->where('event_type', 'page.created')->count());
    }

    public function test_demo_content_ui_requires_authentication_and_is_hidden_outside_an_empty_personal_workspace(): void
    {
        Storage::fake('artifacts');

        $this->post('/demo-content')
            ->assertRedirect('/login');

        $user = app(CreateUser::class)->handle(
            name: 'Demo User',
            email: 'demo@example.test',
            password: 'correct horse battery staple',
        );
        $sharedWorkspace = app(CreateSharedWorkspace::class)
            ->handle($user, 'Empty Shared Workspace');

        $this->actingAs($user)
            ->withSession(['current_workspace_uid' => $sharedWorkspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('No pages yet')
            ->assertDontSee('Add Hello World examples');
    }

    public function test_demo_content_rejects_unsaved_users(): void
    {
        Storage::fake('artifacts');

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Demo content can only be seeded for a saved user.');

        app(SeedDemoContent::class)->handle(new User());
    }
}
