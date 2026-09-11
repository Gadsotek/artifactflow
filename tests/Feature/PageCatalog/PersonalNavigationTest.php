<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\PersonalPageState;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PersonalNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    public function test_opening_a_page_records_one_private_recent_entry_without_changing_the_page(): void
    {
        $user = User::factory()->create();
        $page = $this->pageFor($user, 'My recently opened guide');
        $updatedAt = $page->updated_at;

        $this->actingAs($user)->get(route('pages.show', $page))->assertOk();
        $this->travel(2)->minutes();
        $this->get(route('pages.show', $page))->assertOk();

        $this->assertDatabaseCount('personal_page_states', 1);
        $this->assertDatabaseHas('personal_page_states', ['user_uid' => $user->uid, 'page_uid' => $page->uid]);
        $this->assertEquals($updatedAt, $page->fresh()?->updated_at);
        $this->get('/recent')->assertOk()->assertSee($page->title);
        $this->actingAs(User::factory()->create())->get('/recent')->assertOk()->assertDontSee($page->title);
    }

    public function test_favorites_are_idempotent_private_and_available_to_readers(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $page = $this->pageFor($owner, 'Favorite reader reference');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $page->workspace_uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
        ]);

        $this->actingAs($reader)->putJson("/pages/{$page->uid}/favorite")->assertOk()->assertJsonPath('favorite', true);
        $this->putJson("/pages/{$page->uid}/favorite")->assertOk();
        $this->assertDatabaseCount('personal_page_states', 1);
        $this->get('/favorites')->assertOk()->assertSee($page->title);
        $this->actingAs($owner)->get('/favorites')->assertOk()->assertDontSee($page->title);
        $this->actingAs($reader)->deleteJson("/pages/{$page->uid}/favorite")->assertOk()->assertJsonPath('favorite', false);
        $this->get('/favorites')->assertOk()->assertDontSee($page->title);
    }

    public function test_revoked_pages_disappear_from_recents_favorites_and_quick_search(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $page = $this->pageFor($owner, 'Sensitive navigation marker');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $page->workspace_uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
        ]);
        $this->actingAs($reader)->get(route('pages.show', $page))->assertOk();
        $this->putJson("/pages/{$page->uid}/favorite")->assertOk();
        $page->forceFill(['access_mode' => PageAccessMode::Restricted])->save();

        $this->get('/recent')->assertOk()->assertDontSee($page->title);
        $this->get('/favorites')->assertOk()->assertDontSee($page->title);
        $this->getJson('/navigation/pages?q=Sensitive')->assertOk()->assertJsonCount(0, 'pages')->assertDontSee($page->uid);
        $this->putJson("/pages/{$page->uid}/favorite")->assertNotFound();
    }

    public function test_clear_recent_history_keeps_favorites_and_other_users_history(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $page = $this->pageFor($user, 'Retained favorite');
        $otherPage = $this->pageFor($other, 'Other persons history');
        $this->actingAs($user)->get(route('pages.show', $page))->assertOk();
        $this->putJson("/pages/{$page->uid}/favorite")->assertOk();
        $this->actingAs($other)->get(route('pages.show', $otherPage))->assertOk();
        $this->actingAs($user)->delete('/recent')->assertRedirect('/recent');

        $this->get('/recent')->assertOk()->assertSee('No recently opened pages');
        $this->get('/favorites')->assertOk()->assertSee($page->title);
        $this->actingAs($other)->get('/recent')->assertOk()->assertSee($otherPage->title);
    }

    public function test_quick_search_is_bounded_and_workspace_selection_is_exact(): void
    {
        $user = User::factory()->create();
        $page = $this->pageFor($user, 'Navigation north');
        $other = $this->pageFor($user, 'Navigation south');

        $this->actingAs($user)->getJson("/navigation/pages?q=Navigation&workspace_uid={$page->workspace_uid}")
            ->assertOk()->assertJsonCount(1, 'pages')->assertJsonPath('pages.0.uid', $page->uid)
            ->assertDontSee($other->uid);
        $this->getJson('/navigation/pages?q[]=malformed')->assertUnprocessable();
        $this->getJson('/navigation/pages?workspace_uid[]=malformed')->assertUnprocessable();
        $this->getJson('/navigation/pages?q=' . str_repeat('a', 201))->assertUnprocessable();
    }

    public function test_deleted_pages_remove_personal_state_and_renames_are_reflected(): void
    {
        $user = User::factory()->create();
        $page = $this->pageFor($user, 'Old navigation title');
        $this->actingAs($user)->get(route('pages.show', $page))->assertOk();
        $this->putJson("/pages/{$page->uid}/favorite")->assertOk();
        $page->forceFill(['title' => 'New navigation title'])->save();
        $this->get('/recent')->assertOk()->assertSee('New navigation title')->assertDontSee('Old navigation title');
        DB::table('pages')->where('uid', $page->uid)->delete();
        $this->assertDatabaseCount('personal_page_states', 0);
    }

    public function test_personal_navigation_requires_authentication(): void
    {
        $this->get('/recent')->assertRedirect('/login');
        $this->get('/favorites')->assertRedirect('/login');
        $this->getJson('/navigation/pages?q=private')->assertUnauthorized();
    }

    public function test_navigation_shell_has_explicit_destinations_and_new_page_does_not_inherit_a_parent(): void
    {
        $user = User::factory()->create();
        $page = $this->pageFor($user, 'Navigation shell');
        $response = $this->actingAs($user)->get(route('pages.show', $page))->assertOk();
        $response->assertSee('Search or jump to')->assertSee('Recently opened')->assertSee('Favorites');
        $response->assertSee('Add child page')->assertSee('data-quick-navigation', false);
        $this->assertSame(route('pages.create'), $this->app->make(\App\View\Components\Layouts\App::class)->newPageUrl);
        $this->get('/dashboard')->assertOk()->assertSee('Continue where you left off')->assertSee($page->title);
    }

    public function test_recent_limit_preserves_old_favorites_and_archived_favorites_remain_labeled(): void
    {
        $user = User::factory()->create();
        $first = $this->pageFor($user, 'Old favorite');
        $state = app(PersonalPageState::class);
        $state->recordVisit($user, $first);
        $state->favorite($user, $first, true);
        $this->travel(1)->minutes();
        $pages = Page::factory()->count(100)->create(['workspace_uid' => $first->workspace_uid, 'owner_user_uid' => $user->uid]);
        foreach ($pages as $page) {
            $state->recordVisit($user, $page);
        }
        $this->assertSame(100, DB::table('personal_page_states')->whereNotNull('last_opened_at')->count());
        $this->assertDatabaseHas('personal_page_states', ['page_uid' => $first->uid, 'last_opened_at' => null]);
        $this->assertTrue($state->isFavorite($user, $first));
        $first->forceFill(['status' => PageStatus::Archived])->save();
        $this->actingAs($user)->get('/favorites')->assertOk()->assertSee('Old favorite')->assertSee('Archived');
        $this->getJson('/navigation/pages?q=Old')->assertJsonCount(0, 'pages');
    }

    public function test_home_places_personal_sections_in_the_workspace_body_and_labels_search_and_nested_workspaces(): void
    {
        $user = User::factory()->create();
        $root = app(CreateSharedWorkspace::class)->handle($user, 'Design team');
        app(CreateSharedWorkspace::class)->handle($user, 'Research notes', $root->uid);
        $response = $this->actingAs($user)->get('/dashboard')->assertOk();
        $document = new \DOMDocument();
        @$document->loadHTML((string) $response->getContent());
        $xpath = new \DOMXPath($document);

        foreach (['home-recent-title', 'home-favorites-title'] as $titleId) {
            $sections = $xpath->query('//section[@id="workspace-overview-panel"]//section[@aria-labelledby="' . $titleId . '"]');
            $this->assertNotFalse($sections);
            $this->assertCount(1, $sections);
        }

        $response->assertSee('Search this workspace')->assertSee('Find a workspace');
        $names = $xpath->query('//*[@data-workspace-depth="1"]/*[@data-workspace-name]');
        $this->assertNotFalse($names);
        $this->assertCount(1, $names);
        $name = $names->item(0);
        $this->assertInstanceOf(\DOMElement::class, $name);
        $this->assertSame('Research notes', trim($name->textContent));
    }

    public function test_favorites_have_an_independent_capacity_and_existing_favorites_can_be_repeated(): void
    {
        $user = User::factory()->create();
        $first = $this->pageFor($user, 'Capacity');
        $pages = Page::factory()->count(200)->create(['workspace_uid' => $first->workspace_uid, 'owner_user_uid' => $user->uid]);
        foreach ($pages as $page) {
            DB::table('personal_page_states')->insert([
                'uid' => (string) Str::ulid(), 'user_uid' => $user->uid, 'page_uid' => $page->uid, 'favorited_at' => now(),
            ]);
        }
        $existing = $pages->firstOrFail();
        $this->actingAs($user)->putJson("/pages/{$first->uid}/favorite")->assertUnprocessable()->assertJsonValidationErrors('favorite');
        $this->putJson("/pages/{$existing->uid}/favorite")->assertOk();
        $this->deleteJson("/pages/{$existing->uid}/favorite")->assertOk();
        $this->putJson("/pages/{$first->uid}/favorite")->assertOk();
        $this->assertDatabaseCount('personal_page_states', 200);
    }

    public function test_direct_grants_do_not_disclose_workspace_names_and_quick_results_stop_at_twenty(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $first = $this->pageFor($owner, 'Granted reference');
        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $first->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $reader->uid,
            role: WorkspaceRole::Reader,
        ));
        $this->actingAs($reader)->getJson('/navigation/pages?q=Granted')->assertOk()
            ->assertJsonCount(1, 'pages')->assertJsonPath('pages.0.workspace', null)
            ->assertDontSee($first->workspace->name);
        $this->get(route('pages.show', $first))->assertOk();
        $this->get('/recent')->assertOk()->assertSee($first->title)->assertDontSee($first->workspace->name);
        Page::factory()->count(25)->create([
            'title' => 'Bounded result', 'workspace_uid' => $first->workspace_uid, 'owner_user_uid' => $owner->uid,
        ]);
        $this->actingAs($owner)->getJson('/navigation/pages?q=Bounded')->assertOk()->assertJsonCount(20, 'pages');
    }

    public function test_page_tools_offer_draft_recovery_and_clear_sharing_choices(): void
    {
        $user = User::factory()->create();
        $page = $this->pageFor($user, 'Editing navigation');
        $this->actingAs($user)->get(route('pages.show', $page))->assertOk()
            ->assertSee('data-editor-unsaved-guard', false)->assertSee('Copy draft')
            ->assertSee('Open current version')->assertSee('Share page')->assertSee('Technical details');
    }

    public function test_ai_connections_explains_scopes_and_keeps_token_creation_explicit(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('settings.mcp-tokens.index'))
            ->assertOk()->assertSee('Connect an AI client')->assertSee('Find pages')
            ->assertSee('Upload artifacts')->assertSee('Create token');
        $document = new \DOMDocument();
        @$document->loadHTML((string) $response->getContent());
        $links = (new \DOMXPath($document))->query('//nav[@id="primary-navigation"]/a[contains(@class,"is-active")]');
        $this->assertNotFalse($links);
        $this->assertCount(1, $links);
        $link = $links->item(0);
        $this->assertInstanceOf(\DOMElement::class, $link);
        $this->assertSame(route('settings.mcp-tokens.index'), $link->getAttribute('href'));
        $this->assertSame('page', $link->getAttribute('aria-current'));
    }

    public function test_inaccessible_favorites_cannot_permanently_fill_the_private_capacity(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $accessible = $this->pageFor($user, 'New accessible favorite');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Lost workspace');
        $pages = Page::factory()->count(200)->create(['workspace_uid' => $otherWorkspace->uid, 'owner_user_uid' => $owner->uid]);
        foreach ($pages as $page) {
            DB::table('personal_page_states')->insert([
                'uid' => (string) Str::ulid(), 'user_uid' => $user->uid, 'page_uid' => $page->uid, 'favorited_at' => now(),
            ]);
        }
        $this->actingAs($user)->putJson("/pages/{$accessible->uid}/favorite")->assertOk();
        $this->assertDatabaseCount('personal_page_states', 1);
    }

    private function pageFor(User $owner, string $title): Page
    {
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Workspace ' . Str::ulid());

        return app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: $title,
            description: null,
            content: '# ' . $title,
        ));
    }
}
