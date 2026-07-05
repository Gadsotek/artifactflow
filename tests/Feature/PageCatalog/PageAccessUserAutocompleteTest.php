<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageAccessUserAutocompleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_manager_can_search_the_internal_human_user_directory(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $pageWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Page Team');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Other Team');
        $pageMember = $this->createUser('Page Target', 'page-target@example.test');
        $otherMember = $this->createUser('Other Target', 'other-target@example.test');
        $pendingMember = $this->createUser('Pending Target', 'pending-target@example.test');
        $serviceAccount = $this->createUser('Service Target', 'service-target@example.test');
        $directoryUser = $this->createUser('Directory Target', 'directory-target@example.test');
        $directoryUser->forceFill(['is_system_admin' => true])->save();

        $this->addMember($pageWorkspace->uid, $pageMember, WorkspaceRole::Reader);
        $this->addMember($otherWorkspace->uid, $otherMember, WorkspaceRole::Editor);
        $this->addMember($otherWorkspace->uid, $pendingMember, WorkspaceRole::Reader, false);
        $this->addMember($otherWorkspace->uid, $serviceAccount, WorkspaceRole::Reader);
        $serviceAccount->forceFill(['is_service_account' => true])->save();

        $page = $this->createPage($owner, $pageWorkspace->uid);

        $this->actingAs($owner)
            ->getJson(route('pages.access-users.search', $page) . '?q=target')
            ->assertOk()
            ->assertJsonCount(4, 'results')
            ->assertJsonPath('results.0.email', 'directory-target@example.test')
            ->assertJsonPath('results.1.email', 'other-target@example.test')
            ->assertJsonPath('results.2.email', 'page-target@example.test')
            ->assertJsonPath('results.3.email', 'pending-target@example.test')
            ->assertJsonMissing(['email' => $serviceAccount->email])
            ->assertJsonMissing(['email' => $owner->email]);

        $this->actingAs($owner)
            ->get(route('pages.show', $page))
            ->assertOk()
            ->assertSee('data-known-user-picker', false)
            ->assertSee('data-search-url="' . route('pages.access-users.search', $page) . '"', false)
            ->assertDontSee($pageMember->email)
            ->assertDontSee($otherMember->email);
    }

    public function test_page_user_search_requires_manage_access_authority(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Page Team');
        $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);
        $page = $this->createPage($owner, $workspace->uid);

        $this->actingAs($reader)
            ->getJson(route('pages.access-users.search', $page) . '?q=owner')
            ->assertForbidden();
    }

    private function createPage(User $owner, string $workspaceUid): Page
    {
        return app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspaceUid,
            type: PageType::Markdown,
            title: 'Autocomplete Page',
            description: null,
            content: '# Autocomplete Page',
        ));
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('correct horse battery staple'),
        ]);
    }

    private function addMember(
        string $workspaceUid,
        User $user,
        WorkspaceRole $role,
        bool $accepted = true,
    ): void {
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspaceUid,
            'user_uid' => $user->uid,
            'role' => $role,
            'accepted_at' => $accepted ? now() : null,
        ]);
    }
}
