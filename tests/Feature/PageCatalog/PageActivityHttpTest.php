<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\UpdatePageMetadata;
use App\Application\PageCatalog\UpdatePageMetadataCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageType;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageActivityHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_detail_shows_page_scoped_audit_activity_and_version_hashes(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Audited Page',
            description: null,
            content: '# Audited Page',
        ));

        app(UpdatePageMetadata::class)->handle($owner, new UpdatePageMetadataCommand(
            pageUid: $page->uid,
            title: 'Audited Page',
            description: 'Updated metadata.',
            categoryUid: null,
            parentPageUid: null,
            ownerUserUid: $owner->uid,
            tagNames: [],
        ));

        $version = $page->versions()->sole();

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Page activity')
            ->assertSee('Page created.')
            ->assertSee('Page version created.')
            ->assertSee('Page metadata updated.')
            ->assertSee('Owner User')
            ->assertSee('SHA-256 ' . $version->content_hash);
    }

    public function test_page_activity_survives_domain_event_pruning(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Retention Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Long-lived Page',
            description: null,
            content: '# Long-lived Page',
        ));

        app(UpdatePageMetadata::class)->handle($owner, new UpdatePageMetadataCommand(
            pageUid: $page->uid,
            title: 'Long-lived Page',
            description: 'Updated metadata.',
            categoryUid: null,
            parentPageUid: null,
            ownerUserUid: $owner->uid,
            tagNames: [],
        ));

        // Simulate the domain-event journal being pruned after dispatch. The retained
        // audit trail must still surface the page's activity timeline.
        DomainEvent::query()->delete();

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Page activity')
            ->assertSee('Page created.')
            ->assertSee('Page version created.')
            ->assertSee('Page metadata updated.');
    }

    public function test_page_activity_does_not_expose_private_audit_metadata_to_readers(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $viewer = $this->createUser('Outside Viewer', 'viewer@example.test');
        $hiddenTarget = $this->createUser('Hidden Target', 'hidden@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Security Team');
        // The grantees stay outside the page workspace, so their only path to this
        // page is the explicit grant (not workspace inheritance).
        $sharedWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Shared Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Shared Audit Page',
            description: null,
            content: '# Shared Audit Page',
        ));

        foreach ([$viewer, $hiddenTarget] as $target) {
            WorkspaceMembership::query()->forceCreate([
                'workspace_uid' => $sharedWorkspace->uid,
                'user_uid' => $target->uid,
                'role' => WorkspaceRole::Reader,
                'accepted_at' => now(),
            ]);
            app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
                pageUid: $page->uid,
                subjectType: PageAccessSubjectType::User,
                subjectUid: $target->uid,
                role: WorkspaceRole::Reader,
            ));
        }

        $this->actingAs($viewer)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Page access grant created.')
            ->assertDontSee($hiddenTarget->uid)
            ->assertDontSee($hiddenTarget->email)
            ->assertDontSee('Hidden Target');
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
