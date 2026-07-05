<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\Category;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\PageVersion;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

final class PageCatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_page_catalog_metadata_versions_tags_and_access_grants_with_uids(): void
    {
        $owner = $this->createUser('Page Owner', 'owner@example.test');
        $reader = $this->createUser('Page Reader', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Knowledge Team');

        $category = Category::query()->create([
            'workspace_uid' => $workspace->uid,
            'name' => 'Architecture',
            'slug' => 'architecture',
            'created_by_user_uid' => $owner->uid,
        ]);

        $tag = Tag::query()->create([
            'name' => 'Mermaid',
            'slug' => 'mermaid',
            'created_by_user_uid' => $owner->uid,
        ]);

        $page = Page::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'owner_user_uid' => $owner->uid,
            'category_uid' => $category->uid,
            'parent_page_uid' => null,
            'title' => 'System Map',
            'slug' => 'system-map',
            'description' => 'Current context map.',
            'type' => PageType::Markdown,
            'status' => PageStatus::Draft,
        ]);

        $version = PageVersion::query()->forceCreate([
            'page_uid' => $page->uid,
            'version_number' => 1,
            'content_storage_path' => 'pages/system-map/v1.md',
            'content_hash' => hash('sha256', '# System Map'),
            'byte_size' => 12,
            'source' => PageVersionSource::Editor,
            'created_by_user_uid' => $owner->uid,
            'extracted_text' => 'System Map',
            'source_text' => 'System Map',
        ]);

        $page->forceFill(['current_version_uid' => $version->uid])->save();
        $page->tags()->attach($tag->uid);

        $grant = PageAccessGrant::query()->forceCreate([
            'page_uid' => $page->uid,
            'subject_type' => PageAccessSubjectType::User,
            'subject_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'granted_by_user_uid' => $owner->uid,
        ]);

        $page->refresh();

        $this->assertSame(PageType::Markdown, $page->type);
        $this->assertSame(PageStatus::Draft, $page->status);
        $this->assertSame(PageAccessMode::Inherited, $page->access_mode);
        $this->assertSame($version->uid, $page->current_version_uid);
        $this->assertSame($tag->uid, $page->tags()->sole()->uid);

        $this->assertSame(1, $version->version_number);
        $this->assertSame(PageVersionSource::Editor, $version->source);
        $this->assertSame('System Map', $version->extracted_text);
        $this->assertStringContainsString('System Map', (string) $version->source_text);

        $this->assertSame(PageAccessSubjectType::User, $grant->subject_type);
        $this->assertSame(WorkspaceRole::Reader, $grant->role);
        $this->assertSame($reader->uid, $grant->subject_uid);

        $this->assertFalse(Schema::hasColumn('pages', 'id'));
        $this->assertFalse(Schema::hasColumn('page_versions', 'id'));
        $this->assertFalse(Schema::hasColumn('page_access_grants', 'id'));
        $this->assertFalse(Schema::hasColumn('tags', 'workspace_uid'));
        $this->assertTrue(Schema::hasColumn('pages', 'access_mode'));
        $this->assertTrue(Schema::hasColumn('pages', 'preview_access_revision'));
        $this->assertTrue(Schema::hasColumn('pages', 'search_vector'));
        $this->assertTrue(Schema::hasColumn('page_versions', 'source'));
        $this->assertTrue(Schema::hasColumn('page_versions', 'source_text'));
        $this->assertTrue(Schema::hasIndex('pages', 'pages_parent_page_uid_index'));
        $this->assertTrue(Schema::hasIndex('pages', 'pages_owner_user_uid_index'));
        $this->assertTrue(Schema::hasIndex('pages', 'pages_category_uid_index'));
        $this->assertTrue(Schema::hasIndex('pages', 'pages_current_version_uid_index'));
        $this->assertTrue(Schema::hasIndex('page_versions', 'page_versions_source_index'));
        $this->assertTrue(DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('tablename', 'pages')
            ->where('indexname', 'pages_search_vector_gin_idx')
            ->exists());
        // Expression indexes backing the wiki-link resolver's LOWER(title)/LOWER(slug)
        // lookup; the wrapper defeats the plain (workspace_uid, slug) unique key.
        foreach (['pages_workspace_lower_title_index', 'pages_workspace_lower_slug_index'] as $indexName) {
            $this->assertTrue(DB::table('pg_indexes')
                ->where('schemaname', 'public')
                ->where('tablename', 'pages')
                ->where('indexname', $indexName)
                ->exists(), "Missing expected index {$indexName}.");
        }
        $this->assertTrue(DB::table('pg_constraint')
            ->where('conname', 'pages_category_workspace_fk')
            ->exists());
        $this->assertTrue(DB::table('pg_constraint')
            ->where('conname', 'pages_parent_workspace_fk')
            ->exists());
    }

    public function test_database_rejects_cross_workspace_category_relationships(): void
    {
        $owner = $this->createUser('Page Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Knowledge Team');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Other Team');
        $otherCategory = Category::query()->create([
            'workspace_uid' => $otherWorkspace->uid,
            'name' => 'Other Architecture',
            'slug' => 'other-architecture',
            'created_by_user_uid' => $owner->uid,
        ]);

        $this->expectException(QueryException::class);

        $this->insertRawPage(
            workspaceUid: $workspace->uid,
            ownerUserUid: $owner->uid,
            title: 'Cross Workspace Category',
            slug: 'cross-workspace-category',
            categoryUid: $otherCategory->uid,
        );
    }

    public function test_global_tag_migration_merges_workspace_duplicates_without_losing_page_links(): void
    {
        $owner = $this->createUser('Migration Owner', 'tag-migration@example.test');
        $alphaWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Alpha Team');
        $betaWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Beta Team');
        $alphaPageUid = $this->insertRawPage(
            workspaceUid: $alphaWorkspace->uid,
            ownerUserUid: $owner->uid,
            title: 'Alpha Page',
            slug: 'alpha-page',
        );
        $betaPageUid = $this->insertRawPage(
            workspaceUid: $betaWorkspace->uid,
            ownerUserUid: $owner->uid,
            title: 'Beta Page',
            slug: 'beta-page',
        );
        $migration = require base_path('database/migrations/2026_07_13_000002_globalize_tags.php');

        if (!is_object($migration)) {
            $this->fail('Expected the global tag migration to return an object.');
        }

        (new ReflectionMethod($migration, 'down'))->invoke($migration);
        $alphaTagUid = (string) Str::ulid();
        $betaTagUid = (string) Str::ulid();

        foreach ([
            [$alphaTagUid, $alphaWorkspace->uid, $alphaPageUid],
            [$betaTagUid, $betaWorkspace->uid, $betaPageUid],
        ] as [$tagUid, $workspaceUid, $pageUid]) {
            DB::table('tags')->insert([
                'uid' => $tagUid,
                'workspace_uid' => $workspaceUid,
                'name' => 'codex',
                'slug' => 'codex',
                'created_by_user_uid' => $owner->uid,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('page_tag')->insert([
                'page_uid' => $pageUid,
                'tag_uid' => $tagUid,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        (new ReflectionMethod($migration, 'up'))->invoke($migration);

        $canonicalTagUid = DB::table('tags')->where('slug', 'codex')->sole()->uid;
        $this->assertSame(1, DB::table('tags')->where('slug', 'codex')->count());
        $this->assertFalse(Schema::hasColumn('tags', 'workspace_uid'));
        $this->assertSame(
            [$alphaPageUid, $betaPageUid],
            DB::table('page_tag')->where('tag_uid', $canonicalTagUid)->orderBy('page_uid')->pluck('page_uid')->all(),
        );
    }

    public function test_database_rejects_cross_workspace_parent_relationships(): void
    {
        $owner = $this->createUser('Page Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Knowledge Team');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Other Team');
        $otherParent = Page::query()->forceCreate([
            'workspace_uid' => $otherWorkspace->uid,
            'owner_user_uid' => $owner->uid,
            'title' => 'Other Parent',
            'slug' => 'other-parent',
            'description' => null,
            'type' => PageType::Markdown,
            'status' => PageStatus::Draft,
        ]);

        $this->expectException(QueryException::class);

        $this->insertRawPage(
            workspaceUid: $workspace->uid,
            ownerUserUid: $owner->uid,
            title: 'Cross Workspace Parent',
            slug: 'cross-workspace-parent',
            parentPageUid: $otherParent->uid,
        );
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function insertRawPage(
        string $workspaceUid,
        string $ownerUserUid,
        string $title,
        string $slug,
        ?string $categoryUid = null,
        ?string $parentPageUid = null,
    ): string {
        $pageUid = (string) Str::ulid();
        DB::table('pages')->insert([
            'uid' => $pageUid,
            'workspace_uid' => $workspaceUid,
            'owner_user_uid' => $ownerUserUid,
            'parent_page_uid' => $parentPageUid,
            'category_uid' => $categoryUid,
            'current_version_uid' => null,
            'title' => $title,
            'slug' => $slug,
            'description' => null,
            'access_mode' => PageAccessMode::Inherited->value,
            'type' => PageType::Markdown->value,
            'status' => PageStatus::Draft->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $pageUid;
    }
}
