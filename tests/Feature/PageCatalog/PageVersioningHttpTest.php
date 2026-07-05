<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\ArchivePage;
use App\Application\PageCatalog\ArchivePageCommand;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\DomainEvent;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageVersioningHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_detail_exposes_version_history_update_and_restore_actions(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'HTTP Versioned Page',
            description: null,
            content: '# Version One',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Version Two',
            baseVersionUid: $firstVersion->uid,
        ));
        $this->assertNotNull($firstVersion->created_at);

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Edit Markdown')
            ->assertSee('Format the page directly')
            ->assertSee('data-content-editor', false)
            ->assertSee('data-editor-language="markdown"', false)
            ->assertSee('data-editor-capabilities="rich-markdown"', false)
            ->assertSee('data-editor-layout="rich"', false)
            ->assertSee('data-open-editor-dialog="page-content-dialog"', false)
            ->assertSee('id="page-content-dialog"', false)
            ->assertSee('name="base_version_uid"', false)
            ->assertSee('value="' . $secondVersion->uid . '"', false)
            ->assertSee('data-page-tools', false)
            ->assertSee('data-editor-view-button', false)
            ->assertSee('Markdown source')
            ->assertSee('data-rich-markdown-editor', false)
            ->assertSee('contenteditable="true"', false)
            ->assertSee('<h1>Version Two</h1>', false)
            ->assertSee('Version history')
            ->assertSee('id="page-versions-dialog"', false)
            ->assertSee('Version 1')
            ->assertSee('Version 2')
            ->assertSee('Changed by Editor User')
            ->assertSee($firstVersion->created_at->toDateString())
            ->assertSee("pages/{$page->uid}/versions/{$firstVersion->uid}/restore", false)
            ->assertDontSee("pages/{$page->uid}/versions/{$secondVersion->uid}/restore", false);

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions", [
                'content' => '# Version Three',
                'base_version_uid' => $page->refresh()->current_version_uid,
            ])
            ->assertRedirect("/pages/{$page->uid}");

        $page->refresh();
        $this->assertSame(3, PageVersion::query()->where('page_uid', $page->uid)->count());

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions/{$firstVersion->uid}/restore", [
                'current_version_uid' => $page->current_version_uid,
            ])
            ->assertRedirect("/pages/{$page->uid}");

        $page->refresh();
        $restoredVersion = PageVersion::query()->find($page->current_version_uid);

        $this->assertInstanceOf(PageVersion::class, $restoredVersion);
        $this->assertSame(4, $restoredVersion->version_number);
        $this->assertSame('# Version One', Storage::disk('artifacts')->get($restoredVersion->content_storage_path));
    }

    public function test_page_detail_handles_missing_current_markdown_content_without_a_server_error(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'missing-current@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Missing Current Content',
            description: null,
            content: '# Missing Current',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();

        Storage::disk('artifacts')->delete($version->content_storage_path);

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Stored page content is unavailable.')
            ->assertDontSee('<h1>Missing Current</h1>', false);
    }

    public function test_restore_reports_a_validation_error_when_source_version_content_is_missing(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'missing-restore@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Missing Restore Content',
            description: null,
            content: '# Version One',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Version Two',
            baseVersionUid: $firstVersion->uid,
        ));

        Storage::disk('artifacts')->delete($firstVersion->content_storage_path);

        $this->actingAs($editor)
            ->from("/pages/{$page->uid}")
            ->post("/pages/{$page->uid}/versions/{$firstVersion->uid}/restore", [
                'current_version_uid' => $secondVersion->uid,
            ])
            ->assertRedirect("/pages/{$page->uid}")
            ->assertSessionHasErrors([
                'version_uid' => 'Version content is missing from storage.',
            ]);

        $page->refresh();

        $this->assertSame($secondVersion->uid, $page->current_version_uid);
    }

    public function test_reader_cannot_see_version_mutation_forms_or_post_version_changes(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Reader Version Page',
            description: null,
            content: '# Version One',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();

        \App\Models\WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => \App\Domain\Identity\WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        $this->actingAs($reader)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Version history')
            ->assertDontSee('Edit Markdown')
            ->assertDontSee('Replace from HTML file')
            ->assertDontSee("pages/{$page->uid}/versions/{$version->uid}/restore", false);

        $this->actingAs($reader)
            ->post("/pages/{$page->uid}/versions", [
                'content' => '# Reader Version',
            ])
            ->assertForbidden();
    }

    public function test_oversized_content_field_is_rejected_when_replacing_by_upload(): void
    {
        Storage::fake('artifacts');
        config([
            'pages.max_html_bytes' => 1024,
            'pages.max_markdown_bytes' => 1024,
        ]);

        $editor = $this->createUser('Editor User', 'oversized-version@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Versioned Artifact',
            description: null,
            content: '<!doctype html><html><body>v1</body></html>',
        ));

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions", [
                'mode' => 'upload',
                'content' => str_repeat('a', 2048),
                'html_file' => $this->htmlUploadWithDetectedMime(
                    'ok.html',
                    '<!doctype html><html><body>v2</body></html>',
                    'text/html',
                ),
            ])
            ->assertSessionHasErrors('content');

        $this->assertSame(1, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_archived_page_hides_content_mutations_and_rejects_forged_version_requests(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Archived Version Page',
            description: null,
            content: '# Version one',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Version two',
            baseVersionUid: $firstVersion->uid,
        ));
        app(ArchivePage::class)->handle(
            $editor,
            new ArchivePageCommand($page->uid, confirmed: true),
        );

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertDontSee('Edit Markdown')
            ->assertDontSee("pages/{$page->uid}/versions/{$firstVersion->uid}/restore", false)
            ->assertSee('Unarchive page');

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions", [
                'content' => '# Forged update',
            ])
            ->assertSessionHasErrors('lifecycle');

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions/{$firstVersion->uid}/restore", [
                'current_version_uid' => $page->fresh()?->current_version_uid,
            ])
            ->assertSessionHasErrors('lifecycle');

        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_restore_from_a_stale_history_dialog_returns_409_without_overwriting_a_newer_version(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'stale-restore-http@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Stale Restore Page',
            description: null,
            content: '# Version One',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Version Two',
            baseVersionUid: $firstVersion->uid,
        ));

        // The history dialog was rendered when V2 was current, so its Restore form for
        // V1 carries current_version_uid=V2. A concurrent save then makes V3 current.
        app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Version Three',
            baseVersionUid: $secondVersion->uid,
        ));

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions/{$firstVersion->uid}/restore", [
                'current_version_uid' => $secondVersion->uid,
            ])
            ->assertStatus(409);

        // No V4 was appended; the concurrent save remains current.
        $this->assertSame(3, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_restore_without_the_current_version_token_is_refused_with_409(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'tokenless-restore-http@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Tokenless Restore Page',
            description: null,
            content: '# Version One',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Version Two',
            baseVersionUid: $firstVersion->uid,
        ));

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions/{$firstVersion->uid}/restore")
            ->assertStatus(409);

        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_posting_a_stale_base_version_uid_returns_409_without_creating_a_version(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'stale-http@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Stale HTTP Version Page',
            description: null,
            content: '# Version one',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Version two',
            baseVersionUid: $firstVersion->uid,
        ));

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions", [
                'content' => '# Stale browser update',
                'base_version_uid' => $firstVersion->uid,
            ])
            ->assertStatus(409)
            ->assertSee('This page changed since you opened it.');

        $this->assertSame($secondVersion->uid, $page->refresh()->current_version_uid);
        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_posting_the_current_base_version_uid_succeeds(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'current-http@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Current HTTP Version Page',
            description: null,
            content: '# Version one',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions", [
                'content' => '# Version two',
                'base_version_uid' => $firstVersion->uid,
            ])
            ->assertRedirect("/pages/{$page->uid}");

        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
    }

    public function test_editor_can_directly_edit_html_source_as_a_new_scanned_version(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Editable Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Version One</h1></body></html>',
        ));

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Sandbox profile')
            ->assertSee('Scripts only')
            ->assertDontSee('Open full-screen')
            ->assertSee('Edit HTML source')
            ->assertSee('data-open-editor-dialog="html-source-editor"', false)
            ->assertSee('data-editor-dialog', false)
            ->assertSee('id="html-source-editor"', false)
            ->assertSee('data-close-editor-dialog', false)
            ->assertSee('Replace from HTML file')
            ->assertSee('data-editor-language="html"', false)
            ->assertSee('data-editor-capabilities="line-numbers syntax-highlighting"', false)
            ->assertSee('data-source-editor-mount', false)
            ->assertDontSee('data-html-editor-inline', false)
            ->assertSee('&lt;h1&gt;Version One&lt;/h1&gt;', false)
            ->assertDontSee('<h1>Version One</h1>', false);

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions", [
                'mode' => 'source',
                'content' => '<!doctype html><html><body><h1>Version Two</h1><script>window.ready = true</script></body></html>',
                'base_version_uid' => $page->current_version_uid,
            ])
            ->assertRedirect("/pages/{$page->uid}");

        $version = PageVersion::query()
            ->where('page_uid', $page->uid)
            ->orderByDesc('version_number')
            ->first();

        $this->assertInstanceOf(PageVersion::class, $version);
        $this->assertSame(2, $version->version_number);
        $this->assertSame(PageVersionSource::Editor, $version->source);
        $storedContent = Storage::disk('artifacts')->get($version->content_storage_path);
        $this->assertIsString($storedContent);
        $this->assertStringContainsString(
            'Version Two',
            $storedContent,
        );

        $event = DomainEvent::query()
            ->where('event_type', 'page.version.created')
            ->where('payload->page_version_uid', $version->uid)
            ->sole();
        $this->assertSame('editor', $event->payload['source']);
    }

    public function test_editor_can_replace_an_html_artifact_by_reuploading_a_single_html_file(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Reupload Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Version One</h1></body></html>',
        ));

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions", [
                'mode' => 'upload',
                'base_version_uid' => $page->current_version_uid,
                'html_file' => UploadedFile::fake()->createWithContent(
                    'replacement.html',
                    '<!doctype html><html><body><h1>Uploaded Replacement</h1></body></html>',
                ),
            ])
            ->assertRedirect("/pages/{$page->uid}");

        $version = PageVersion::query()
            ->where('page_uid', $page->uid)
            ->orderByDesc('version_number')
            ->first();

        $this->assertInstanceOf(PageVersion::class, $version);
        $this->assertSame(2, $version->version_number);
        $this->assertSame(PageVersionSource::Upload, $version->source);
        $storedContent = Storage::disk('artifacts')->get($version->content_storage_path);
        $this->assertIsString($storedContent);
        $this->assertStringContainsString(
            'Uploaded Replacement',
            $storedContent,
        );

        $event = DomainEvent::query()
            ->where('event_type', 'page.version.created')
            ->where('payload->page_version_uid', $version->uid)
            ->sole();
        $this->assertSame('upload', $event->payload['source']);
    }

    public function test_html_reupload_accepts_text_plain_mime_when_structural_html_is_valid(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'text-plain-reupload@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Text Plain Reupload',
            description: null,
            content: '<!doctype html><html><body><h1>Version One</h1></body></html>',
        ));

        $this->actingAs($editor)
            ->post("/pages/{$page->uid}/versions", [
                'mode' => 'upload',
                'base_version_uid' => $page->current_version_uid,
                'html_file' => $this->htmlUploadWithDetectedMime(
                    'replacement.html',
                    '<html><body><h1>Short Replacement</h1></body></html>',
                    'text/plain',
                ),
            ])
            ->assertRedirect("/pages/{$page->uid}");

        $version = PageVersion::query()
            ->where('page_uid', $page->uid)
            ->orderByDesc('version_number')
            ->first();

        $this->assertInstanceOf(PageVersion::class, $version);
        $this->assertSame(2, $version->version_number);
        $this->assertSame(PageVersionSource::Upload, $version->source);
        $this->assertSame(
            '<html><body><h1>Short Replacement</h1></body></html>',
            Storage::disk('artifacts')->get($version->content_storage_path),
        );
    }

    public function test_html_reupload_validates_file_type_size_and_page_type(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $htmlPage = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Validated Upload',
            description: null,
            content: '<!doctype html><html><body>One</body></html>',
        ));
        $markdownPage = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Markdown Upload',
            description: null,
            content: '# Markdown',
        ));

        $this->actingAs($editor)
            ->post("/pages/{$htmlPage->uid}/versions", [
                'mode' => 'upload',
                'html_file' => UploadedFile::fake()->createWithContent('replacement.txt', '<html></html>'),
            ])
            ->assertSessionHasErrors('html_file');

        $this->actingAs($editor)
            ->post("/pages/{$htmlPage->uid}/versions", [
                'mode' => 'upload',
                'html_file' => UploadedFile::fake()->createWithContent('replacement.html', 'plain text'),
            ])
            ->assertSessionHasErrors('html_file');

        $this->actingAs($editor)
            ->post("/pages/{$htmlPage->uid}/versions", [
                'mode' => 'upload',
                'html_file' => UploadedFile::fake()->createWithContent(
                    'replacement.html',
                    "\x89PNG\r\n\x1a\n<script>alert(1)</script>",
                ),
            ])
            ->assertSessionHasErrors('html_file');

        $this->actingAs($editor)
            ->post("/pages/{$htmlPage->uid}/versions", [
                'mode' => 'upload',
                'html_file' => UploadedFile::fake()->createWithContent(
                    'replacement.html',
                    "<!doctype html><html><body>\xFF</body></html>",
                ),
            ])
            ->assertSessionHasErrors('html_file');

        config(['pages.max_html_bytes' => 10]);

        $this->actingAs($editor)
            ->post("/pages/{$htmlPage->uid}/versions", [
                'mode' => 'upload',
                'html_file' => UploadedFile::fake()->createWithContent('replacement.html', str_repeat('x', 11)),
            ])
            ->assertSessionHasErrors('html_file');

        $this->actingAs($editor)
            ->post("/pages/{$markdownPage->uid}/versions", [
                'mode' => 'upload',
                'html_file' => UploadedFile::fake()->createWithContent('replacement.html', '<html></html>'),
            ])
            ->assertSessionHasErrors('mode');

        $this->assertSame(1, PageVersion::query()->where('page_uid', $htmlPage->uid)->count());
        $this->assertSame(1, PageVersion::query()->where('page_uid', $markdownPage->uid)->count());
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
