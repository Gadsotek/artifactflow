<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The owner picker lists the page workspace's Editor/Admin members. Ownership
 * transfer needs page-admin authority, so a non-member Editor -- reachable now
 * that Editor grants no longer require workspace membership -- must not receive
 * that roster: it would disclose who belongs to a workspace they are not in.
 */
final class PageOwnerPickerDisclosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_member_editor_is_not_shown_the_workspace_owner_roster(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');

        // A second workspace member who only appears in the owner roster.
        $insider = $this->createUser('Insider Editor', 'insider@example.test');
        $this->addMember($workspace->uid, $insider, WorkspaceRole::Editor);

        // The grantee stays outside the page workspace and receives a page-only
        // Editor grant -- the newly allowed non-member Editor.
        $outsider = $this->createUser('Outside Editor', 'outsider@example.test');
        $home = app(CreateSharedWorkspace::class)->handle($owner, 'Outsider Home');
        $this->addMember($home->uid, $outsider, WorkspaceRole::Editor);

        $page = $this->restrictedPage($owner, $workspace->uid, 'Shared Page');
        $this->grantEditor($owner, $page, $outsider);

        $response = $this->actingAs($outsider)->get("/pages/{$page->uid}")->assertOk();

        // The outsider can edit, so the metadata form renders...
        $response->assertSee('Edit metadata');
        // ...but the insider's identity must not appear in the owner picker.
        $response->assertDontSee('Insider Editor');
        $response->assertDontSee($insider->uid);
        // The picker holds only the current owner, so the required owner field
        // still submits unchanged without exposing the roster.
        $response->assertSee('value="' . $owner->uid . '"', false);
    }

    public function test_page_admin_still_sees_the_owner_picker(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $coOwner = $this->createUser('Co Owner', 'co-owner@example.test');
        $this->addMember($workspace->uid, $coOwner, WorkspaceRole::Editor);
        $page = $this->restrictedPage($owner, $workspace->uid, 'Owned Page');

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Edit metadata')
            ->assertSee('Co Owner')
            ->assertSee('<select class="mt-2 w-full" name="owner_user_uid" required>', false);
    }

    public function test_non_member_editor_can_still_save_metadata_without_changing_owner(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $outsider = $this->createUser('Outside Editor', 'outsider@example.test');
        $home = app(CreateSharedWorkspace::class)->handle($owner, 'Outsider Home');
        $this->addMember($home->uid, $outsider, WorkspaceRole::Editor);
        $page = $this->restrictedPage($owner, $workspace->uid, 'Editable Page');
        $this->grantEditor($owner, $page, $outsider);

        // Submitting the current owner (as the hidden field does) leaves ownership
        // unchanged, so no page-admin authority is required and the save succeeds.
        $this->actingAs($outsider)
            ->put("/pages/{$page->uid}/metadata", [
                'title' => 'Renamed By Outsider',
                'description' => 'Edited by a non-member editor.',
                'owner_user_uid' => $owner->uid,
                'tags' => '',
            ])
            ->assertRedirect();

        $this->assertSame('Renamed By Outsider', $page->refresh()->title);
        $this->assertSame($owner->uid, $page->owner_user_uid);
    }

    public function test_non_member_editor_cannot_transfer_ownership_by_forging_the_owner_field(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $insider = $this->createUser('Insider Editor', 'insider@example.test');
        $this->addMember($workspace->uid, $insider, WorkspaceRole::Editor);
        $outsider = $this->createUser('Outside Editor', 'outsider@example.test');
        $home = app(CreateSharedWorkspace::class)->handle($owner, 'Outsider Home');
        $this->addMember($home->uid, $outsider, WorkspaceRole::Editor);
        $page = $this->restrictedPage($owner, $workspace->uid, 'Guarded Page');
        $this->grantEditor($owner, $page, $outsider);

        // Even though the picker is hidden, the outsider can POST any uid. The
        // server rejects the transfer under page-admin authority regardless.
        $this->actingAs($outsider)
            ->put("/pages/{$page->uid}/metadata", [
                'title' => 'Guarded Page',
                'owner_user_uid' => $insider->uid,
                'tags' => '',
            ])
            ->assertForbidden();

        $this->assertSame($owner->uid, $page->refresh()->owner_user_uid);
    }

    private function restrictedPage(User $owner, string $workspaceUid, string $title): Page
    {
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspaceUid,
            type: PageType::Markdown,
            title: $title,
            description: null,
            content: '# ' . $title,
        ));

        $page->forceFill(['access_mode' => PageAccessMode::Restricted])->save();

        return $page;
    }

    private function grantEditor(User $actor, Page $page, User $subject): void
    {
        app(GrantPageAccess::class)->handle($actor, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $subject->uid,
            role: WorkspaceRole::Editor,
        ));
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function addMember(string $workspaceUid, User $user, WorkspaceRole $role): void
    {
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspaceUid,
            'user_uid' => $user->uid,
            'role' => $role,
            'accepted_at' => now(),
        ]);
    }
}
