<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageType;
use App\Models\PageAccessGrant;
use App\Models\PageVersion;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageRouteAuthorizationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_route_hides_existence_from_a_non_member_with_a_uniform_404(): void
    {
        Storage::fake('artifacts');

        $owner = app(CreateUser::class)->handle('Owner User', 'owner@example.test', 'correct horse battery staple');
        $outsider = app(CreateUser::class)->handle('Outside User', 'outsider@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Private Page',
            description: null,
            content: '# Private',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $this->actingAs($outsider)->get("/pages/{$page->uid}")->assertNotFound();
        $this->actingAs($outsider)
            ->get("/pages/{$page->uid}/versions/{$version->uid}")
            ->assertNotFound();
        $this->actingAs($outsider)
            ->getJson("/pages/{$page->uid}/versions/{$version->uid}/artifact-preview-url")
            ->assertNotFound();
        $this->actingAs($outsider)
            ->postJson("/pages/{$page->uid}/markdown-preview", ['content' => '# Preview'])
            ->assertNotFound();
        $this->actingAs($outsider)
            ->post("/pages/{$page->uid}/versions", ['content' => '# Update'])
            ->assertNotFound();
        $this->actingAs($outsider)
            ->post("/pages/{$page->uid}/versions/{$version->uid}/restore")
            ->assertNotFound();
        $this->actingAs($outsider)
            ->post("/pages/{$page->uid}/access", [
                'subject_type' => 'user',
                'user_email' => $outsider->email,
                'role' => 'reader',
            ])
            ->assertNotFound();
        $this->actingAs($outsider)
            ->delete("/pages/{$page->uid}")
            ->assertNotFound();

        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(0, PageAccessGrant::query()->where('page_uid', $page->uid)->count());
    }

    public function test_nested_version_and_grant_from_another_page_are_rejected_over_http(): void
    {
        Storage::fake('artifacts');

        $owner = app(CreateUser::class)->handle('Owner User', 'nested-owner@example.test', 'correct horse battery staple');
        $target = app(CreateUser::class)->handle('Target User', 'nested-target@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        // The target belongs to the page workspace and can receive elevated roles.
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $target->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $pageA = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Page A',
            description: null,
            content: '# Page A',
        ));
        $pageB = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Page B',
            description: null,
            content: '# Page B',
        ));
        $foreignVersion = app(UpdatePageContent::class)->handle($owner, new UpdatePageContentCommand(
            pageUid: $pageB->uid,
            content: '# Page B Version Two',
            baseVersionUid: $pageB->current_version_uid,
        ));
        $foreignGrant = app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $pageB->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $target->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->actingAs($owner)
            ->from("/pages/{$pageA->uid}")
            ->post("/pages/{$pageA->uid}/versions/{$foreignVersion->uid}/restore", [
                'current_version_uid' => $pageA->current_version_uid,
            ])
            ->assertSessionHasErrors('version_uid');

        $this->actingAs($owner)
            ->delete("/pages/{$pageA->uid}/access/{$foreignGrant->uid}")
            ->assertNotFound();

        $this->assertSame('# Page A', Storage::disk('artifacts')->get(
            PageVersion::query()->findOrFail($pageA->refresh()->current_version_uid)->content_storage_path,
        ));
    }
}
