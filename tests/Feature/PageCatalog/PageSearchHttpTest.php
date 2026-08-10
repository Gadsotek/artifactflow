<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\PageSearchVectorUpdater;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Application\Provenance\ProducerAssertionInput;
use App\Application\Provenance\VersionProvenanceInput;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\Provenance\ProducerKind;
use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageSearchHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_array_valued_provenance_scope_is_rejected_as_invalid_input(): void
    {
        $editor = $this->createUser('Malformed Search User', 'malformed-search@example.test');
        app(CreateSharedWorkspace::class)->handle($editor, 'Malformed Search Team');

        $this->actingAs($editor)
            ->get('/pages?provenance_scope[]=any_version')
            ->assertUnprocessable();
    }

    public function test_page_list_ranks_title_matches_ahead_of_tag_and_body_matches(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');

        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Body Mention',
            description: 'Atlas appears only inside the source body.',
            content: '# Runtime Notes' . PHP_EOL . PHP_EOL . 'Atlas appears in the extracted body text.',
        ));
        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Tagged Reference',
            description: 'A page found through metadata.',
            content: '# Tagged Reference',
            tagNames: ['Atlas'],
        ));
        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Atlas Title Match',
            description: 'The best title match.',
            content: '# Atlas Title Match',
        ));

        $this->actingAs($editor)
            ->get("/pages?workspace_uid={$workspace->uid}&q=Atlas")
            ->assertOk()
            ->assertSeeInOrder([
                'Atlas Title Match',
                'Tagged Reference',
                'Body Mention',
            ]);
    }

    public function test_page_list_uses_postgresql_full_text_search_across_content_and_context_metadata(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Avery Operator', 'avery@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Flight Operations');
        $category = $this->createCategory($workspace, $owner, 'Runbooks');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Service Checklist',
            description: 'Routine release procedure.',
            content: '<!doctype html><html><body><h1>Runtime</h1><p>The service was deployed safely.</p></body></html>',
            status: PageStatus::Approved,
            categoryUid: $category->uid,
            tagNames: ['Release'],
        ));

        foreach (['deployed', 'flight', 'avery', 'runbooks', 'html artifact', 'approved'] as $query) {
            $this->actingAs($owner)
                ->get('/pages?workspace_uid=all&q=' . urlencode($query))
                ->assertOk()
                ->assertSee($page->title);
        }
    }

    public function test_page_list_searches_against_the_maintained_page_search_vector(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Avery Operator', 'avery-vector@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Vector Operations');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Indexed Artifact',
            description: 'Safe summary.',
            content: '# Indexed Artifact' . PHP_EOL . PHP_EOL . 'contentonlyneedle',
        ));

        $this->actingAs($owner)
            ->get('/pages?workspace_uid=all&q=contentonlyneedle')
            ->assertOk()
            ->assertSee($page->title);

        DB::table('pages')
            ->where('uid', $page->uid)
            ->update(['search_vector' => DB::raw("''::tsvector")]);

        $this->actingAs($owner)
            ->get('/pages?workspace_uid=all&q=contentonlyneedle')
            ->assertOk()
            ->assertDontSee($page->title)
            ->assertSee('No pages found');

        app(PageSearchVectorUpdater::class)->refreshPage($page->uid);

        $this->actingAs($owner)
            ->get('/pages?workspace_uid=all&q=contentonlyneedle')
            ->assertOk()
            ->assertSee($page->title);
    }

    public function test_page_list_finds_literal_tokens_that_exist_only_inside_artifact_script_source(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Artifact Owner', 'artifact-source@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Artifact Source Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Script Only Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Runtime</h1><script>const data={code:"EXENODED"};</script></body></html>',
        ));

        $this->actingAs($owner)
            ->get('/pages?workspace_uid=all&q=EXENODED')
            ->assertOk()
            ->assertSee($page->title);
    }

    public function test_visible_text_matches_rank_ahead_of_source_only_matches(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Ranking Owner', 'source-ranking@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Source Ranking Team');
        $visiblePage = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Visible Widget Page',
            description: null,
            content: '# Visible Widget Page' . PHP_EOL . PHP_EOL . 'widgetterm is visible body text.',
        ));
        $sourceOnlyPage = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Source Only Widget Page',
            description: null,
            content: '<!doctype html><html><body><h1>Runtime</h1><script>const hidden="widgetterm";</script></body></html>',
        ));

        $this->actingAs($owner)
            ->get('/pages?workspace_uid=all&q=widgetterm')
            ->assertOk()
            ->assertSeeInOrder([
                $visiblePage->title,
                $sourceOnlyPage->title,
            ]);
    }

    public function test_source_text_search_preserves_page_authorization_boundaries(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Source Owner', 'source-owner@example.test');
        $outsider = $this->createUser('Source Outsider', 'source-outsider@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Private Source Team');
        // The outsider stays outside the page workspace, so it can only reach the
        // page explicitly granted to it, not the rest of the source workspace.
        $sharedWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Shared Source Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $sharedWorkspace->uid,
            'user_uid' => $outsider->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $privatePage = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Private Source Token',
            description: null,
            content: '<!doctype html><html><body><h1>Private</h1><script>const token="sourcetenantneedle";</script></body></html>',
        ));
        $grantedPage = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Granted Source Token',
            description: null,
            content: '<!doctype html><html><body><h1>Granted</h1><script>const token="sourcetenantneedle";</script></body></html>',
        ));

        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $grantedPage->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $outsider->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->actingAs($outsider)
            ->get('/pages?workspace_uid=all&q=sourcetenantneedle')
            ->assertOk()
            ->assertSee($grantedPage->title)
            ->assertDontSee($privatePage->title)
            ->assertSee('Private Source Team')
            ->assertSee('Page access');
    }

    public function test_source_only_matches_do_not_reflect_artifact_markup_into_result_snippets(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Snippet Owner', 'source-snippet@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Snippet Source Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Source Snippet Artifact',
            description: 'Safe source-only summary.',
            content: '<!doctype html><html><body><h1>Snippet</h1><script>/*needlephrase*/ alert("x")</script></body></html>',
        ));

        $this->actingAs($owner)
            ->get('/pages?workspace_uid=all&q=needlephrase')
            ->assertOk()
            ->assertSee($page->title)
            ->assertSee('Safe source-only summary.')
            ->assertDontSee('/*needlephrase*/ alert', false)
            ->assertDontSee('<script>/*needlephrase*/', false);
    }

    public function test_null_description_source_only_matches_do_not_use_source_text_as_snippets(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Null Snippet Owner', 'null-source-snippet@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Null Snippet Source Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Null Description Source Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Snippet</h1><script>const unrenderedSnippetLeak = "cagesnippetpayload";</script></body></html>',
        ));

        $this->actingAs($owner)
            ->get('/pages?workspace_uid=all&q=cagesnippetpayload')
            ->assertOk()
            ->assertSee($page->title)
            ->assertDontSee('unrenderedSnippetLeak')
            ->assertDontSee('script const unrenderedSnippetLeak', false)
            ->assertDontSee('<script>', false);
    }

    public function test_search_results_show_required_context_without_exposing_private_content(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Avery Operator', 'avery@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Flight Operations');
        $category = $this->createCategory($workspace, $owner, 'Runbooks');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Release Runbook',
            description: 'Safe public summary.',
            content: '# Release Runbook' . PHP_EOL . PHP_EOL . 'Private body phrase.',
            status: PageStatus::Approved,
            categoryUid: $category->uid,
            tagNames: ['Release'],
        ));
        $page->forceFill(['status' => PageStatus::Deprecated])->save();
        $this->assertNotNull($page->updated_at);

        $this->actingAs($owner)
            ->get("/pages?workspace_uid={$workspace->uid}&q=Release")
            ->assertOk()
            ->assertSee($page->title)
            ->assertSee('Safe public summary.')
            ->assertSee('Flight Operations')
            ->assertSee('Runbooks')
            ->assertSee('Avery Operator')
            ->assertSee('Deprecated')
            ->assertSee($page->updated_at->toDateString())
            ->assertDontSee('Private body phrase.');
    }

    public function test_page_list_filters_by_workspace_type_multi_status_category_tag_and_owner_uid(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $otherOwner = $this->createUser('Other Owner', 'other-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($editor, 'Research Team');
        $category = $this->createCategory($workspace, $editor, 'Runbooks');

        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $otherOwner->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);

        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Filtered Runbook',
            description: 'This page should survive every filter.',
            content: '# Filtered Runbook',
            status: PageStatus::Approved,
            categoryUid: $category->uid,
            ownerUserUid: $editor->uid,
            tagNames: ['Release'],
        ));

        $releaseTag = Tag::query()->where('slug', 'release')->sole();

        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'HTML Artifact',
            description: null,
            content: '<!doctype html><html><body><h1>Artifact</h1></body></html>',
            status: PageStatus::Approved,
            categoryUid: $category->uid,
            tagNames: ['Release'],
        ));
        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Draft Runbook',
            description: null,
            content: '# Draft Runbook',
            status: PageStatus::Draft,
            categoryUid: $category->uid,
            tagNames: ['Release'],
        ));
        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Other Owner Runbook',
            description: null,
            content: '# Other Owner Runbook',
            status: PageStatus::Approved,
            categoryUid: $category->uid,
            ownerUserUid: $otherOwner->uid,
            tagNames: ['Release'],
        ));
        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $otherWorkspace->uid,
            type: PageType::Markdown,
            title: 'Other Workspace Runbook',
            description: null,
            content: '# Other Workspace Runbook',
            status: PageStatus::Approved,
            tagNames: ['Release'],
        ));

        $this->actingAs($editor)
            ->get(sprintf(
                '/pages?workspace_uid=%s&type=markdown&statuses[]=%s&category_uids[]=%s&tag_uids[]=%s&owner_user_uid=%s',
                $workspace->uid,
                PageStatus::Approved->value,
                $category->uid,
                $releaseTag->uid,
                $editor->uid,
            ))
            ->assertOk()
            ->assertSee('Filtered Runbook')
            ->assertDontSee('HTML Artifact')
            ->assertDontSee('Draft Runbook')
            ->assertDontSee('Other Owner Runbook')
            ->assertDontSee('Other Workspace Runbook');
    }

    public function test_page_list_multi_select_filters_match_any_selected_tag_and_keep_active_tags_visible(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');

        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Release Security Runbook',
            description: null,
            content: '# Release Security Runbook',
            tagNames: ['Release', 'Security'],
        ));
        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Release Only Runbook',
            description: null,
            content: '# Release Only Runbook',
            tagNames: ['Release'],
        ));
        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Security Only Runbook',
            description: null,
            content: '# Security Only Runbook',
            tagNames: ['Security'],
        ));

        $releaseTag = Tag::query()->where('slug', 'release')->sole();
        $securityTag = Tag::query()->where('slug', 'security')->sole();

        $response = $this->actingAs($editor)
            ->get(sprintf(
                '/pages?workspace_uid=%s&tag_uids[]=%s&tag_uids[]=%s',
                $workspace->uid,
                $releaseTag->uid,
                $securityTag->uid,
            ))
            ->assertOk()
            ->assertSee('Release Security Runbook')
            ->assertSee('Release Only Runbook')
            ->assertSee('Security Only Runbook');

        $response->assertSee(sprintf('value="%s" selected', $releaseTag->uid), false);
        $response->assertSee(sprintf('value="%s" selected', $securityTag->uid), false);
    }

    public function test_page_list_multi_select_categories_and_statuses_match_any_selected_value(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Facet Editor', 'facet-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Facet Team');
        $runbooks = $this->createCategory($workspace, $editor, 'Runbooks');
        $design = $this->createCategory($workspace, $editor, 'Design');
        $excluded = $this->createCategory($workspace, $editor, 'Excluded');

        foreach ([
            ['Draft Runbook', $runbooks, PageStatus::Draft],
            ['Approved Design', $design, PageStatus::Approved],
            ['Excluded Draft', $excluded, PageStatus::Draft],
        ] as [$title, $category, $status]) {
            app(CreatePage::class)->handle($editor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Markdown,
                title: $title,
                description: null,
                content: '# ' . $title,
                status: $status,
                categoryUid: $category->uid,
            ));
        }

        $this->actingAs($editor)
            ->get(sprintf(
                '/pages?workspace_uid=%s&category_uids[]=%s&category_uids[]=%s&statuses[]=draft&statuses[]=approved',
                $workspace->uid,
                $runbooks->uid,
                $design->uid,
            ))
            ->assertOk()
            ->assertSee('Draft Runbook')
            ->assertSee('Approved Design')
            ->assertDontSee('Excluded Draft')
            ->assertSee(sprintf('value="%s" selected', $runbooks->uid), false)
            ->assertSee(sprintf('value="%s" selected', $design->uid), false);
    }

    public function test_page_list_filters_ai_provenance_without_disclosing_inaccessible_pages(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Provenance Searcher', 'provenance-searcher@example.test');
        $otherOwner = $this->createUser('Other Provenance Owner', 'other-provenance-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Visible Provenance Team');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($otherOwner, 'Hidden Provenance Team');
        $provenance = new VersionProvenanceInput([
            new ProducerAssertionInput(
                kind: ProducerKind::Ai,
                producerName: null,
                producerVersion: null,
                providerKey: 'anthropic',
                modelId: 'claude-opus-5-2-20260715',
                modelLabel: 'Claude Opus 5.2',
                modelVersion: '20260715',
                generatedAt: null,
                references: [],
            ),
        ]);

        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Visible Opus Artifact',
            description: null,
            content: '# Visible',
            provenance: $provenance,
        ));
        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Visible Human Artifact',
            description: null,
            content: '# Human',
        ));
        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Visible Partial GPT Artifact',
            description: null,
            content: '# Partial GPT',
            provenance: new VersionProvenanceInput([
                new ProducerAssertionInput(
                    kind: ProducerKind::Ai,
                    producerName: null,
                    producerVersion: null,
                    providerKey: 'openai',
                    modelId: null,
                    modelLabel: 'GPT-5 family',
                    modelVersion: null,
                    generatedAt: null,
                    references: [],
                    reportedProvider: 'OpenAI',
                ),
            ]),
        ));
        app(CreatePage::class)->handle($otherOwner, new CreatePageCommand(
            workspaceUid: $otherWorkspace->uid,
            type: PageType::Markdown,
            title: 'Hidden Opus Artifact',
            description: null,
            content: '# Hidden',
            provenance: $provenance,
        ));

        $this->actingAs($editor)
            ->get('/pages?workspace_uid=all&ai_providers[]=anthropic&ai_model_ids[]=claude-opus-5-2-20260715&provenance_scope=page_origin')
            ->assertOk()
            ->assertSee('Visible Opus Artifact')
            ->assertDontSee('Visible Human Artifact')
            ->assertDontSee('Hidden Opus Artifact')
            ->assertSee('name="ai_providers[]"', false)
            ->assertSee('name="ai_model_ids[]"', false)
            ->assertSee('value="anthropic" selected', false)
            ->assertSee('value="claude-opus-5-2-20260715" selected', false)
            ->assertSee('Claude Opus 5.2 — claude-opus-5-2-20260715')
            ->assertSee('name="provenance_scope"', false);

        $this->actingAs($editor)
            ->get('/pages?' . http_build_query([
                'workspace_uid' => 'all',
                'ai_providers' => ['openai'],
                'ai_model_ids' => ['GPT-5 family'],
                'provenance_scope' => 'page_origin',
            ]))
            ->assertOk()
            ->assertSee('Visible Partial GPT Artifact')
            ->assertDontSee('Visible Human Artifact')
            ->assertSee('OpenAI')
            ->assertSee('value="GPT-5 family" selected', false);

        $this->actingAs($editor)
            ->get('/pages?workspace_uid=all&q=Opus')
            ->assertOk()
            ->assertSee('Visible Opus Artifact')
            ->assertDontSee('Visible Human Artifact')
            ->assertDontSee('Hidden Opus Artifact');

        $this->actingAs($editor)
            ->get('/pages?workspace_uid=all&q=20260715')
            ->assertOk()
            ->assertDontSee('Visible Opus Artifact');
    }

    public function test_provenance_facets_deduplicate_historical_assertions_in_sql(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Facet Provenance Editor', 'facet-provenance@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Facet Provenance Team');
        $provenance = new VersionProvenanceInput([
            new ProducerAssertionInput(
                kind: ProducerKind::Ai,
                producerName: null,
                producerVersion: null,
                providerKey: 'openai',
                modelId: 'gpt-5.2',
                modelLabel: 'GPT-5.2',
                modelVersion: null,
                generatedAt: null,
                references: [],
                reportedProvider: 'OpenAI',
            ),
        ]);
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Versioned Provenance Facet',
            description: null,
            content: '# Version one',
            provenance: $provenance,
        ));
        $baseVersionUid = $page->current_version_uid;

        foreach ([2, 3] as $version) {
            $createdVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: '# Version ' . $version,
                baseVersionUid: $baseVersionUid,
                provenance: $provenance,
            ));
            $baseVersionUid = $createdVersion->uid;
        }

        /** @var list<string> $facetQueries */
        $facetQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$facetQueries): void {
            $sql = strtolower($query->sql);

            if (
                str_contains($sql, 'from "producer_assertions"')
                && str_contains($sql, 'join "page_version_ingests"')
            ) {
                $facetQueries[] = $sql;
            }
        });

        $response = $this->actingAs($editor)
            ->get("/pages?workspace_uid={$workspace->uid}")
            ->assertOk();
        $content = $response->getContent();

        $this->assertIsString($content);
        $this->assertSame(1, substr_count($content, 'value="openai"'));
        $this->assertSame(1, substr_count($content, 'value="gpt-5.2"'));
        $this->assertNotEmpty($facetQueries);

        foreach ($facetQueries as $query) {
            $this->assertStringContainsString('select distinct', $query);
        }
    }

    public function test_cross_workspace_filters_use_global_tags_and_qualified_authorized_categories(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Taxonomy Editor', 'taxonomy-editor@example.test');
        $outsider = $this->createUser('Taxonomy Outsider', 'taxonomy-outsider@example.test');
        $platformWorkspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $researchWorkspace = app(CreateSharedWorkspace::class)->handle($editor, 'Research Team');
        $hiddenWorkspace = app(CreateSharedWorkspace::class)->handle($outsider, 'Hidden Team');
        $platformCategory = $this->createCategory($platformWorkspace, $editor, 'Runbooks');
        $researchCategory = $this->createCategory($researchWorkspace, $editor, 'Runbooks');
        $this->createCategory($platformWorkspace, $editor, 'Empty but Available');
        $hiddenCategory = $this->createCategory($hiddenWorkspace, $outsider, 'Secret Category');
        $this->createCategory($hiddenWorkspace, $outsider, 'Hidden Empty Category');

        foreach ([
            [$editor, $platformWorkspace, $platformCategory, 'Platform Notes', 'codex'],
            [$editor, $researchWorkspace, $researchCategory, 'Research Notes', 'codex'],
            [$outsider, $hiddenWorkspace, $hiddenCategory, 'Hidden Notes', 'secret-taxonomy'],
        ] as [$actor, $workspace, $category, $title, $tag]) {
            app(CreatePage::class)->handle($actor, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Markdown,
                title: $title,
                description: null,
                content: '# Notes',
                categoryUid: $category->uid,
                tagNames: [$tag],
            ));
        }

        $allVisible = $this->actingAs($editor)
            ->get('/pages?workspace_uid=all')
            ->assertOk()
            ->assertSee('Runbooks — Platform Team')
            ->assertSee('Runbooks — Research Team')
            ->assertSee('Empty but Available — Platform Team')
            ->assertDontSee('Secret Category')
            ->assertDontSee('Hidden Empty Category')
            ->assertDontSee('secret-taxonomy')
            ->assertSee('data-library-workspace-filter', false)
            ->assertSee('name="category_uids[]"', false)
            ->assertSee('name="tag_uids[]"', false)
            ->assertSee('data-searchable-multi-select', false);

        $responseContent = $allVisible->getContent();
        $this->assertIsString($responseContent);
        $this->assertSame(1, substr_count($responseContent, '>codex</option>'));

        $this->actingAs($editor)
            ->get("/pages?workspace_uid={$platformWorkspace->uid}")
            ->assertOk()
            ->assertSee('>Runbooks</option>', false)
            ->assertDontSee('Runbooks — Platform Team')
            ->assertDontSee('Runbooks — Research Team')
            ->assertDontSee('data-library-workspace-filter', false)
            ->assertSee('name="workspace_uid" type="hidden"', false);
    }

    public function test_archived_pages_are_hidden_by_default_and_can_be_explicitly_included(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');

        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Live Archive Notes',
            description: null,
            content: '# Live Archive Notes',
        ));
        $archivedPage = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Archived Search Notes',
            description: null,
            content: '# Archived Search Notes',
        ));
        $archivedPage->forceFill(['status' => PageStatus::Archived])->save();

        $this->actingAs($editor)
            ->get("/pages?workspace_uid={$workspace->uid}&q=Notes")
            ->assertOk()
            ->assertSee('Live Archive Notes')
            ->assertDontSee('Archived Search Notes');

        $this->actingAs($editor)
            ->get("/pages?workspace_uid={$workspace->uid}&q=Notes&statuses[]=archived")
            ->assertOk()
            ->assertDontSee('Live Archive Notes')
            ->assertSee('Archived Search Notes');

        $this->actingAs($editor)
            ->get("/pages?workspace_uid={$workspace->uid}&q=Notes&statuses[]=draft&statuses[]=archived")
            ->assertOk()
            ->assertSee('Live Archive Notes')
            ->assertSee('Archived Search Notes')
            ->assertSee('name="statuses[]"', false)
            ->assertDontSee('name="include_archived"', false);
    }

    public function test_page_list_can_sort_visible_results_by_title_or_recent_update(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');

        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Alpha Runbook',
            description: null,
            content: '# Alpha',
        ));
        $this->travel(1)->second();
        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Zebra Runbook',
            description: null,
            content: '# Zebra',
        ));

        $this->actingAs($editor)
            ->get("/pages?workspace_uid={$workspace->uid}&sort=title")
            ->assertOk()
            ->assertSeeInOrder(['Alpha Runbook', 'Zebra Runbook'])
            ->assertSee('value="title">Title</button>', false)
            ->assertSee('data-sort-active="true"', false)
            ->assertDontSee('<select class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="sort">', false);

        $this->actingAs($editor)
            ->get("/pages?workspace_uid={$workspace->uid}&sort=recently_updated")
            ->assertOk()
            ->assertSeeInOrder(['Zebra Runbook', 'Alpha Runbook'])
            ->assertSee('value="recently_updated">Recently updated</button>', false)
            ->assertSee('data-library-sort-switches', false);
    }

    public function test_page_list_nests_visible_descendants_beneath_their_parent(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Hierarchy Editor', 'hierarchy-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Hierarchy Team');
        $parent = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'C Parent',
            description: null,
            content: '# Parent',
        ));
        $child = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'A Child',
            description: null,
            content: '# Child',
            parentPageUid: $parent->uid,
        ));
        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'B Grandchild',
            description: null,
            content: '# Grandchild',
            parentPageUid: $child->uid,
        ));
        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'D Standalone',
            description: null,
            content: '# Standalone',
        ));

        $this->actingAs($editor)
            ->get("/pages?workspace_uid={$workspace->uid}&sort=title")
            ->assertOk()
            ->assertSeeInOrder([
                'C Parent',
                'Under C Parent',
                'A Child',
                'Under A Child',
                'B Grandchild',
                'D Standalone',
            ])
            ->assertSee('data-page-hierarchy-depth="1"', false)
            ->assertSee('data-page-hierarchy-depth="2"', false);
    }

    public function test_page_list_does_not_disclose_a_hidden_parent_through_hierarchy_styling(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Hierarchy Owner', 'hierarchy-owner@example.test');
        $reader = $this->createUser('Hierarchy Reader', 'hierarchy-reader@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Private Hierarchy');
        $sharedWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Shared Hierarchy');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $sharedWorkspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $hiddenParent = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Hidden Parent Name',
            description: null,
            content: '# Hidden Parent',
        ));
        $sharedChild = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $sourceWorkspace->uid,
            type: PageType::Markdown,
            title: 'Visible Child Result',
            description: null,
            content: '# Visible Child',
            parentPageUid: $hiddenParent->uid,
        ));

        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $sharedChild->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $reader->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->actingAs($reader)
            ->get('/pages?workspace_uid=all')
            ->assertOk()
            ->assertSee('Visible Child Result')
            ->assertDontSee('Hidden Parent Name')
            ->assertSee('data-page-hierarchy-depth="0"', false)
            ->assertDontSee('af-library-page-child', false);
    }

    public function test_search_only_returns_pages_the_actor_can_view_including_direct_page_grants(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $outsider = $this->createUser('Outside User', 'outside@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Security Team');
        $privateCategory = $this->createCategory($workspace, $owner, 'Private Taxonomy');
        $grantedCategory = $this->createCategory($workspace, $owner, 'Granted Taxonomy');
        $this->createCategory($workspace, $owner, 'Unused Private Taxonomy');
        // The outsider stays outside the page workspace, so it can only reach the
        // page explicitly granted to it, not the rest of the workspace.
        $sharedWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Shared Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $sharedWorkspace->uid,
            'user_uid' => $outsider->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Private Search Result',
            description: null,
            content: '# Private Search Result',
            categoryUid: $privateCategory->uid,
            tagNames: ['private-taxonomy-tag'],
        ));
        $sharedPage = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Granted Search Result',
            description: null,
            content: '# Granted Search Result',
            categoryUid: $grantedCategory->uid,
            tagNames: ['granted-taxonomy-tag'],
        ));

        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $sharedPage->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $outsider->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->actingAs($outsider)
            ->get('/pages?workspace_uid=all&q=Search%20Result')
            ->assertOk()
            ->assertSee('Granted Search Result')
            ->assertDontSee('Private Search Result')
            ->assertSee('Security Team')
            ->assertSee('Granted Taxonomy — Security Team')
            ->assertSee('granted-taxonomy-tag')
            ->assertDontSee('Private Taxonomy')
            ->assertDontSee('Unused Private Taxonomy')
            ->assertDontSee('private-taxonomy-tag')
            ->assertSee('Page access');

        // A page-only grant makes its source workspace a useful library scope,
        // without turning the grantee into a workspace member or exposing any
        // other page from that workspace.
        $this->actingAs($outsider)
            ->get("/pages?workspace_uid={$workspace->uid}")
            ->assertOk()
            ->assertSee('Granted Search Result')
            ->assertDontSee('Private Search Result')
            ->assertSee('>Granted Taxonomy</option>', false)
            ->assertSee('granted-taxonomy-tag')
            ->assertDontSee('Private Taxonomy')
            ->assertDontSee('Unused Private Taxonomy')
            ->assertDontSee('private-taxonomy-tag');
    }

    public function test_unknown_uid_filters_return_an_empty_authorized_result_set_without_errors(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Visible Runbook',
            description: null,
            content: '# Visible Runbook',
        ));

        $this->actingAs($editor)
            ->get('/pages?workspace_uid=01K00000000000000000000000&tag_uid=01K00000000000000000000001')
            ->assertOk()
            ->assertDontSee('Visible Runbook')
            ->assertSee('No pages found');
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
            'slug' => str($name)->slug()->toString(),
            'created_by_user_uid' => $creator->uid,
        ]);
    }
}
