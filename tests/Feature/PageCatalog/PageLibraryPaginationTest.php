<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageStatus;
use App\Models\Page;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class PageLibraryPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_can_reach_more_than_one_hundred_pages_and_preserves_filters(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Pagination workspace');
        for ($i = 0; $i < 106; ++$i) {
            Page::factory()->create([
                'title' => sprintf('Reference %03d', $i),
                'workspace_uid' => $workspace->uid,
                'owner_user_uid' => $user->uid,
            ]);
        }
        $url = "/pages?workspace_uid={$workspace->uid}&sort=title&type=html_artifact";
        $this->actingAs($user)->get($url)->assertOk()->assertSee('Reference 000')->assertSee('Reference 024')
            ->assertDontSee('Reference 025')->assertSee('Next pages')->assertDontSee('Previous pages');
        $this->get($url . '&page=5')->assertOk()->assertSee('Reference 100')->assertSee('Reference 105')
            ->assertDontSee('Reference 099')->assertSee('Previous pages')->assertDontSee('Next pages');
        $this->get($url . '&page[]=1')->assertSessionHasErrors('page');
    }

    public function test_unviewable_pages_do_not_create_a_next_page_or_disclose_titles(): void
    {
        $reader = User::factory()->create();
        $owner = User::factory()->create();
        $workspace = app(CreateSharedWorkspace::class)->handle($reader, 'Visible workspace');
        Page::factory()->count(25)->create(['workspace_uid' => $workspace->uid, 'owner_user_uid' => $reader->uid]);
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Inaccessible workspace');
        // Ownership is deliberately left behind after membership is lost: SQL
        // admits these candidates, but exact authority must exclude them.
        Page::factory()->count(30)->create([
            'title' => 'Hidden pagination marker', 'owner_user_uid' => $reader->uid,
            'workspace_uid' => $otherWorkspace->uid,
        ]);
        $this->actingAs($reader)->get('/pages?workspace_uid=all')->assertOk()
            ->assertDontSee('Hidden pagination marker')->assertDontSee('Next pages');
    }

    public function test_paginated_children_keep_authorized_parent_context_from_other_windows(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Tree pagination');
        $attributes = ['workspace_uid' => $workspace->uid, 'owner_user_uid' => $user->uid];
        for ($i = 0; $i < 24; ++$i) {
            Page::factory()->create([...$attributes, 'title' => sprintf('Reference %03d', $i)]);
        }
        $parent = Page::factory()->create([...$attributes, 'title' => 'Reference 024 parent']);
        $child = Page::factory()->create([...$attributes, 'title' => 'Reference 025 child', 'parent_page_uid' => $parent->uid]);
        $grandchild = Page::factory()->create([...$attributes, 'title' => 'Reference 026 grandchild', 'parent_page_uid' => $child->uid]);
        $url = "/pages?workspace_uid={$workspace->uid}&sort=title";

        $response = $this->actingAs($user)->get($url . '&page=2')->assertOk();
        $this->assertHierarchyRow($response, $child, 1, $parent->title);
        $this->assertHierarchyRow($response, $grandchild, 2, $child->title);
        $response->assertDontSee('Next pages');

        // A child may sort before a parent which appears in the next window.
        $child->update(['title' => 'A child before its parent']);
        $response = $this->get($url)->assertOk();
        $this->assertHierarchyRow($response, $child, 1, $parent->title);
        $response->assertSee('Next pages');
    }

    public function test_off_window_ancestors_do_not_disclose_inaccessible_pages(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Private ancestor context');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid, 'user_uid' => $reader->uid, 'role' => WorkspaceRole::Reader,
        ]);
        $attributes = ['workspace_uid' => $workspace->uid, 'owner_user_uid' => $owner->uid];
        $hidden = Page::factory()->create([...$attributes, 'title' => 'Hidden ancestor marker', 'access_mode' => PageAccessMode::Restricted]);
        $child = Page::factory()->create([...$attributes, 'title' => 'Visible child', 'parent_page_uid' => $hidden->uid]);
        $response = $this->actingAs($reader)->get("/pages?workspace_uid={$workspace->uid}&sort=title")->assertOk();
        $this->assertHierarchyRow($response, $child, 0, null);
        $response->assertDontSee($hidden->title)->assertDontSee($hidden->uid);
    }

    public function test_live_catalog_fragment_includes_updated_pagination_when_a_page_is_added(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Live pagination');
        $attributes = ['workspace_uid' => $workspace->uid, 'owner_user_uid' => $user->uid];
        Page::factory()->count(25)->create($attributes);
        $url = "/pages?workspace_uid={$workspace->uid}&sort=title";
        $this->actingAs($user)->get($url)->assertOk()->assertDontSee('Next pages');
        Page::factory()->create($attributes);
        $response = $this->get($url, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $document = new \DOMDocument();
        @$document->loadHTML((string) $response->getContent());
        $nextLinks = (new \DOMXPath($document))->query('//*[@data-live-page-catalog]//nav[@aria-label="Library pagination"]/a[contains(., "Next pages")]');
        $this->assertNotFalse($nextLinks);
        $this->assertCount(1, $nextLinks);
    }

    public function test_ancestor_context_terminates_on_cycles_outside_the_result_window(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Ancestor boundaries');
        $attributes = ['workspace_uid' => $workspace->uid, 'owner_user_uid' => $user->uid];
        $first = Page::factory()->create([...$attributes, 'status' => PageStatus::Archived]);
        $second = Page::factory()->create([...$attributes, 'status' => PageStatus::Archived, 'parent_page_uid' => $first->uid]);
        $first->update(['parent_page_uid' => $second->uid]);
        $child = Page::factory()->create([...$attributes, 'parent_page_uid' => $second->uid]);
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($user, 'Other ancestor workspace');
        $foreign = Page::factory()->create(['workspace_uid' => $otherWorkspace->uid, 'owner_user_uid' => $user->uid]);

        $response = $this->actingAs($user)->get("/pages?workspace_uid={$workspace->uid}&sort=title")->assertOk();
        $this->assertHierarchyRow($response, $child, 0, null);
        $response->assertDontSee($first->title)->assertDontSee($second->title)->assertDontSee($foreign->title);
    }

    public function test_active_filter_links_remove_one_group_preserve_scope_and_restart_pagination(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Filter workspace');
        $response = $this->actingAs($user)->get('/pages?' . http_build_query([
            'workspace_uid' => $workspace->uid, 'q' => 'reference', 'sort' => 'title',
            'type' => 'html_artifact', 'statuses' => ['archived'], 'page' => 3,
        ]))->assertOk()->assertSee('HTML artifact');
        $document = new \DOMDocument();
        @$document->loadHTML((string) $response->getContent());
        $links = (new \DOMXPath($document))->query('//a[@data-remove-filter="type"]');
        $this->assertNotFalse($links);
        $this->assertCount(1, $links);
        $link = $links->item(0);
        $this->assertInstanceOf(\DOMElement::class, $link);
        $href = $link->getAttribute('href');
        $query = parse_url($href, PHP_URL_QUERY);
        $this->assertIsString($query);
        parse_str($query, $parameters);
        $this->assertArrayNotHasKey('type', $parameters);
        $this->assertArrayNotHasKey('page', $parameters);
        $this->assertSame($workspace->uid, $parameters['workspace_uid']);
        $this->assertSame('reference', $parameters['q']);
        $this->assertSame('title', $parameters['sort']);
        $this->assertSame(['archived'], $parameters['statuses']);
    }

    /** @param TestResponse<Response> $response */
    private function assertHierarchyRow(TestResponse $response, Page $page, int $depth, ?string $parentTitle): void
    {
        $document = new \DOMDocument();
        @$document->loadHTML((string) $response->getContent());
        $rows = (new \DOMXPath($document))->query('//a[@data-page-uid="' . $page->uid . '"]');
        $this->assertNotFalse($rows);
        $this->assertCount(1, $rows);
        $row = $rows->item(0);
        $this->assertInstanceOf(\DOMElement::class, $row);
        $this->assertSame((string) $depth, $row->getAttribute('data-page-hierarchy-depth'));
        if ($parentTitle === null) {
            $this->assertStringNotContainsString('Under ', $row->textContent);
        } else {
            $this->assertStringContainsString('Under ' . $parentTitle, $row->textContent);
        }
    }
}
