<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\BootstrapSystemAdmin;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\ArtifactPreviewUrl;
use App\Application\PageCatalog\CreateCategory;
use App\Application\PageCatalog\CreateCategoryCommand;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\RevokePageAccess;
use App\Application\PageCatalog\RevokePageAccessCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Http\Middleware\EnforceTwoFactorEnrollment;
use App\Models\Category;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class PageCreationHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_screen_requires_authentication_and_lists_editable_workspaces(): void
    {
        $this->get('/pages/create')
            ->assertRedirect('/login');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');

        $this->actingAs($editor)
            ->get('/pages/create')
            ->assertOk()
            ->assertSee('Create page')
            ->assertSee('Platform Team')
            ->assertSee('Markdown')
            ->assertSee('Upload HTML')
            ->assertSee('Paste HTML')
            ->assertDontSee('name="owner_user_uid"', false)
            ->assertSee('Preview HTML before saving')
            ->assertSee('data-html-draft-preview', false)
            ->assertSee('Never enter sensitive data such as passwords, emails, or logins into an artifact.')
            ->assertSee('Artifacts must be a')
            ->assertSee('single self-contained HTML file')
            ->assertSee('sandbox="allow-scripts"', false)
            ->assertSee('allow=""', false)
            ->assertSee('data-create-page-form', false)
            ->assertSee('data-create-page-essential-fields', false)
            ->assertSee('data-create-page-optional-fields', false)
            ->assertSee('data-create-page-content-fields', false)
            ->assertSee('data-create-page-upload-fields', false)
            ->assertSee('data-content-editor', false)
            ->assertSee('data-editor-language-select="type"', false)
            ->assertSee('data-editor-capabilities="rich-markdown source-code"', false)
            ->assertSee('data-rich-markdown-editor', false)
            ->assertSee('contenteditable="true"', false)
            ->assertSee('Rich Markdown', false);
    }

    public function test_browser_create_ignores_forged_owner_assignment(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $otherEditor = $this->createUser('Other Editor', 'other-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $otherEditor->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);

        $this->actingAs($editor)
            ->get('/pages/create')
            ->assertOk()
            ->assertDontSee('name="owner_user_uid"', false);

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'owner_user_uid' => $otherEditor->uid,
                'type' => 'markdown',
                'title' => 'Browser Owned Page',
                'status' => 'draft',
                'content' => '# Browser Owned Page',
            ])
            ->assertRedirect();

        $page = Page::query()->where('title', 'Browser Owned Page')->sole();

        $this->assertSame($editor->uid, $page->owner_user_uid);
    }

    public function test_page_creation_can_create_and_assign_a_workspace_category_ad_hoc(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Category Editor', 'adhoc-category@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');

        $this->actingAs($editor)
            ->get('/pages/create')
            ->assertOk()
            ->assertSee('name="category_name"', false)
            ->assertSee('data-create-page-category', false)
            ->assertSee('data-open-editor-dialog="page-category-create-dialog"', false)
            ->assertSee('aria-label="Create category"', false)
            ->assertSee('af-inline-field-action', false)
            ->assertSee('id="page-category-create-dialog"', false)
            ->assertSee('Create a new category')
            ->assertDontSee('Create a category while saving');

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'markdown',
                'title' => 'Ad-hoc Category Page',
                'status' => 'draft',
                'content' => '# Ad-hoc Category Page',
                'category_name' => ' Release Runbooks ',
            ])
            ->assertRedirect();

        $page = Page::query()->where('title', 'Ad-hoc Category Page')->sole();
        $category = Category::query()->where('workspace_uid', $workspace->uid)->sole();

        $this->assertSame('Release Runbooks', $category->name);
        $this->assertSame('release-runbooks', $category->slug);
        $this->assertSame($category->uid, $page->category_uid);
    }

    public function test_page_creation_prefills_the_workspace_selected_in_the_library(): void
    {
        $editor = $this->createUser('Workspace Editor', 'workspace-prefill@example.test');
        $firstWorkspace = app(CreateSharedWorkspace::class)->handle($editor, 'First Team');
        $selectedWorkspace = app(CreateSharedWorkspace::class)->handle($editor, 'Selected Team');

        $this->actingAs($editor)
            ->get("/pages?workspace_uid={$selectedWorkspace->uid}")
            ->assertOk()
            ->assertSee(
                route('pages.create', ['workspace_uid' => $selectedWorkspace->uid]),
                false,
            );

        $this->actingAs($editor)
            ->get(route('pages.create', ['workspace_uid' => $selectedWorkspace->uid]))
            ->assertOk()
            ->assertSee(
                'value="' . $selectedWorkspace->uid . '" selected',
                false,
            )
            ->assertDontSee(
                'value="' . $firstWorkspace->uid . '" selected',
                false,
            );
    }

    public function test_page_creation_from_a_page_prefills_its_workspace_and_parent(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Child Page Editor', 'child-page-editor@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($editor, 'Source Team');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($editor, 'Other Team');
        $sourceParent = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Source Parent',
            description: null,
            content: '# Source Parent',
        ));
        $otherParent = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $otherWorkspace->uid,
            type: PageType::Markdown,
            title: 'Other Parent',
            description: null,
            content: '# Other Parent',
        ));
        $createChildUrl = route('pages.create', [
            'workspace_uid' => $sourceWorkspace->uid,
            'parent_page_uid' => $sourceParent->uid,
        ]);

        $this->actingAs($editor)
            ->get(route('pages.show', $sourceParent))
            ->assertOk()
            ->assertSee($createChildUrl);

        $createResponse = $this->actingAs($editor)->get($createChildUrl);

        $createResponse
            ->assertOk()
            ->assertViewHas('selectedParentPageUid', $sourceParent->uid)
            ->assertSee('value="' . $sourceWorkspace->uid . '" selected', false)
            ->assertSee('data-create-page-parent-select', false)
            ->assertSee(
                'data-create-page-parent-workspace-uid="' . $sourceWorkspace->uid . '"',
                false,
            )
            ->assertDontSee('value="' . $otherParent->uid . '" selected', false);

        $this->actingAs($editor)
            ->get(route('pages.create', [
                'workspace_uid' => $sourceWorkspace->uid,
                'parent_page_uid' => $otherParent->uid,
            ]))
            ->assertOk()
            ->assertViewHas('selectedParentPageUid', null)
            ->assertDontSee('value="' . $otherParent->uid . '" selected', false);
    }

    public function test_page_creation_rejects_selecting_and_creating_a_category_together(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Category Editor', 'category-conflict@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $category = app(CreateCategory::class)->handle($editor, new CreateCategoryCommand(
            workspaceUid: $workspace->uid,
            name: 'Existing',
        ));

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'markdown',
                'title' => 'Ambiguous Category Page',
                'status' => 'draft',
                'content' => '# Ambiguous',
                'category_uid' => $category->uid,
                'category_name' => 'New Category',
            ])
            ->assertSessionHasErrors('category_name');

        $this->assertSame(0, Page::query()->where('title', 'Ambiguous Category Page')->count());
        $this->assertSame(1, Category::query()->where('workspace_uid', $workspace->uid)->count());
    }

    public function test_page_creation_rejects_a_duplicate_ad_hoc_category_without_creating_a_page(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Category Editor', 'duplicate-adhoc-category@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        app(CreateCategory::class)->handle($editor, new CreateCategoryCommand(
            workspaceUid: $workspace->uid,
            name: 'Release Runbooks',
        ));

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'markdown',
                'title' => 'Duplicate Category Page',
                'status' => 'draft',
                'content' => '# Duplicate',
                'category_name' => ' release runbooks ',
            ])
            ->assertSessionHasErrors('category_name');

        $this->assertSame(0, Page::query()->where('title', 'Duplicate Category Page')->count());
        $this->assertSame(1, Category::query()->where('workspace_uid', $workspace->uid)->count());
    }

    public function test_blocked_page_content_is_not_misreported_as_an_ad_hoc_category_error(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Category Editor', 'blocked-adhoc-category@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'markdown',
                'title' => 'Blocked Category Page',
                'status' => 'draft',
                'content' => 'api_key = "abcdefghijklmnopqrstuvwxyz1234567890"',
                'category_name' => 'Should Roll Back',
            ])
            ->assertSessionHasErrors('content')
            ->assertSessionDoesntHaveErrors('category_name');

        $this->assertSame(0, Page::query()->where('title', 'Blocked Category Page')->count());
        $this->assertSame(0, Category::query()->where('workspace_uid', $workspace->uid)->count());
    }

    public function test_create_page_rejects_too_many_tags(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'too-many-tags-http@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'markdown',
                'title' => 'Too Many Tags',
                'status' => 'draft',
                'content' => '# Too many tags',
                'tags' => implode(',', array_map(static fn (int $number): string => 'tag-' . $number, range(1, 26))),
            ])
            ->assertSessionHasErrors('tags');

        $this->assertSame(0, Page::query()->where('title', 'Too Many Tags')->count());
    }

    public function test_create_page_rejects_content_with_control_bytes_as_a_validation_error(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'nul-content-http@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');

        // A NUL byte would poison the derived source_text/extracted_text columns
        // (PostgreSQL text) and surface as a 500; it must be a clean 422 instead.
        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'markdown',
                'title' => 'Binary Content Page',
                'status' => 'draft',
                'content' => "# Notes\0 with a NUL byte",
            ])
            ->assertSessionHasErrors('content');

        $this->assertSame(0, Page::query()->where('title', 'Binary Content Page')->count());
    }

    public function test_create_page_rejects_metadata_with_control_bytes_as_a_validation_error(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'nul-metadata-http@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');

        // Metadata fields are bound to PostgreSQL text columns just like content. A NUL
        // (or malformed UTF-8) byte in the title or tags passes the string/length rules
        // -- mb_strlen counts it -- and would only fail as a 500 (SQLSTATE 22021) at
        // write time. It must be a clean 422 that writes nothing.
        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'markdown',
                'title' => "Binary\0 Title",
                'status' => 'draft',
                'content' => '# Clean content',
            ])
            ->assertSessionHasErrors('title');

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'markdown',
                'title' => 'Clean Title',
                'status' => 'draft',
                'content' => '# Clean content',
                'tags' => "release,\xFFmalformed",
            ])
            ->assertSessionHasErrors('tags');

        $this->assertSame(0, Page::query()->whereIn('title', ['Binary Title', 'Clean Title'])->count());
    }

    public function test_create_page_parent_choices_hide_restricted_pages_without_an_existence_oracle(): void
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

        $this->actingAs($editor)
            ->get('/pages/create')
            ->assertOk()
            ->assertSee('Visible Parent')
            ->assertSee('value="' . $visibleParent->uid . '"', false)
            ->assertDontSee('Restricted Parent')
            ->assertDontSee('value="' . $restrictedParent->uid . '"', false);

        $restrictedResponse = $this->actingAs($editor)->post('/pages', [
            'workspace_uid' => $workspace->uid,
            'type' => 'markdown',
            'title' => 'Forged Child',
            'status' => 'draft',
            'content' => '# Must not persist',
            'parent_page_uid' => $restrictedParent->uid,
        ]);

        $restrictedResponse
            ->assertRedirect()
            ->assertSessionHasErrors([
                'content' => 'Parent page must belong to the selected workspace.',
            ]);

        $missingResponse = $this->actingAs($editor)->post('/pages', [
            'workspace_uid' => $workspace->uid,
            'type' => 'markdown',
            'title' => 'Missing Parent Child',
            'status' => 'draft',
            'content' => '# Must not persist',
            'parent_page_uid' => (string) Str::ulid(),
        ]);

        $missingResponse
            ->assertRedirect()
            ->assertSessionHasErrors([
                'content' => 'Parent page must belong to the selected workspace.',
            ]);
        $this->assertSame($missingResponse->getStatusCode(), $restrictedResponse->getStatusCode());

        $this->assertSame(
            0,
            Page::query()->whereIn('title', ['Forged Child', 'Missing Parent Child'])->count(),
        );
    }

    public function test_authenticated_editor_can_create_a_markdown_page_and_land_on_detail(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');

        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $owner->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);

        $response = $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'owner_user_uid' => $owner->uid,
                'type' => 'markdown',
                'title' => 'Runtime Notes',
                'description' => 'Release runtime context.',
                'status' => 'draft',
                'content' => "# Runtime Notes\n\n```mermaid\ngraph TD\n  App --> DB\n```\n",
                'tags' => 'runtime, mermaid',
            ]);

        $page = Page::query()->where('title', 'Runtime Notes')->sole();

        $response->assertRedirect("/pages/{$page->uid}");
        $this->assertSame($editor->uid, $page->owner_user_uid);

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Runtime Notes')
            ->assertSee('Release runtime context.')
            ->assertSee('runtime')
            ->assertSee('mermaid')
            ->assertSee('graph TD');
    }

    public function test_rich_markdown_editor_repopulates_validation_input_with_sanitized_html(): void
    {
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');

        $this->actingAs($editor)
            ->from('/pages/create')
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'markdown',
                'title' => '',
                'status' => 'draft',
                'content' => "Safe **bold text**.\n\n<script>window.evil = true</script>",
            ])
            ->assertRedirect('/pages/create')
            ->assertSessionHasErrors('title');

        $this->actingAs($editor)
            ->get('/pages/create')
            ->assertOk()
            ->assertSee('<strong>bold text</strong>', false)
            ->assertSee('&lt;script&gt;window.evil = true&lt;/script&gt;', false)
            ->assertDontSee('<script>window.evil = true</script>', false);
    }

    public function test_markdown_detail_renders_sanitized_markdown_and_mermaid_targets(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $content = <<<'MARKDOWN'
            # Safe Notes

            This has **bold context** and [[Runtime Notes]].

            <script>window.evil = true</script>
            <img src="/logo.png" onerror="window.evil = true">

            [unsafe link](javascript:alert(1))
            [safe link](https://example.test/runbook)

            ```mermaid
            graph TD
              App --> DB
            ```
            MARKDOWN;

        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Safe Notes',
            description: null,
            content: $content,
        ));

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('<h1>Safe Notes</h1>', false)
            ->assertSee('<strong>bold context</strong>', false)
            ->assertSee('Runtime Notes')
            ->assertSee('data-mermaid-diagram', false)
            ->assertSee('data-mermaid-canvas', false)
            ->assertSee('data-mermaid-source=', false)
            ->assertSee('Diagram source')
            ->assertSee('graph TD')
            ->assertSee('<a>unsafe link</a>', false)
            ->assertSee('<a href="https://example.test/runbook" target="_blank" rel="noopener noreferrer">safe link</a>', false)
            ->assertSee('&lt;script&gt;window.evil = true&lt;/script&gt;', false)
            ->assertSee('javascript:alert(1)')
            ->assertDontSee('<script>window.evil = true</script>', false)
            ->assertDontSee('<img src="/logo.png" onerror="window.evil = true">', false)
            ->assertDontSee('href="javascript:', false);
    }

    public function test_editor_can_upload_a_valid_html_file(): void
    {
        Storage::fake('artifacts');
        config(['app.artifact_url' => 'http://artifacts.example.test']);

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');

        $response = $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'html_artifact',
                'mode' => 'html_upload',
                'title' => 'Prototype',
                'status' => 'draft',
                'html_file' => UploadedFile::fake()->createWithContent(
                    'prototype.html',
                    '<!doctype html><html><body><h1>Prototype</h1></body></html>',
                ),
            ]);

        $page = Page::query()->where('title', 'Prototype')->sole();
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $response->assertRedirect("/pages/{$page->uid}");
        $this->assertSame(PageType::HtmlArtifact, $page->type);
        $this->assertSame(PageVersionSource::Upload, $version->source);
        Storage::disk('artifacts')->assertExists($version->content_storage_path);
        $this->assertStringContainsString('Prototype', (string) $version->extracted_text);

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertDontSee('Open full-screen')
            ->assertDontSee('target="_blank"', false)
            ->assertSee('h-[calc(100vh-13rem)] min-h-[38rem]', false)
            ->assertSee('data-artifact-preview', false)
            ->assertSee('data-artifact-fullscreen-toggle', false)
            ->assertSee('Never enter sensitive data such as passwords, emails, or logins into an artifact.')
            ->assertSee('sandbox="allow-scripts"', false)
            ->assertSee('allow=""', false)
            ->assertSee('referrerpolicy="no-referrer"', false)
            ->assertSee("src=\"http://artifacts.example.test/artifact-previews/{$page->uid}/versions/{$version->uid}", false)
            ->assertDontSee("href=\"http://artifacts.example.test/artifact-previews/{$page->uid}/versions/{$version->uid}", false)
            ->assertDontSee('<h1>Prototype</h1>', false);
    }

    public function test_uploaded_html_artifact_persists_category_tags_and_status(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $category = app(CreateCategory::class)->handle($editor, new CreateCategoryCommand(
            workspaceUid: $workspace->uid,
            name: 'Runbooks',
        ));

        // The create form now exposes the organize metadata for uploads; the backend has
        // always accepted it, so an uploaded artifact must persist category, tags, and status.
        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'html_artifact',
                'mode' => 'html_upload',
                'title' => 'Release Dashboard',
                'status' => 'approved',
                'category_uid' => $category->uid,
                'tags' => 'architecture, runbook',
                'html_file' => UploadedFile::fake()->createWithContent(
                    'release-dashboard.html',
                    '<!doctype html><html><body><h1>Dashboard</h1></body></html>',
                ),
            ])
            ->assertRedirect();

        $page = Page::query()->where('title', 'Release Dashboard')->sole();
        $this->assertSame(PageType::HtmlArtifact, $page->type);
        $this->assertSame(PageStatus::Approved, $page->status);
        $this->assertSame($category->uid, $page->category_uid);

        $tagNames = [];

        foreach ($page->tags()->pluck('name')->all() as $tagName) {
            if (is_string($tagName)) {
                $tagNames[] = strtolower($tagName);
            }
        }

        sort($tagNames);
        $this->assertSame(['architecture', 'runbook'], $tagNames);
    }

    public function test_artifact_preview_warns_every_viewer_not_to_enter_sensitive_data(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'artifact-warning-owner@example.test');
        $reader = $this->createUser('Reader User', 'artifact-warning-reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Artifact Warning Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Shared Artifact',
            description: null,
            content: '<!doctype html><html><head></head><body><h1>Shared</h1></body></html>',
        ));

        $warning = 'Never enter sensitive data such as passwords, emails, or logins into an artifact.';

        // Every viewer is warned, including the author.
        $this->actingAs($reader)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee($warning);

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee($warning);
    }

    public function test_html_upload_accepts_text_plain_mime_when_structural_html_is_valid(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'text-plain-html@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');

        $response = $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'html_artifact',
                'mode' => 'html_upload',
                'title' => 'Short HTML Artifact',
                'status' => 'draft',
                'html_file' => $this->htmlUploadWithDetectedMime(
                    'short.html',
                    '<html><body><h1>Short Artifact</h1></body></html>',
                    'text/plain',
                ),
            ]);

        $page = Page::query()->where('title', 'Short HTML Artifact')->sole();
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $response->assertRedirect("/pages/{$page->uid}");
        $this->assertSame(PageVersionSource::Upload, $version->source);
        $this->assertSame(
            '<html><body><h1>Short Artifact</h1></body></html>',
            Storage::disk('artifacts')->get($version->content_storage_path),
        );
    }

    public function test_artifact_preview_serves_html_with_strict_security_headers_from_artifact_runtime(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_frame_ancestors' => 'http://localhost:18080',
            'app.artifact_url' => 'http://localhost',
            'app.runtime_role' => 'artifact-host',
        ]);

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Runnable Artifact',
            description: null,
            content: '<!doctype html><html><head></head><body><h1>Runnable</h1><script>console.log("artifactflow-console-leak"); document.cookie = "preview=1"; localStorage.setItem("preview", "1"); document.body.dataset.ready = "yes";</script></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);

        $response = $this->get($url);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Strict-Transport-Security')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Security-Policy');

        $this->assertFalse($response->headers->has('Set-Cookie'));

        $content = (string) $response->getContent();

        $response->assertSee('<h1>Runnable</h1>', false)
            ->assertSee('data-artifactflow-preview-guard', false)
            ->assertSee("defineValue(window, 'RTCPeerConnection', blockedConstructor)", false)
            ->assertSee("defineValue(window, 'webkitRTCPeerConnection', blockedConstructor)", false)
            ->assertSee('console.log("artifactflow-console-leak")', false)
            ->assertSee('document.body.dataset.ready', false)
            ->assertDontSee('data-artifactflow-preview-wrapper', false);

        $guardPosition = strpos($content, 'data-artifactflow-preview-guard');
        $userScriptPosition = strpos($content, 'console.log("artifactflow-console-leak")');

        $this->assertIsInt($guardPosition);
        $this->assertIsInt($userScriptPosition);
        $this->assertLessThan($userScriptPosition, $guardPosition);

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        // Exact sandbox token set: allow-scripts and nothing else (never same-origin).
        $this->assertSame('allow-scripts', $this->cspDirective($csp, 'sandbox'));
        $this->assertStringNotContainsString('allow-same-origin', $csp);
        $this->assertStringContainsString("script-src 'unsafe-inline'", $csp);
        $this->assertStringContainsString("connect-src 'none'", $csp);
        $this->assertStringNotContainsString('navigate-to', $csp);
        $this->assertStringContainsString("frame-ancestors http://localhost:18080", $csp);
    }

    public function test_artifact_preview_is_refused_for_top_level_navigation_and_served_only_to_iframe_embeds(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_frame_ancestors' => 'http://localhost:18080',
            'app.artifact_url' => 'http://localhost',
            'app.url' => 'http://localhost:18080',
            'app.runtime_role' => 'artifact-host',
        ]);

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Embed Only Artifact',
            description: null,
            content: '<!doctype html><html><head></head><body><h1>Embed Only</h1></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);

        // A top-level browser navigation (the browser sets Sec-Fetch-Dest: document).
        $topLevel = $this->get($url, ['Sec-Fetch-Dest' => 'document']);
        $topLevel->assertForbidden()
            ->assertDontSee('<h1>Embed Only</h1>', false)
            ->assertDontSee('data-artifactflow-preview-guard', false)
            ->assertSee('can only be viewed inside ArtifactFlow')
            ->assertSee('http://localhost:18080/pages/' . $page->uid, false);
        $this->assertSame('Sec-Fetch-Dest', $topLevel->headers->get('Vary'));

        // Any other explicit non-iframe destination is refused too.
        $this->get($url, ['Sec-Fetch-Dest' => 'empty'])->assertForbidden();

        // The real embedding path (browser sets Sec-Fetch-Dest: iframe) is served.
        $this->get($url, ['Sec-Fetch-Dest' => 'iframe'])
            ->assertOk()
            ->assertSee('<h1>Embed Only</h1>', false)
            ->assertSee('data-artifactflow-preview-guard', false);

        // Absent header fails open so a proxy that strips Sec-Fetch cannot break embedding.
        $this->get($url)
            ->assertOk()
            ->assertSee('<h1>Embed Only</h1>', false);
    }

    public function test_top_level_recovery_notice_links_to_the_app_origin_not_the_artifact_host(): void
    {
        Storage::fake('artifacts');
        // Reproduce the artifact-host runtime: compose overwrites its APP_URL with the
        // artifact origin, so app.url here is the artifact host itself. Only
        // artifact_frame_ancestors still carries the app origin -- the notice must link
        // there, or "Open it inside ArtifactFlow" 404s back on the artifact host.
        config([
            'app.artifact_frame_ancestors' => 'http://localhost:18080',
            'app.artifact_url' => 'http://localhost',
            'app.url' => 'http://localhost',
            'app.runtime_role' => 'artifact-host',
        ]);

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Embed Only Artifact',
            description: null,
            content: '<!doctype html><html><head></head><body><h1>Embed Only</h1></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);

        $this->get($url, ['Sec-Fetch-Dest' => 'document'])
            ->assertForbidden()
            ->assertSee('can only be viewed inside ArtifactFlow')
            // The link resolves to the app origin, never the artifact host that served it.
            ->assertSee('http://localhost:18080/pages/' . $page->uid, false)
            ->assertDontSee('http://localhost/pages/' . $page->uid, false);
    }

    public function test_artifact_preview_strips_refresh_meta_tags_before_serving_html(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_frame_ancestors' => 'http://localhost:18080',
            'app.artifact_url' => 'http://localhost',
            'app.runtime_role' => 'artifact-host',
        ]);

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Refresh Artifact',
            description: null,
            content: <<<'HTML'
                <!doctype html>
                <html>
                    <head>
                        <meta charset="utf-8">
                        <meta http-equiv="ref&#x72;esh" content="0; url=http://localhost:18080/leak">
                        <meta content="0; url=http://localhost:18080/leak" http-equiv=' refresh '>
                        <meta http-equiv=refresh content='0; url=http://localhost:18080/leak'>
                    </head>
                    <body>
                        <h1>Refresh stripped</h1>
                    </body>
                </html>
                HTML,
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);

        $response = $this->get($url);

        $response->assertOk()
            ->assertSee('<meta charset="utf-8">', false)
            ->assertSee('Refresh stripped', false)
            ->assertDontSee('http-equiv="ref&#x72;esh"', false)
            ->assertDontSee("http-equiv=' refresh '", false)
            ->assertDontSee('url=http://localhost:18080/leak', false)
            ->assertSee('data-artifactflow-preview-guard', false)
            ->assertDontSee('data-artifactflow-preview-wrapper', false);
    }

    public function test_artifact_preview_returns_not_found_when_stored_content_is_missing(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_url' => 'http://localhost',
            'app.runtime_role' => 'artifact-host',
        ]);

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Missing Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Missing</h1></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);

        Storage::disk('artifacts')->delete($version->content_storage_path);

        $this->get($url)
            ->assertNotFound();
    }

    public function test_artifact_read_refuses_oversized_content(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_url' => 'http://localhost',
            'app.runtime_role' => 'artifact-host',
        ]);

        $editor = $this->createUser('Editor User', 'oversized-artifact@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Oversized Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Too large</h1></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);
        config([
            'pages.max_html_bytes' => 16,
            'pages.artifact_max_bytes' => 16,
        ]);
        $disk = Mockery::mock(Filesystem::class);
        /** @var \Mockery\Expectation $sizeExpectation */
        $sizeExpectation = $disk->shouldReceive('size');
        $sizeExpectation->once()->with($version->content_storage_path)->andReturn(64);
        /** @var \Mockery\Expectation $getExpectation */
        $getExpectation = $disk->shouldReceive('get');
        $getExpectation->never();
        Storage::shouldReceive('disk')
            ->once()
            ->with('artifacts')
            ->andReturn($disk);

        $this->get($url)
            ->assertNotFound();
    }

    public function test_artifact_preview_uses_trusted_proxy_origin_for_public_https_urls(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_frame_ancestors' => 'https://app.example.test',
            'app.artifact_url' => 'https://artifacts.example.test',
            'app.runtime_role' => 'artifact-host',
            'trustedproxy.proxies' => ['172.18.0.12'],
        ]);

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Forwarded Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Forwarded</h1></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);

        $response = $this->withServerVariables([
            'HTTP_HOST' => 'artifact-host:8000',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.44',
            'HTTP_X_FORWARDED_HOST' => 'artifacts.example.test',
            'HTTP_X_FORWARDED_PORT' => '443',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '172.18.0.12',
        ])->get($this->pathAndQueryFrom($url));

        $response->assertOk()
            ->assertSee('<h1>Forwarded</h1>', false)
            ->assertSee('data-artifactflow-preview-guard', false);
    }

    public function test_artifact_preview_rejects_forwarded_origin_from_untrusted_proxy(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_frame_ancestors' => 'https://app.example.test',
            'app.artifact_url' => 'https://artifacts.example.test',
            'app.runtime_role' => 'artifact-host',
            'trustedproxy.proxies' => ['172.18.0.12'],
        ]);

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Untrusted Proxy Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Forwarded</h1></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);

        $this->withServerVariables([
            'HTTP_HOST' => 'artifact-host:8000',
            'HTTP_X_FORWARDED_HOST' => 'artifacts.example.test',
            'HTTP_X_FORWARDED_PORT' => '443',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '203.0.113.10',
        ])->get($this->pathAndQueryFrom($url))
            ->assertNotFound();
    }

    public function test_artifact_preview_rejects_superseded_versions_and_urls_issued_before_access_revocation(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_url' => 'http://localhost',
            'app.runtime_role' => 'artifact-host',
        ]);

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $viewer = $this->createUser('Viewer User', 'viewer@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Design Team');
        // The viewer stays outside the page workspace, so the page grant is its
        // only access path and revoking it fully removes view.
        $sharedWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Shared Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $sharedWorkspace->uid,
            'user_uid' => $viewer->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Bearer Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Version One</h1></body></html>',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $firstUrl = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $firstVersion);

        app(UpdatePageContent::class)->handle($owner, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '<!doctype html><html><body><h1>Version Two</h1></body></html>',
            baseVersionUid: $firstVersion->uid,
        ));

        $this->get($firstUrl)->assertNotFound();

        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $viewer->uid,
            role: WorkspaceRole::Reader,
        ));

        $page->refresh();
        $currentVersion = PageVersion::query()->findOrFail($page->current_version_uid);
        $bearerUrl = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $currentVersion);

        app(RevokePageAccess::class)->handle($owner, new RevokePageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $viewer->uid,
        ));

        $this->get($bearerUrl)
            ->assertNotFound();
    }

    public function test_artifact_preview_refuses_to_serve_from_the_app_origin(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_url' => 'http://artifacts.example.test',
        ]);

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'App Origin Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Never on app origin</h1></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);
        $appOriginUrl = str_replace('http://artifacts.example.test', 'http://localhost', $url);

        $this->get($appOriginUrl)
            ->assertNotFound();
    }

    public function test_app_runtime_refuses_to_serve_a_signed_preview_even_on_the_artifact_origin(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_url' => 'http://localhost',
            'app.runtime_role' => 'app',
        ]);

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Runtime Isolated Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Artifact host only</h1></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);

        $this->get($url)
            ->assertNotFound();
    }

    public function test_artifact_runtime_does_not_expose_application_routes(): void
    {
        config(['app.runtime_role' => 'artifact-host']);

        $this->get('/')
            ->assertNotFound();
        $this->get('/login')
            ->assertNotFound();
        $this->get('/pages')
            ->assertNotFound();
    }

    public function test_artifact_preview_csp_does_not_allow_same_origin_imports(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_frame_ancestors' => 'http://app.example.test',
            'app.artifact_url' => 'http://artifacts.example.test',
            'app.runtime_role' => 'artifact-host',
        ]);

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Same Origin Imports',
            description: null,
            content: <<<'HTML'
                <!doctype html>
                <html>
                    <head>
                        <link rel="stylesheet" href="/artifact.css">
                        <script src="/artifact.js"></script>
                    </head>
                    <body>
                        <img src="/logo.png" alt="Logo">
                        <iframe src="/nested.html"></iframe>
                    </body>
                </html>
                HTML,
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);

        $response = $this->get($url);

        $response->assertOk()
            ->assertSee('href="/artifact.css"', false)
            ->assertSee('src="/artifact.js"', false)
            ->assertSee('src="/logo.png"', false)
            ->assertSee('src="/nested.html"', false)
            ->assertSee('data-artifactflow-preview-guard', false)
            ->assertDontSee('data-artifactflow-preview-wrapper', false);

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertSame("'unsafe-inline'", $this->cspDirective($csp, 'script-src'));
        $this->assertSame("'unsafe-inline'", $this->cspDirective($csp, 'style-src'));
        $this->assertSame('data: blob:', $this->cspDirective($csp, 'img-src'));
        $this->assertSame('data:', $this->cspDirective($csp, 'font-src'));
        $this->assertSame("'none'", $this->cspDirective($csp, 'connect-src'));
        $this->assertSame("'none'", $this->cspDirective($csp, 'frame-src'));
        $this->assertSame("'none'", $this->cspDirective($csp, 'child-src'));
        $this->assertSame("'none'", $this->cspDirective($csp, 'worker-src'));
        // The opaque-origin guarantee: scripts may run, but the sandbox must never
        // grant allow-same-origin, or the artifact would execute in the artifact
        // host's own origin. Pinning the exact token set (not a loose substring)
        // means a regression that appends allow-same-origin fails here.
        $this->assertSame('allow-scripts', $this->cspDirective($csp, 'sandbox'));
        $this->assertStringNotContainsString('allow-same-origin', $csp);
        $this->assertSame("'none'", $this->cspDirective($csp, 'default-src'));
        $this->assertSame("'none'", $this->cspDirective($csp, 'object-src'));
        $this->assertSame("'none'", $this->cspDirective($csp, 'base-uri'));
        $this->assertSame("'none'", $this->cspDirective($csp, 'form-action'));
        $this->assertNull($this->cspDirective($csp, 'navigate-to'));
        $this->assertSame("'block'", $this->cspDirective($csp, 'webrtc'));
        $this->assertStringNotContainsString("'self'", $csp);
    }

    public function test_artifact_preview_rejects_expired_and_tampered_signatures(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_preview_url_ttl_seconds' => 1,
            'app.artifact_url' => 'http://localhost',
            'app.runtime_role' => 'artifact-host',
        ]);

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Expiring Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Expiring</h1></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);

        $this->travel(2)->seconds();

        $this->get($url)
            ->assertNotFound();

        $freshUrl = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);
        $tamperedUrl = str_replace('signature=', 'signature=broken', $freshUrl);

        $this->get($tamperedUrl)
            ->assertNotFound();
    }

    public function test_artifact_preview_does_not_reveal_whether_records_exist_to_invalid_signatures(): void
    {
        Storage::fake('artifacts');
        config([
            'app.artifact_url' => 'http://localhost',
            'app.runtime_role' => 'artifact-host',
        ]);

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Probed Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Probed</h1></body></html>',
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $probe = fn (string $pageUid, string $versionUid) => $this->get(sprintf(
            'http://localhost/artifact-previews/%s/versions/%s?expires=%d&signature=%s',
            $pageUid,
            $versionUid,
            now()->addMinute()->getTimestamp(),
            str_repeat('0', 64),
        ));

        $existingRecords = $probe($page->uid, $version->uid);
        $missingRecords = $probe((string) Str::ulid(), (string) Str::ulid());

        $existingRecords->assertNotFound();
        $missingRecords->assertNotFound();
        $this->assertSame(
            $existingRecords->getStatusCode(),
            $missingRecords->getStatusCode(),
            'Invalid-signature responses must not differ between existing and missing records.',
        );
    }

    public function test_invalid_html_upload_type_and_oversized_content_are_rejected(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'html_artifact',
                'mode' => 'html_upload',
                'title' => 'Not HTML',
                'status' => 'draft',
                'html_file' => UploadedFile::fake()->createWithContent('prototype.txt', 'plain text'),
            ])
            ->assertSessionHasErrors('html_file');

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'html_artifact',
                'mode' => 'html_upload',
                'title' => 'Control Character HTML',
                'status' => 'draft',
                'html_file' => $this->htmlUploadWithDetectedMime(
                    'control.html',
                    "<html><body>bad\x00content</body></html>",
                    'text/html',
                ),
            ])
            ->assertSessionHasErrors([
                'html_file' => 'HTML artifact uploads must be text, not binary content.',
            ]);

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'html_artifact',
                'mode' => 'html_upload',
                'title' => 'Binary Disguised As HTML',
                'status' => 'draft',
                'html_file' => UploadedFile::fake()->createWithContent(
                    'prototype.html',
                    "\x89PNG\r\n\x1a\n<script>alert(1)</script>",
                ),
            ])
            ->assertSessionHasErrors('html_file');

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'html_artifact',
                'mode' => 'html_upload',
                'title' => 'Invalid Encoding HTML',
                'status' => 'draft',
                'html_file' => UploadedFile::fake()->createWithContent(
                    'prototype.html',
                    "<!doctype html><html><body>\xFF</body></html>",
                ),
            ])
            ->assertSessionHasErrors('html_file');

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'html_artifact',
                'mode' => 'html_upload',
                'title' => 'Plain Text Disguised As HTML',
                'status' => 'draft',
                'html_file' => UploadedFile::fake()->createWithContent('prototype.html', 'plain text'),
            ])
            ->assertSessionHasErrors('html_file');

        config(['pages.max_html_bytes' => 12]);

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'html_artifact',
                'mode' => 'html_paste',
                'title' => 'Too Large',
                'status' => 'draft',
                'content' => '<html>too large</html>',
            ])
            ->assertSessionHasErrors('content');

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'html_artifact',
                'mode' => 'html_upload',
                'title' => 'Large Upload',
                'status' => 'draft',
                'html_file' => UploadedFile::fake()->createWithContent(
                    'large.html',
                    '<html>larger than configured limit</html>',
                ),
            ])
            ->assertSessionHasErrors('html_file');

        $this->assertSame(0, Page::query()->count());
    }

    public function test_oversized_content_field_is_rejected_even_when_uploading_a_file(): void
    {
        Storage::fake('artifacts');
        config([
            'pages.max_html_bytes' => 1024,
            'pages.max_markdown_bytes' => 1024,
        ]);

        $editor = $this->createUser('Editor User', 'oversized-content@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'html_artifact',
                'mode' => 'html_upload',
                'title' => 'Upload With Oversized Paste Leftover',
                'status' => 'draft',
                'content' => str_repeat('a', 2048),
                'html_file' => $this->htmlUploadWithDetectedMime(
                    'ok.html',
                    '<!doctype html><html><body>ok</body></html>',
                    'text/html',
                ),
            ])
            ->assertSessionHasErrors('content');

        $this->assertSame(0, Page::query()->count());
    }

    public function test_suspicious_html_warnings_are_saved_as_advisory_scan_metadata(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Design Team');
        $content = '<!doctype html><html><body><h1>Widget</h1><script>alert("x")</script></body></html>';

        $response = $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'html_artifact',
                'mode' => 'html_paste',
                'title' => 'Widget',
                'status' => 'draft',
                'content' => $content,
            ]);

        $page = Page::query()->where('title', 'Widget')->sole();
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $response->assertRedirect("/pages/{$page->uid}");
        $this->assertSame('warnings', $version->scan_status->value);
        $this->assertSame('inline_script', $version->scan_findings[0]['code'] ?? null);
    }

    public function test_saved_artifact_displays_specific_advisory_warning_findings(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Warning Details',
            description: null,
            content: '<!doctype html><script>fetch("/api"); window.parent.postMessage("x", "*");</script>',
        ));

        $this->actingAs($editor)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Security warnings recorded for this version')
            ->assertSee('Inline script tags were found.')
            ->assertSee('Network requests using fetch() were found.')
            ->assertSee('References to window.parent were found.');
    }

    public function test_page_detail_enforces_workspace_membership_and_page_overrides(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $outsider = $this->createUser('Outside User', 'outsider@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Private Page',
            description: null,
            content: '# Private Page',
        ));

        $this->actingAs($outsider)
            ->get("/pages/{$page->uid}")
            ->assertNotFound();

        PageAccessGrant::query()->forceCreate([
            'page_uid' => $page->uid,
            'subject_type' => PageAccessSubjectType::User,
            'subject_uid' => $outsider->uid,
            'role' => WorkspaceRole::Reader,
            'granted_by_user_uid' => $owner->uid,
        ]);

        $this->actingAs($outsider)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Private Page');
    }

    public function test_page_owner_can_grant_access_from_the_page_detail_shell(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $target = $this->createUser('Target User', 'target@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Operations Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Grantable Page',
            description: null,
            content: '# Grantable Page',
        ));

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Access overrides')
            ->assertSee('Grant user access')
            ->assertSee('Grant workspace access')
            ->assertSee('Search by name or email')
            ->assertSee('Operations Team')
            ->assertDontSee('Subject UID');

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/access", [
                'subject_type' => 'user',
                'user_email' => 'not-an-email',
                'role' => 'reader',
            ])
            ->assertSessionHasErrors('user_email');

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/access", [
                'subject_type' => 'user',
                'user_email' => 'missing@example.test',
                'role' => 'reader',
            ])
            ->assertRedirect("/pages/{$page->uid}")
            ->assertSessionHas('status', 'If that email belongs to an eligible registered coworker, their access has been granted.')
            ->assertSessionDoesntHaveErrors();

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/access", [
                'subject_type' => 'user',
                'user_email' => ' TARGET@example.test ',
                'role' => 'reader',
            ])
            ->assertRedirect("/pages/{$page->uid}");

        $this->actingAs($owner)
            ->post("/pages/{$page->uid}/access", [
                'subject_type' => 'workspace',
                'workspace_uid' => $targetWorkspace->uid,
                'role' => 'reader',
            ])
            ->assertRedirect("/pages/{$page->uid}");

        $this->assertSame(2, PageAccessGrant::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(1, PageAccessGrant::query()
            ->where('page_uid', $page->uid)
            ->where('subject_type', PageAccessSubjectType::Workspace)
            ->where('subject_uid', $targetWorkspace->uid)
            ->count());

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Target User')
            ->assertSee('target@example.test')
            ->assertDontSee($target->uid);

        $this->actingAs($target)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Grantable Page')
            ->assertDontSee('target@example.test')
            ->assertDontSee($target->uid)
            ->assertDontSee('Grant user access');
    }

    public function test_bootstrap_system_admin_can_receive_page_only_editor_access_without_workspace_membership(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $systemAdmin = app(BootstrapSystemAdmin::class)->handle(
            'Bootstrap System Admin',
            'bootstrap-admin@example.test',
            'correct horse battery staple',
        );
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Membership Scoped Grant Page',
            description: null,
            content: '# Membership Scoped Grant Page',
        ));
        $page->forceFill(['access_mode' => PageAccessMode::Restricted])->save();

        $this->assertFalse(app(PageAccess::class)->canView($systemAdmin, $page));

        $this->actingAs($owner)
            ->from("/pages/{$page->uid}")
            ->post("/pages/{$page->uid}/access", [
                'subject_type' => 'user',
                'user_email' => $systemAdmin->email,
                'role' => 'editor',
            ])
            ->assertRedirect("/pages/{$page->uid}")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'If that email belongs to an eligible registered coworker, their access has been granted.');

        $grant = PageAccessGrant::query()
            ->where('page_uid', $page->uid)
            ->where('subject_uid', $systemAdmin->uid)
            ->sole();

        $this->assertSame(WorkspaceRole::Editor, $grant->role);
        $this->assertTrue(app(PageAccess::class)->canEdit($systemAdmin, $page->refresh()));

        $this->withoutMiddleware(EnforceTwoFactorEnrollment::class);
        $this->actingAs($systemAdmin)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Membership Scoped Grant Page');
    }

    public function test_page_detail_editor_keeps_wiki_link_source_portable_while_display_resolves_links(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $target = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Runtime Notes',
            description: null,
            content: '# Runtime Notes',
        ));
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Portable Wiki Source',
            description: null,
            content: 'See [[Runtime Notes]].',
        ));

        $response = $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('href="/pages/' . $target->uid . '"', false)
            ->assertSee('data-rich-markdown-editor', false)
            ->assertSee('[[Runtime Notes]]', false);

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertSame(1, substr_count($content, 'href="/pages/' . $target->uid . '"'));
    }

    public function test_workspace_reader_cannot_grant_page_access_from_http(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $target = $this->createUser('Target User', 'target@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Grantable Page',
            description: null,
            content: '# Grantable Page',
        ));

        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        $this->actingAs($reader)
            ->post("/pages/{$page->uid}/access", [
                'subject_type' => 'user',
                'user_email' => $target->email,
                'role' => 'reader',
            ])
            ->assertForbidden();

        $this->assertSame(0, PageAccessGrant::query()->where('page_uid', $page->uid)->count());
    }

    public function test_workspace_reader_cannot_post_page_creation(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        $this->actingAs($reader)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'markdown',
                'title' => 'Reader Page',
                'status' => 'draft',
                'content' => '# Reader Page',
                'category_name' => 'Unauthorized Category',
            ])
            ->assertForbidden();

        $this->assertSame(0, Page::query()->where('title', 'Reader Page')->count());
        $this->assertSame(0, Category::query()->where('workspace_uid', $workspace->uid)->count());
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function cspDirective(string $csp, string $directive): ?string
    {
        foreach (explode(';', $csp) as $part) {
            $trimmedPart = trim($part);

            if (str_starts_with($trimmedPart, "{$directive} ")) {
                return trim(substr($trimmedPart, strlen($directive)));
            }
        }

        return null;
    }

    private function pathAndQueryFrom(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            self::fail('Artifact preview URL must include a path.');
        }

        $query = parse_url($url, PHP_URL_QUERY);

        if (!is_string($query) || $query === '') {
            return $path;
        }

        return $path . '?' . $query;
    }
}
