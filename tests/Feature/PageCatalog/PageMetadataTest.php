<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\UpdatePageMetadata;
use App\Application\PageCatalog\UpdatePageMetadataCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageType;
use App\Models\AuditEntry;
use App\Models\Category;
use App\Models\DomainEvent;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_metadata_with_traceability_without_creating_a_version(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $newOwner = $this->createUser('New Owner', 'new-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);
        $this->addMember($workspace->uid, $newOwner, WorkspaceRole::Editor);
        $category = Category::query()->create([
            'workspace_uid' => $workspace->uid,
            'name' => 'Runbooks',
            'slug' => 'runbooks',
            'created_by_user_uid' => $owner->uid,
        ]);
        $parent = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Parent Page',
            description: null,
            content: '# Parent',
        ));
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Old Title',
            description: 'Old description.',
            content: '# Immutable content',
            tagNames: ['old'],
        ));
        $originalVersionUid = $page->current_version_uid;
        $originalVersionCount = PageVersion::query()->where('page_uid', $page->uid)->count();

        $updatedPage = app(UpdatePageMetadata::class)->handle($owner, new UpdatePageMetadataCommand(
            pageUid: $page->uid,
            expectedMetadataRevision: $page->metadata_revision,
            title: 'New Metadata Title',
            description: 'Updated description.',
            categoryUid: $category->uid,
            parentPageUid: $parent->uid,
            ownerUserUid: $newOwner->uid,
            tagNames: ['Operations', 'Runbook', 'operations'],
        ));

        $this->assertSame('New Metadata Title', $updatedPage->title);
        $this->assertSame('new-metadata-title', $updatedPage->slug);
        $this->assertSame('Updated description.', $updatedPage->description);
        $this->assertSame($category->uid, $updatedPage->category_uid);
        $this->assertSame($parent->uid, $updatedPage->parent_page_uid);
        $this->assertSame($newOwner->uid, $updatedPage->owner_user_uid);
        $this->assertSame($originalVersionUid, $updatedPage->current_version_uid);
        $this->assertSame(
            $originalVersionCount,
            PageVersion::query()->where('page_uid', $page->uid)->count(),
        );
        $this->assertSame(
            ['operations', 'runbook'],
            $updatedPage->tags()->orderBy('name')->pluck('name')->all(),
        );

        $event = DomainEvent::query()
            ->where('event_type', 'page.metadata.updated')
            ->sole();
        $this->assertSame($page->uid, $event->aggregate_uid);
        $this->assertSame($owner->uid, $event->payload['updated_by_user_uid']);
        $this->assertSame(
            'category_uid,description,owner_user_uid,parent_page_uid,tags,title',
            $event->payload['changed_fields'],
        );
        $this->assertArrayNotHasKey('title', $event->payload);
        $this->assertArrayNotHasKey('description', $event->payload);

        $auditEntry = AuditEntry::query()
            ->where('action', 'page.metadata.updated')
            ->sole();
        $this->assertSame($event->uid, $auditEntry->event_uid);
        $this->assertSame($owner->uid, $auditEntry->actor_user_uid);
        $this->assertSame($page->uid, $auditEntry->auditable_uid);
        $this->assertArrayNotHasKey('title', $auditEntry->metadata);
        $this->assertArrayNotHasKey('description', $auditEntry->metadata);

        $ownershipEvent = DomainEvent::query()
            ->where('event_type', 'page.ownership.transferred')
            ->sole();
        $this->assertSame($page->uid, $ownershipEvent->aggregate_uid);
        $this->assertSame($owner->uid, $ownershipEvent->payload['previous_owner_user_uid']);
        $this->assertSame($newOwner->uid, $ownershipEvent->payload['new_owner_user_uid']);
        $this->assertSame($owner->uid, $ownershipEvent->payload['transferred_by_user_uid']);
        $this->assertSame('metadata_update', $ownershipEvent->payload['reason']);
        $this->assertSame(1, AuditEntry::query()->where('action', 'page.ownership.transferred')->count());
    }

    public function test_editor_can_update_metadata_without_transferring_ownership(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Editable Metadata',
            description: null,
            content: '# Editable Metadata',
        ));

        $updatedPage = app(UpdatePageMetadata::class)->handle($editor, new UpdatePageMetadataCommand(
            pageUid: $page->uid,
            expectedMetadataRevision: $page->metadata_revision,
            title: 'Editor Updated Metadata',
            description: 'Updated without ownership transfer.',
            categoryUid: null,
            parentPageUid: null,
            ownerUserUid: $owner->uid,
            tagNames: ['operations'],
        ));

        $this->assertSame('Editor Updated Metadata', $updatedPage->title);
        $this->assertSame($owner->uid, $updatedPage->owner_user_uid);
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.metadata.updated')->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.ownership.transferred')->count());
    }

    public function test_ownership_transfer_bumps_preview_access_revision(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'preview-owner@example.test');
        $newOwner = $this->createUser('New Owner', 'preview-new-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Preview Transfer Team');
        $this->addMember($workspace->uid, $newOwner, WorkspaceRole::Editor);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Restricted Ownership Transfer',
            description: null,
            content: '# Restricted Ownership Transfer',
        ));
        $page->forceFill(['access_mode' => PageAccessMode::Restricted])->save();

        $updatedPage = app(UpdatePageMetadata::class)->handle($owner, new UpdatePageMetadataCommand(
            pageUid: $page->uid,
            expectedMetadataRevision: $page->metadata_revision,
            title: $page->title,
            description: $page->description,
            categoryUid: $page->category_uid,
            parentPageUid: $page->parent_page_uid,
            ownerUserUid: $newOwner->uid,
            tagNames: [],
        ));

        $this->assertSame($newOwner->uid, $updatedPage->owner_user_uid);
        $this->assertSame(1, $updatedPage->preview_access_revision);
    }

    public function test_editor_cannot_transfer_page_ownership_with_metadata_update(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $newOwner = $this->createUser('New Owner', 'new-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);
        $this->addMember($workspace->uid, $newOwner, WorkspaceRole::Editor);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Protected Owner',
            description: null,
            content: '# Protected Owner',
        ));

        try {
            app(UpdatePageMetadata::class)->handle($editor, new UpdatePageMetadataCommand(
                pageUid: $page->uid,
                expectedMetadataRevision: $page->metadata_revision,
                title: 'Protected Owner',
                description: null,
                categoryUid: null,
                parentPageUid: null,
                ownerUserUid: $newOwner->uid,
                tagNames: [],
            ));
            $this->fail('Expected editor ownership transfer to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot transfer page ownership.', $exception->getMessage());
        }

        $this->assertSame($owner->uid, $page->refresh()->owner_user_uid);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.metadata.updated')->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.ownership.transferred')->count());
    }

    public function test_repeating_the_same_metadata_update_is_idempotent(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Stable Metadata',
            description: 'Already current.',
            content: '# Stable',
            tagNames: ['runbook'],
        ));
        $command = new UpdatePageMetadataCommand(
            pageUid: $page->uid,
            expectedMetadataRevision: $page->metadata_revision,
            title: ' Stable Metadata ',
            description: ' Already current. ',
            categoryUid: null,
            parentPageUid: null,
            ownerUserUid: $owner->uid,
            tagNames: ['RUNBOOK', 'runbook'],
        );

        app(UpdatePageMetadata::class)->handle($owner, $command);
        app(UpdatePageMetadata::class)->handle($owner, $command);

        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.metadata.updated')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'page.metadata.updated')->count());
        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_reader_cannot_update_page_metadata(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Protected Metadata',
            description: null,
            content: '# Protected',
        ));

        try {
            app(UpdatePageMetadata::class)->handle($reader, new UpdatePageMetadataCommand(
                pageUid: $page->uid,
                expectedMetadataRevision: $page->metadata_revision,
                title: 'Unauthorized Title',
                description: null,
                categoryUid: null,
                parentPageUid: null,
                ownerUserUid: $owner->uid,
                tagNames: [],
            ));
            $this->fail('Expected reader metadata update to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot edit this page.', $exception->getMessage());
        }

        $this->assertSame('Protected Metadata', $page->refresh()->title);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.metadata.updated')->count());
    }

    public function test_metadata_update_rejects_invalid_relationships_and_cycles_without_trace_events(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $otherOwner = $this->createUser('Other Owner', 'other-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($otherOwner, 'Other Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Validated Metadata',
            description: null,
            content: '# Validated',
        ));
        $child = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Child Page',
            description: null,
            content: '# Child',
            parentPageUid: $page->uid,
        ));
        $foreignCategory = Category::query()->create([
            'workspace_uid' => $otherWorkspace->uid,
            'name' => 'Foreign',
            'slug' => 'foreign',
            'created_by_user_uid' => $otherOwner->uid,
        ]);

        foreach ([
            new UpdatePageMetadataCommand(
                pageUid: $page->uid,
                expectedMetadataRevision: $page->metadata_revision,
                title: 'Validated Metadata',
                description: null,
                categoryUid: $foreignCategory->uid,
                parentPageUid: null,
                ownerUserUid: $owner->uid,
                tagNames: [],
            ),
            new UpdatePageMetadataCommand(
                pageUid: $page->uid,
                expectedMetadataRevision: $page->metadata_revision,
                title: 'Validated Metadata',
                description: null,
                categoryUid: null,
                parentPageUid: $page->uid,
                ownerUserUid: $owner->uid,
                tagNames: [],
            ),
            new UpdatePageMetadataCommand(
                pageUid: $page->uid,
                expectedMetadataRevision: $page->metadata_revision,
                title: 'Validated Metadata',
                description: null,
                categoryUid: null,
                parentPageUid: $child->uid,
                ownerUserUid: $owner->uid,
                tagNames: [],
            ),
            new UpdatePageMetadataCommand(
                pageUid: $page->uid,
                expectedMetadataRevision: $page->metadata_revision,
                title: 'Validated Metadata',
                description: null,
                categoryUid: null,
                parentPageUid: null,
                ownerUserUid: $otherOwner->uid,
                tagNames: [],
            ),
            new UpdatePageMetadataCommand(
                pageUid: $page->uid,
                expectedMetadataRevision: $page->metadata_revision,
                title: 'Validated Metadata',
                description: null,
                categoryUid: null,
                parentPageUid: null,
                ownerUserUid: $owner->uid,
                tagNames: array_map(static fn (int $number): string => 'tag-' . $number, range(1, 26)),
            ),
        ] as $command) {
            try {
                app(UpdatePageMetadata::class)->handle($owner, $command);
                $this->fail('Expected invalid page metadata to be rejected.');
            } catch (DomainRuleViolation $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }

        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.metadata.updated')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'page.metadata.updated')->count());
    }

    public function test_metadata_update_cannot_transfer_ownership_to_a_reader_and_hides_reader_owner_choices(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Protected Ownership',
            description: null,
            content: '# Protected',
        ));

        try {
            app(UpdatePageMetadata::class)->handle($owner, new UpdatePageMetadataCommand(
                pageUid: $page->uid,
                expectedMetadataRevision: $page->metadata_revision,
                title: $page->title,
                description: null,
                categoryUid: null,
                parentPageUid: null,
                ownerUserUid: $reader->uid,
                tagNames: [],
            ));
            $this->fail('Expected Reader page ownership to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Page owner must be a workspace editor or admin.', $exception->getMessage());
        }

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('value="' . $owner->uid . '"', false)
            ->assertDontSee('value="' . $reader->uid . '"', false);

        $this->actingAs($owner)
            ->put("/pages/{$page->uid}/metadata", [
                'metadata_revision' => $page->metadata_revision,
                'title' => $page->title,
                'owner_user_uid' => $reader->uid,
            ])
            ->assertSessionHasErrors('metadata');

        $this->assertSame($owner->uid, $page->refresh()->owner_user_uid);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.metadata.updated')->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.ownership.transferred')->count());
    }

    public function test_metadata_parent_choices_hide_restricted_pages_and_forged_assignment_is_rejected(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);
        $targetPage = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Editable Target',
            description: null,
            content: '# Editable Target',
            ownerUserUid: $editor->uid,
        ));
        $visibleParent = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Visible Parent',
            description: null,
            content: '# Visible Parent',
        ));
        $restrictedParent = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Restricted Parent',
            description: null,
            content: '# Restricted Parent',
        ));
        $restrictedParent->forceFill(['access_mode' => PageAccessMode::Restricted])->save();
        $metadataEventCount = DomainEvent::query()
            ->where('event_type', 'page.metadata.updated')
            ->count();

        $this->actingAs($editor)
            ->get("/pages/{$targetPage->uid}")
            ->assertOk()
            ->assertSee('Visible Parent')
            ->assertSee('value="' . $visibleParent->uid . '"', false)
            ->assertDontSee('Restricted Parent')
            ->assertDontSee('value="' . $restrictedParent->uid . '"', false);

        $this->actingAs($editor)
            ->put("/pages/{$targetPage->uid}/metadata", [
                'metadata_revision' => $targetPage->metadata_revision,
                'title' => $targetPage->title,
                'owner_user_uid' => $editor->uid,
                'parent_page_uid' => $restrictedParent->uid,
            ])
            ->assertForbidden();

        $this->assertNull($targetPage->refresh()->parent_page_uid);
        $this->assertSame(
            $metadataEventCount,
            DomainEvent::query()->where('event_type', 'page.metadata.updated')->count(),
        );
    }

    public function test_http_metadata_update_validates_and_escapes_hostile_plain_text(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Safe Metadata',
            description: null,
            content: '# Safe',
        ));

        $this->actingAs($owner)
            ->put("/pages/{$page->uid}/metadata", [
                'metadata_revision' => $page->metadata_revision,
                'title' => '',
                'description' => str_repeat('x', 5001),
                'owner_user_uid' => $owner->uid,
                'tags' => str_repeat('t', 81),
            ])
            ->assertSessionHasErrors(['title', 'description', 'tags']);

        $this->actingAs($owner)
            ->put("/pages/{$page->uid}/metadata", [
                'metadata_revision' => $page->metadata_revision,
                'title' => $page->title,
                'description' => null,
                'owner_user_uid' => $owner->uid,
                'tags' => implode(',', array_map(static fn (int $number): string => 'tag-' . $number, range(1, 26))),
            ])
            ->assertSessionHasErrors('tags');

        $hostileTitle = '<script>alert("title")</script>';
        $hostileDescription = '<img src=x onerror=alert("description")>';
        $hostileTag = '<svg onload=alert("tag")>';

        $this->actingAs($owner)
            ->put("/pages/{$page->uid}/metadata", [
                'metadata_revision' => $page->metadata_revision,
                'title' => $hostileTitle,
                'description' => $hostileDescription,
                'owner_user_uid' => $owner->uid,
                'tags' => $hostileTag,
            ])
            ->assertRedirect("/pages/{$page->uid}");

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee($hostileTitle)
            ->assertSee($hostileDescription)
            ->assertSee(mb_strtolower($hostileTag))
            ->assertDontSee($hostileTitle, false)
            ->assertDontSee($hostileDescription, false)
            ->assertDontSee($hostileTag, false);

        $eventJson = DomainEvent::query()
            ->where('event_type', 'page.metadata.updated')
            ->sole()
            ->getRawOriginal('payload');
        $auditJson = AuditEntry::query()
            ->where('action', 'page.metadata.updated')
            ->sole()
            ->getRawOriginal('metadata');

        $this->assertIsString($eventJson);
        $this->assertIsString($auditJson);
        $this->assertStringNotContainsString('alert', $eventJson);
        $this->assertStringNotContainsString('onerror=', $eventJson);
        $this->assertStringNotContainsString('alert', $auditJson);
        $this->assertStringNotContainsString('onerror=', $auditJson);
        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_http_metadata_update_rejects_a_stale_form_without_clobbering_newer_fields(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Concurrent Owner', 'concurrent-metadata@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Concurrent Metadata Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Original Title',
            description: null,
            content: '# Original',
        ));
        $openedRevision = $page->metadata_revision;

        $this->actingAs($owner)
            ->put("/pages/{$page->uid}/metadata", [
                'metadata_revision' => $openedRevision,
                'title' => 'Newer Title',
                'owner_user_uid' => $owner->uid,
                'tags' => 'newer-tag',
            ])
            ->assertRedirect("/pages/{$page->uid}");

        $this->actingAs($owner)
            ->put("/pages/{$page->uid}/metadata", [
                'metadata_revision' => $openedRevision,
                'title' => 'Original Title',
                'owner_user_uid' => $owner->uid,
                'tags' => 'stale-tag',
            ])
            ->assertStatus(409);

        $page->refresh();
        $this->assertSame('Newer Title', $page->title);
        $this->assertSame(['newer-tag'], $page->tags()->pluck('name')->all());
        $this->assertSame($openedRevision + 1, $page->metadata_revision);
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
