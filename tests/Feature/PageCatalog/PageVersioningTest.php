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
use App\Application\PageCatalog\RestorePageVersion;
use App\Application\PageCatalog\RestorePageVersionCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\InvalidPageStatusTransition;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class PageVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_can_append_a_new_markdown_version_with_traceability(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Versioned Runbook',
            description: null,
            content: '# Original',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $newVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Revised' . PHP_EOL . PHP_EOL . 'Searchable update.',
            baseVersionUid: $firstVersion->uid,
        ));

        $page->refresh();

        $this->assertSame(2, $newVersion->version_number);
        $this->assertSame(PageVersionSource::Editor, $newVersion->source);
        $this->assertSame($newVersion->uid, $page->current_version_uid);
        $this->assertNotSame($firstVersion->uid, $newVersion->uid);
        $this->assertStringContainsString('Searchable update', (string) $newVersion->extracted_text);

        Storage::disk('artifacts')->assertExists($firstVersion->content_storage_path);
        Storage::disk('artifacts')->assertExists($newVersion->content_storage_path);
        $this->assertSame('# Original', Storage::disk('artifacts')->get($firstVersion->content_storage_path));
        $this->assertSame('# Revised' . PHP_EOL . PHP_EOL . 'Searchable update.', Storage::disk('artifacts')->get($newVersion->content_storage_path));

        $this->assertSame(2, DomainEvent::query()->where('event_type', 'page.version.created')->count());
        $versionEvent = DomainEvent::query()
            ->where('event_type', 'page.version.created')
            ->where('payload->page_version_uid', $newVersion->uid)
            ->sole();

        $this->assertSame($page->uid, $versionEvent->aggregate_uid);
        $this->assertSame($editor->uid, $versionEvent->payload['created_by_user_uid']);
        $this->assertSame(PageVersionSource::Editor->value, $versionEvent->payload['source']);

        $auditEntry = AuditEntry::query()
            ->where('action', 'page.version.created')
            ->where('auditable_uid', $newVersion->uid)
            ->sole();

        $this->assertSame($versionEvent->uid, $auditEntry->event_uid);
    }

    public function test_stale_base_version_uid_is_rejected_without_staging_content(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'stale-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Stale Version Page',
            description: null,
            content: '# Version one',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Version two',
            baseVersionUid: $firstVersion->uid,
        ));

        try {
            app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: '# Losing stale update',
                baseVersionUid: $firstVersion->uid,
            ));
            $this->fail('Expected stale base version to be rejected.');
        } catch (StalePageVersionException $exception) {
            $this->assertSame('This page changed since you opened it.', $exception->getMessage());
        }

        $this->assertSame($secondVersion->uid, $page->refresh()->current_version_uid);
        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame([
            $firstVersion->content_storage_path,
            $secondVersion->content_storage_path,
        ], Storage::disk('artifacts')->allFiles());
    }

    public function test_missing_base_version_uid_is_rejected_when_page_has_a_current_version(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'missing-base@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Missing Base Version Page',
            description: null,
            content: '# Version one',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();

        try {
            app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: '# Missing base update',
            ));
            $this->fail('Expected missing base version to be rejected.');
        } catch (StalePageVersionException $exception) {
            $this->assertSame('This page changed since you opened it.', $exception->getMessage());
        }

        $this->assertSame($firstVersion->uid, $page->refresh()->current_version_uid);
        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame([$firstVersion->content_storage_path], Storage::disk('artifacts')->allFiles());
    }

    public function test_correct_base_version_uid_appends_a_new_version(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'fresh-base@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Current Base Version Page',
            description: null,
            content: '# Version one',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $newVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Version two',
            baseVersionUid: $firstVersion->uid,
        ));

        $this->assertSame(2, $newVersion->version_number);
        $this->assertSame($newVersion->uid, $page->refresh()->current_version_uid);
        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_two_concurrent_saves_only_the_first_succeeds(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'concurrent-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Concurrent Version Page',
            description: null,
            content: '# Version one',
        ));
        $baseVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $winner = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# First editor wins',
            baseVersionUid: $baseVersion->uid,
        ));

        try {
            app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: '# Second editor loses',
                baseVersionUid: $baseVersion->uid,
            ));
            $this->fail('Expected the second save from the same base to be rejected.');
        } catch (StalePageVersionException $exception) {
            $this->assertSame('This page changed since you opened it.', $exception->getMessage());
        }

        $this->assertSame($winner->uid, $page->refresh()->current_version_uid);
        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame('# First editor wins', Storage::disk('artifacts')->get($winner->content_storage_path));
    }

    public function test_editor_can_restore_an_older_version_as_a_new_current_version(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Restore Runbook',
            description: null,
            content: '# Original Restore Body',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Updated Restore Body',
            baseVersionUid: $firstVersion->uid,
        ));

        $restoredVersion = app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
            pageUid: $page->uid,
            versionUid: $firstVersion->uid,
        ));

        $page->refresh();

        $this->assertSame(3, $restoredVersion->version_number);
        $this->assertSame(PageVersionSource::Restore, $restoredVersion->source);
        $this->assertSame($restoredVersion->uid, $page->current_version_uid);
        $this->assertSame('# Original Restore Body', Storage::disk('artifacts')->get($restoredVersion->content_storage_path));
        $this->assertSame('# Updated Restore Body', Storage::disk('artifacts')->get($secondVersion->content_storage_path));

        $restoredEvent = DomainEvent::query()
            ->where('event_type', 'page.version.restored')
            ->sole();

        $this->assertSame($page->uid, $restoredEvent->aggregate_uid);
        $this->assertSame($firstVersion->uid, $restoredEvent->payload['restored_from_version_uid']);
        $this->assertSame($restoredVersion->uid, $restoredEvent->payload['page_version_uid']);

        $auditEntry = AuditEntry::query()
            ->where('action', 'page.version.restored')
            ->sole();

        $this->assertSame($restoredEvent->uid, $auditEntry->event_uid);
        $this->assertSame($restoredVersion->uid, $auditEntry->metadata['page_version_uid']);
    }

    public function test_content_changes_return_approved_and_deprecated_pages_to_draft_with_traceability(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $approvedPage = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Approved Content',
            description: null,
            content: '# Approved original',
            status: PageStatus::Approved,
        ));
        $deprecatedPage = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Deprecated Content',
            description: null,
            content: '# Deprecated original',
            status: PageStatus::Approved,
        ));
        app(DeprecatePage::class)->handle($editor, new DeprecatePageCommand($deprecatedPage->uid));

        $approvedVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $approvedPage->uid,
            content: '# Approved content changed',
            baseVersionUid: $approvedPage->current_version_uid,
        ));
        $deprecatedVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $deprecatedPage->uid,
            content: '# Deprecated content changed',
            baseVersionUid: $deprecatedPage->current_version_uid,
        ));

        $this->assertSame(PageStatus::Draft, $approvedPage->refresh()->status);
        $this->assertSame(PageStatus::Draft, $deprecatedPage->refresh()->status);

        $events = DomainEvent::query()
            ->where('event_type', 'page.content_change_returned_to_draft')
            ->orderBy('aggregate_uid')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame(
            [$approvedPage->uid, $deprecatedPage->uid],
            $events->pluck('aggregate_uid')->sort()->values()->all(),
        );
        $this->assertSame(
            [PageStatus::Approved->value, PageStatus::Deprecated->value],
            $events->pluck('payload.previous_status')->sort()->values()->all(),
        );
        $this->assertSame(2, AuditEntry::query()
            ->where('action', 'page.content_change_returned_to_draft')
            ->count());
        Storage::disk('artifacts')->assertExists($approvedVersion->content_storage_path);
        Storage::disk('artifacts')->assertExists($deprecatedVersion->content_storage_path);
    }

    public function test_restoring_an_approved_page_version_returns_the_page_to_draft(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Approved Restore',
            description: null,
            content: '# Version one',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Version two',
            baseVersionUid: $firstVersion->uid,
        ));
        $page->forceFill(['status' => PageStatus::Approved])->save();

        app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
            pageUid: $page->uid,
            versionUid: $firstVersion->uid,
        ));

        $this->assertSame(PageStatus::Draft, $page->refresh()->status);
        $event = DomainEvent::query()
            ->where('event_type', 'page.content_change_returned_to_draft')
            ->sole();
        $this->assertSame(PageStatus::Approved->value, $event->payload['previous_status']);
        $this->assertSame(PageStatus::Draft->value, $event->payload['new_status']);
    }

    public function test_archived_pages_reject_new_versions_and_restores_without_side_effects(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Archived Content',
            description: null,
            content: '# Version one',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Version two',
            baseVersionUid: $firstVersion->uid,
        ));
        app(ArchivePage::class)->handle(
            $editor,
            new ArchivePageCommand($page->uid, confirmed: true),
        );

        foreach ([
            static fn () => app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: '# Forbidden update',
            )),
            static fn () => app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
                pageUid: $page->uid,
                versionUid: $firstVersion->uid,
            )),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected archived page content mutation to be rejected.');
            } catch (InvalidPageStatusTransition $exception) {
                $this->assertSame(
                    'Archived pages must be unarchived before changing content.',
                    $exception->getMessage(),
                );
            }
        }

        $this->assertSame(PageStatus::Archived, $page->refresh()->status);
        $this->assertSame($secondVersion->uid, $page->current_version_uid);
        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame([
            $firstVersion->content_storage_path,
            $secondVersion->content_storage_path,
        ], Storage::disk('artifacts')->allFiles());
        $this->assertSame(0, DomainEvent::query()
            ->where('event_type', 'page.content_change_returned_to_draft')
            ->count());
    }

    public function test_readers_and_secret_content_cannot_create_new_versions(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Protected Runbook',
            description: null,
            content: '# Original',
        ));

        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        try {
            app(UpdatePageContent::class)->handle($reader, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: '# Reader Update',
            ));
            $this->fail('Expected reader update to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot edit this page.', $exception->getMessage());
        }

        try {
            app(UpdatePageContent::class)->handle($owner, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: 'AWS_SECRET_ACCESS_KEY=abcdefghijklmnopqrstuvwxyz1234567890',
            ));
            $this->fail('Expected secret content to be rejected.');
        } catch (BlockedPageContentException $exception) {
            $this->assertSame(['aws_secret_access_key'], $exception->findingCodes());
        }

        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.version.created')->count());
        $blockedEvent = DomainEvent::query()->where('event_type', 'page.secret_scan.blocked')->sole();
        $this->assertSame($page->uid, $blockedEvent->aggregate_uid);
        $this->assertSame('create_version', $blockedEvent->payload['operation']);
        $this->assertSame('aws_secret_access_key', $blockedEvent->payload['finding_codes']);
        $this->assertArrayNotHasKey('content', $blockedEvent->payload);
        $this->assertSame(
            1,
            AuditEntry::query()
                ->where('action', 'page.secret_scan.blocked')
                ->where('auditable_uid', $page->uid)
                ->count(),
        );
    }

    public function test_restore_rejects_cross_page_versions_and_records_advisory_warnings(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $scriptedPage = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Scripted Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>One</h1><script>window.ready = true;</script></body></html>',
        ));
        $scriptedVersion = PageVersion::query()->where('page_uid', $scriptedPage->uid)->sole();
        app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $scriptedPage->uid,
            content: '<!doctype html><html><body><h1>Safe</h1></body></html>',
            baseVersionUid: $scriptedVersion->uid,
        ));
        $otherPage = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Other Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Other</h1></body></html>',
        ));

        try {
            app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
                pageUid: $otherPage->uid,
                versionUid: $scriptedVersion->uid,
            ));
            $this->fail('Expected cross-page restore to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Version does not belong to the selected page.', $exception->getMessage());
        }

        $restoredVersion = app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
            pageUid: $scriptedPage->uid,
            versionUid: $scriptedVersion->uid,
        ));

        $this->assertSame(3, $restoredVersion->version_number);
        $this->assertSame(2, DomainEvent::query()->where('event_type', 'page.security_warnings.recorded')->count());
    }

    public function test_failed_version_transaction_removes_staged_content_and_keeps_current_version(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Transactional Versions',
            description: null,
            content: '# Original',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $eventName = 'eloquent.creating: ' . DomainEvent::class;

        Event::listen($eventName, static function (): void {
            throw new RuntimeException('Forced version event persistence failure.');
        });

        try {
            app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: '# Rolled Back',
                baseVersionUid: $firstVersion->uid,
            ));
            $this->fail('Expected version transaction failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced version event persistence failure.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame($firstVersion->uid, $page->refresh()->current_version_uid);
        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame([$firstVersion->content_storage_path], Storage::disk('artifacts')->allFiles());
    }

    public function test_unique_version_conflict_does_not_delete_existing_storage_path(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Conflicting Version',
            description: null,
            content: '# Original',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $competingPath = sprintf('pages/%s/versions/2/source.md', $page->uid);
        $eventName = 'eloquent.creating: ' . PageVersion::class;
        $conflictCreated = false;

        Event::listen($eventName, function (PageVersion $version) use (
            &$conflictCreated,
            $competingPath,
            $editor,
            $page,
        ): void {
            if ($conflictCreated || $version->page_uid !== $page->uid || $version->version_number !== 2) {
                return;
            }

            $conflictCreated = true;
            Storage::disk('artifacts')->put($competingPath, '# Competing committed content');
            DB::table('page_versions')->insert([
                'uid' => (string) Str::ulid(),
                'page_uid' => $page->uid,
                'version_number' => 2,
                'content_storage_path' => $competingPath,
                'content_hash' => hash('sha256', '# Competing committed content'),
                'byte_size' => strlen('# Competing committed content'),
                'scan_status' => 'clean',
                'scan_findings' => null,
                'source' => PageVersionSource::Editor->value,
                'created_by_user_uid' => $editor->uid,
                'extracted_text' => 'Competing committed content',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: '# Losing concurrent update',
                baseVersionUid: $firstVersion->uid,
            ));
            $this->fail('Expected the conflicting version insert to fail.');
        } catch (Throwable) {
            $this->assertTrue($conflictCreated);
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame($firstVersion->uid, $page->refresh()->current_version_uid);
        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame('# Original', Storage::disk('artifacts')->get($firstVersion->content_storage_path));
        Storage::disk('artifacts')->assertExists($competingPath);
        $this->assertSame('# Competing committed content', Storage::disk('artifacts')->get($competingPath));
    }

    public function test_failed_content_status_reset_removes_staged_content_and_preserves_approved_version(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Approved Transaction',
            description: null,
            content: '# Approved original',
            status: PageStatus::Approved,
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $eventName = 'eloquent.creating: ' . DomainEvent::class;

        Event::listen($eventName, static function (DomainEvent $event): void {
            if ($event->event_type === 'page.content_change_returned_to_draft') {
                throw new RuntimeException('Forced content status event persistence failure.');
            }
        });

        try {
            app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: '# Rolled back approved update',
                baseVersionUid: $firstVersion->uid,
            ));
            $this->fail('Expected content status reset transaction failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Forced content status event persistence failure.',
                $exception->getMessage(),
            );
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame(PageStatus::Approved, $page->refresh()->status);
        $this->assertSame($firstVersion->uid, $page->current_version_uid);
        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame([$firstVersion->content_storage_path], Storage::disk('artifacts')->allFiles());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.version.created')->count());
        $this->assertSame(0, DomainEvent::query()
            ->where('event_type', 'page.content_change_returned_to_draft')
            ->count());
    }

    public function test_failed_restore_transaction_removes_the_new_restored_content(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Transactional Restore',
            description: null,
            content: '# Original',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Current',
            baseVersionUid: $firstVersion->uid,
        ));
        $eventName = 'eloquent.creating: ' . DomainEvent::class;

        Event::listen($eventName, static function (DomainEvent $event): void {
            if ($event->event_type === 'page.version.restored') {
                throw new RuntimeException('Forced restore event persistence failure.');
            }
        });

        try {
            app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
                pageUid: $page->uid,
                versionUid: $firstVersion->uid,
            ));
            $this->fail('Expected restore transaction failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced restore event persistence failure.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame($secondVersion->uid, $page->refresh()->current_version_uid);
        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame([
            $firstVersion->content_storage_path,
            $secondVersion->content_storage_path,
        ], Storage::disk('artifacts')->allFiles());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.version.restored')->count());
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
