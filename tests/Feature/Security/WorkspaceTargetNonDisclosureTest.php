<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageType;
use App\Models\PageAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class WorkspaceTargetNonDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private const string MISSING_WORKSPACE_UID = '01K00000000000000000000000';

    public function test_child_creation_does_not_distinguish_missing_or_inaccessible_parent_workspaces(): void
    {
        $owner = $this->createUser('Target Owner', 'target-owner@example.test');
        $outsider = $this->createUser('Target Outsider', 'target-outsider@example.test');
        $shared = app(CreateSharedWorkspace::class)->handle($owner, 'Private Shared Target');
        $personal = app(CreatePersonalWorkspaceForUser::class)->handle($owner);

        foreach ([$shared->uid, $personal->uid, self::MISSING_WORKSPACE_UID] as $parentWorkspaceUid) {
            $this->actingAs($outsider)
                ->post('/workspaces', [
                    'name' => 'Unauthorized Child',
                    'parent_workspace_uid' => $parentWorkspaceUid,
                ])
                ->assertForbidden();
        }

        $this->assertFalse(Workspace::query()->where('name', 'Unauthorized Child')->exists());
    }

    public function test_reparent_preview_does_not_distinguish_missing_or_inaccessible_parent_workspaces(): void
    {
        $actor = $this->createUser('Hierarchy Actor', 'hierarchy-actor@example.test');
        $other = $this->createUser('Hierarchy Other', 'hierarchy-other@example.test');
        $source = app(CreateSharedWorkspace::class)->handle($actor, 'Movable Source');
        $shared = app(CreateSharedWorkspace::class)->handle($other, 'Private Shared Parent');
        $personal = app(CreatePersonalWorkspaceForUser::class)->handle($other);

        foreach ([$shared->uid, $personal->uid, self::MISSING_WORKSPACE_UID] as $parentWorkspaceUid) {
            $this->actingAs($actor)
                ->post("/workspaces/{$source->uid}/hierarchy/preview", [
                    'parent_workspace_uid' => $parentWorkspaceUid,
                ])
                ->assertForbidden();
        }

        $this->assertNull($source->refresh()->parent_workspace_uid);
    }

    public function test_page_workspace_grants_do_not_distinguish_missing_or_inaccessible_targets(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Page Owner', 'page-owner@example.test');
        $other = $this->createUser('Workspace Other', 'workspace-other@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Page Source');
        $shared = app(CreateSharedWorkspace::class)->handle($other, 'Private Shared Grant Target');
        $personal = app(CreatePersonalWorkspaceForUser::class)->handle($other);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Non-disclosure Page',
            description: null,
            content: '# Non-disclosure Page',
        ));

        foreach ([$shared->uid, $personal->uid, self::MISSING_WORKSPACE_UID] as $workspaceUid) {
            $this->actingAs($owner)
                ->post("/pages/{$page->uid}/access", [
                    'subject_type' => PageAccessSubjectType::Workspace->value,
                    'workspace_uid' => $workspaceUid,
                    'role' => WorkspaceRole::Reader->value,
                ])
                ->assertRedirect("/pages/{$page->uid}")
                ->assertSessionHasNoErrors()
                ->assertSessionHas(
                    'status',
                    'If that email belongs to an eligible registered coworker, their access has been granted.',
                );
        }

        $this->assertSame(0, PageAccessGrant::query()->where('page_uid', $page->uid)->count());
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
