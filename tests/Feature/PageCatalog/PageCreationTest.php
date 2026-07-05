<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Models\AuditEntry;
use App\Models\Category;
use App\Models\DomainEvent;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class PageCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_can_create_a_markdown_page_with_mermaid_and_traceability(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $category = $this->createCategory($workspace, $editor, 'Architecture');

        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'System Map',
            description: 'Current context map.',
            content: "# System Map\n\n[[Runtime Notes]]\n\n```mermaid\ngraph TD\n  UI --> API\n```\n",
            status: PageStatus::Draft,
            categoryUid: $category->uid,
            tagNames: ['Architecture', 'Mermaid Blocks', 'architecture'],
        ));

        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $this->assertSame($workspace->uid, $page->workspace_uid);
        $this->assertSame($editor->uid, $page->owner_user_uid);
        $this->assertSame('system-map', $page->slug);
        $this->assertSame(PageType::Markdown, $page->type);
        $this->assertSame(PageStatus::Draft, $page->status);
        $this->assertSame($version->uid, $page->current_version_uid);

        $this->assertSame(1, $version->version_number);
        $this->assertSame(PageVersionSource::Editor, $version->source);
        $this->assertSame(hash('sha256', "# System Map\n\n[[Runtime Notes]]\n\n```mermaid\ngraph TD\n  UI --> API\n```\n"), $version->content_hash);
        $this->assertSame('clean', $version->scan_status->value);
        $this->assertSame([], $version->scan_findings);
        $this->assertStringContainsString('System Map', (string) $version->extracted_text);
        $this->assertStringContainsString('Runtime Notes', (string) $version->extracted_text);
        $this->assertStringContainsString('UI API', (string) $version->extracted_text);

        Storage::disk('artifacts')->assertExists($version->content_storage_path);
        $this->assertSame(
            "# System Map\n\n[[Runtime Notes]]\n\n```mermaid\ngraph TD\n  UI --> API\n```\n",
            Storage::disk('artifacts')->get($version->content_storage_path),
        );

        $tagSlugs = Tag::query()->orderBy('slug')->pluck('slug')->all();
        $this->assertSame(['architecture', 'mermaid-blocks'], $tagSlugs);

        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.created')->count());
        $versionEvent = DomainEvent::query()->where('event_type', 'page.version.created')->sole();
        $this->assertSame(PageVersionSource::Editor->value, $versionEvent->payload['source']);
        $this->assertSame(1, AuditEntry::query()->where('action', 'page.created')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'page.version.created')->count());
    }

    public function test_page_creation_rejects_too_many_tags_at_the_application_boundary(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'too-many-tags@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Markdown,
                title: 'Too Many Tags',
                description: null,
                content: '# Too Many Tags',
                tagNames: array_map(static fn (int $number): string => 'tag-' . $number, range(1, 26)),
            ));
            $this->fail('Expected too many tags to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Pages can have at most 25 tags.', $exception->getMessage());
        }

        $this->assertSame(0, Page::query()->where('title', 'Too Many Tags')->count());
    }

    public function test_tag_vocabulary_is_global_across_workspaces(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Global Tag Editor', 'global-tags@example.test');
        $platformWorkspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $researchWorkspace = app(CreateSharedWorkspace::class)->handle($editor, 'Research Team');

        $platformPage = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $platformWorkspace->uid,
            type: PageType::Markdown,
            title: 'Platform Notes',
            description: null,
            content: '# Platform',
            tagNames: ['Codex'],
        ));
        $researchPage = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $researchWorkspace->uid,
            type: PageType::Markdown,
            title: 'Research Notes',
            description: null,
            content: '# Research',
            tagNames: ['codex'],
        ));

        $this->assertSame(1, Tag::query()->where('slug', 'codex')->count());
        $this->assertSame(
            $platformPage->tags()->sole()->uid,
            $researchPage->tags()->sole()->uid,
        );
    }

    public function test_editor_can_create_html_artifact_with_advisory_javascript_warnings(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $html = '<!doctype html><html><body><h1>Forecast</h1><script>console.log("ok")</script></body></html>';

        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Forecast Demo',
            description: null,
            content: $html,
            sourceFilename: 'forecast.html',
        ));

        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $this->assertSame(PageType::HtmlArtifact, $page->type);
        $this->assertSame('warnings', $version->scan_status->value);
        $scanFindings = $version->scan_findings;

        $this->assertIsArray($scanFindings);
        $this->assertSame('inline_script', $scanFindings[0]['code']);
        $this->assertStringContainsString('Forecast', (string) $version->extracted_text);
        $this->assertStringNotContainsString('console.log', (string) $version->extracted_text);
        $this->assertStringContainsString('console log', (string) $version->source_text);
        Storage::disk('artifacts')->assertExists($version->content_storage_path);

        $this->assertSame(1, DomainEvent::query()->where('event_type', 'page.security_warnings.recorded')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'page.security_warnings.recorded')->count());
    }

    public function test_obvious_secrets_block_saving_and_record_content_safe_security_traceability(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Security Team');

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Markdown,
                title: 'Leaked Runbook',
                description: null,
                content: "AWS_SECRET_ACCESS_KEY=abcdefghijklmnopqrstuvwxyz1234567890\n",
            ));
            $this->fail('Expected obvious secrets to block page creation.');
        } catch (BlockedPageContentException $exception) {
            $this->assertSame(['aws_secret_access_key'], $exception->findingCodes());
        }

        $this->assertSame(0, Page::query()->count());
        $this->assertSame(0, PageVersion::query()->count());
        $event = DomainEvent::query()->where('event_type', 'page.secret_scan.blocked')->sole();
        $this->assertSame('workspace', $event->aggregate_type);
        $this->assertSame($workspace->uid, $event->aggregate_uid);
        $this->assertSame('aws_secret_access_key', $event->payload['finding_codes']);
        $this->assertSame('create_page', $event->payload['operation']);
        $this->assertArrayNotHasKey('content', $event->payload);
        $eventPayload = $event->getRawOriginal('payload');
        $this->assertIsString($eventPayload);
        $this->assertStringNotContainsString(
            'abcdefghijklmnopqrstuvwxyz1234567890',
            $eventPayload,
        );

        $audit = AuditEntry::query()->where('action', 'page.secret_scan.blocked')->sole();
        $this->assertSame($event->uid, $audit->event_uid);
        $this->assertSame($editor->uid, $audit->actor_user_uid);
        $this->assertSame('workspace', $audit->auditable_type);
        $this->assertSame($workspace->uid, $audit->auditable_uid);
        $auditMetadata = $audit->getRawOriginal('metadata');
        $this->assertIsString($auditMetadata);
        $this->assertStringNotContainsString(
            'abcdefghijklmnopqrstuvwxyz1234567890',
            $auditMetadata,
        );
        Storage::disk('artifacts')->assertMissing('pages/leaked-runbook/v1.md');
    }

    public function test_reader_cannot_create_pages_in_a_shared_workspace(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Read Only Team');

        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        $this->expectException(AuthorizationException::class);

        app(CreatePage::class)->handle($reader, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Reader Draft',
            description: null,
            content: '# Reader Draft',
        ));
    }

    public function test_editor_cannot_assign_page_ownership_to_a_workspace_reader(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Markdown,
                title: 'Escalating Ownership',
                description: null,
                content: '# Must not persist',
                ownerUserUid: $reader->uid,
            ));
            $this->fail('Expected Reader page ownership to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Page owner must be a workspace editor or admin.', $exception->getMessage());
        }

        $this->assertSame(0, Page::query()->where('title', 'Escalating Ownership')->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.created')->count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_duplicate_titles_get_unique_workspace_scoped_slugs(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');

        $firstPage = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'System Map',
            description: null,
            content: '# One',
        ));
        $secondPage = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'System Map',
            description: null,
            content: '# Two',
        ));

        $this->assertSame('system-map', $firstPage->slug);
        $this->assertSame('system-map-2', $secondPage->slug);
    }

    public function test_category_and_parent_page_must_belong_to_the_selected_workspace(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($editor, 'Other Team');
        $otherCategory = $this->createCategory($otherWorkspace, $editor, 'Other');
        $otherPage = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $otherWorkspace->uid,
            type: PageType::Markdown,
            title: 'Other Parent',
            description: null,
            content: '# Other',
        ));

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Markdown,
                title: 'Wrong Category',
                description: null,
                content: '# Wrong',
                categoryUid: $otherCategory->uid,
            ));
            $this->fail('Expected cross-workspace category assignment to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Category must belong to the selected workspace.', $exception->getMessage());
        }

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Markdown,
                title: 'Wrong Parent',
                description: null,
                content: '# Wrong',
                parentPageUid: $otherPage->uid,
            ));
            $this->fail('Expected cross-workspace parent assignment to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Parent page must belong to the selected workspace.', $exception->getMessage());
        }
    }

    public function test_editor_cannot_use_a_restricted_page_they_cannot_view_as_parent(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $editor->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);
        $restrictedParent = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Restricted Parent',
            description: null,
            content: '# Restricted Parent',
        ));
        $restrictedParent->forceFill(['access_mode' => PageAccessMode::Restricted])->save();
        $pageCount = Page::query()->count();
        $eventCount = DomainEvent::query()->count();
        $storedFiles = Storage::disk('artifacts')->allFiles();

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Markdown,
                title: 'Unauthorized Child',
                description: null,
                content: '# Must not persist',
                parentPageUid: $restrictedParent->uid,
            ));
            $this->fail('Expected an invisible restricted parent to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Parent page must belong to the selected workspace.', $exception->getMessage());
        }

        $this->assertSame($pageCount, Page::query()->count());
        $this->assertSame($eventCount, DomainEvent::query()->count());
        $this->assertSame($storedFiles, Storage::disk('artifacts')->allFiles());
    }

    public function test_failed_page_creation_transaction_removes_staged_content(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $eventName = 'eloquent.creating: ' . DomainEvent::class;

        Event::listen($eventName, static function (): void {
            throw new RuntimeException('Forced domain event persistence failure.');
        });

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Markdown,
                title: 'Rollback Page',
                description: null,
                content: '# Rollback Page',
            ));
            $this->fail('Expected page creation transaction failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced domain event persistence failure.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame(0, Page::query()->where('title', 'Rollback Page')->count());
        $this->assertSame(0, PageVersion::query()->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.created')->count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_page_creation_rejects_a_failed_content_write_without_database_records(): void
    {
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $disk = Mockery::mock(Filesystem::class);
        /** @var \Mockery\Expectation $putExpectation */
        $putExpectation = $disk->shouldReceive('put');
        $putExpectation->once()->andReturnFalse();
        /** @var \Mockery\Expectation $deleteExpectation */
        $deleteExpectation = $disk->shouldReceive('delete');
        $deleteExpectation->once()->andReturnTrue();
        Storage::shouldReceive('disk')
            ->twice()
            ->with('artifacts')
            ->andReturn($disk);

        try {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Markdown,
                title: 'Failed Write',
                description: null,
                content: '# Failed Write',
            ));
            $this->fail('Expected failed content storage to reject page creation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Failed to store page content.', $exception->getMessage());
        }

        $this->assertSame(0, Page::query()->where('title', 'Failed Write')->count());
        $this->assertSame(0, PageVersion::query()->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.created')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'page.created')->count());
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function createCategory(Workspace $workspace, User $creator, string $name): Category
    {
        return Category::query()->create([
            'workspace_uid' => $workspace->uid,
            'name' => $name,
            'slug' => strtolower($name),
            'created_by_user_uid' => $creator->uid,
        ]);
    }
}
