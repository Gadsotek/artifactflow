<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
