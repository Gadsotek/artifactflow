<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\ArchivePage;
use App\Application\PageCatalog\ArchivePageCommand;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\DeprecatePage;
use App\Application\PageCatalog\DeprecatePageCommand;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DashboardDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_authorized_search_results_and_hides_archived_pages_by_default(): void
    {
        Storage::fake('artifacts');

        $user = $this->createUser('Dashboard User', 'dashboard@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Platform Team');
        app(CreatePage::class)->handle($user, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Visible Dashboard Page',
            description: null,
            content: '# Visible Dashboard Page',
            tagNames: ['Operations'],
        ));
        $deprecatedPage = app(CreatePage::class)->handle($user, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Deprecated Dashboard Page',
            description: null,
            content: '# Deprecated Dashboard Page',
            status: PageStatus::Approved,
            tagNames: ['Legacy', 'Operations'],
        ));
        app(DeprecatePage::class)->handle($user, new DeprecatePageCommand($deprecatedPage->uid));
        $archivedPage = app(CreatePage::class)->handle($user, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Archived Dashboard Page',
            description: null,
            content: '# Archived Dashboard Page',
        ));
        app(ArchivePage::class)->handle(
            $user,
            new ArchivePageCommand($archivedPage->uid, confirmed: true),
        );

        $this->actingAs($user)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Search pages')
            ->assertSee('Visible Dashboard Page')
            ->assertSee('Deprecated Dashboard Page')
            ->assertDontSee('Archived Dashboard Page')
            ->assertSee('Recently updated pages')
            ->assertSee('Category summary')
            ->assertSee('Popular tags')
            ->assertSee('operations')
            ->assertSee('legacy')
            ->assertSee('Draft pages')
            ->assertSee('Deprecated pages')
            ->assertSee('name="workspace_uid"', false)
            ->assertSee("value=\"{$workspace->uid}\"", false);
    }

    public function test_dashboard_ignores_forged_workspace_filter_instead_of_disclosing_foreign_categories(): void
    {
        $user = $this->createUser('Dashboard User', 'dashboard-filter@example.test');
        $foreignOwner = $this->createUser('Foreign Owner', 'dashboard-foreign@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Platform Team');
        $foreignWorkspace = app(CreateSharedWorkspace::class)->handle($foreignOwner, 'Foreign Team');
        Category::query()->create([
            'workspace_uid' => $foreignWorkspace->uid,
            'name' => 'Acquisition Secrets',
            'slug' => 'acquisition-secrets',
            'created_by_user_uid' => $foreignOwner->uid,
        ]);

        $this->actingAs($user)
            ->get("/dashboard?workspace_uid={$foreignWorkspace->uid}")
            ->assertOk()
            ->assertDontSee('Acquisition Secrets')
            ->assertSee("value=\"{$workspace->uid}\"", false)
            ->assertDontSee("value=\"{$foreignWorkspace->uid}\"", false);
    }

    public function test_dashboard_nests_visible_child_pages_beneath_their_parent(): void
    {
        Storage::fake('artifacts');

        $user = $this->createUser('Hierarchy User', 'dashboard-hierarchy@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Hierarchy Team');
        $parent = app(CreatePage::class)->handle($user, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Overview Parent',
            description: null,
            content: '# Overview Parent',
        ));
        app(CreatePage::class)->handle($user, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Overview Child',
            description: null,
            content: '# Overview Child',
            parentPageUid: $parent->uid,
        ));

        $this->actingAs($user)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder([
                'Overview Parent',
                'Under Overview Parent',
                'Overview Child',
            ])
            ->assertSee('data-page-hierarchy-depth="1"', false);
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
