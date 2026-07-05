<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArtifactDraftPreviewHttpTest extends TestCase
{
    use RefreshDatabase;

    private const string DRAFT_URL = '/artifact-previews/draft';

    public function test_it_renders_unsaved_html_with_the_hardened_sandbox_response(): void
    {
        config(['app.runtime_role' => 'artifact-host']);

        $content = '<!doctype html><html><head><style>#result{color:rgb(1,2,3)}</style></head>'
            . '<body><p id="result">draft body</p><script>document.title = "ran"</script></body></html>';

        $response = $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])
            ->post(self::DRAFT_URL, ['content' => $content]);

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        // The draft preview renders unsaved, untrusted HTML, so it must carry the
        // same opaque-origin sandbox as saved artifacts: scripts allowed, but never
        // allow-same-origin (which would drop the artifact into the host origin).
        // The bounded regex fails if any extra token is appended to the directive.
        $this->assertMatchesRegularExpression('/(?:^|; )sandbox allow-scripts(?:;|$)/', $csp);
        $this->assertStringNotContainsString('allow-same-origin', $csp);
        $this->assertStringContainsString("script-src 'unsafe-inline'", $csp);
        $this->assertStringContainsString("style-src 'unsafe-inline'", $csp);
        $this->assertStringContainsString("connect-src 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
        $this->assertStringContainsString("form-action 'none'", $csp);

        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $body = $response->getContent();
        // Same guard the saved-artifact preview injects.
        $this->assertStringContainsString('data-artifactflow-preview-guard', (string) $body);
        // The unsaved content is rendered verbatim, inline style and script intact.
        $this->assertStringContainsString('#result{color:rgb(1,2,3)}', (string) $body);
        $this->assertStringContainsString('document.title = "ran"', (string) $body);
    }

    public function test_it_is_absent_outside_the_artifact_host_runtime(): void
    {
        config(['app.runtime_role' => 'app']);

        $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])
            ->post(self::DRAFT_URL, ['content' => '<p>draft</p>'])
            ->assertNotFound();
    }

    public function test_it_refuses_to_render_as_a_top_level_document(): void
    {
        config(['app.runtime_role' => 'artifact-host']);

        $response = $this->withHeaders(['Sec-Fetch-Dest' => 'document'])
            ->post(self::DRAFT_URL, ['content' => '<p>draft</p>']);

        $response->assertStatus(403);
        $this->assertStringContainsString(
            'can only be viewed inside ArtifactFlow',
            (string) $response->getContent(),
        );
    }

    public function test_top_level_recovery_notice_links_to_the_app_origin_not_the_artifact_host(): void
    {
        // Reproduce the artifact-host runtime: compose overwrites its APP_URL with the artifact
        // origin, so app.url here is the artifact host itself; only artifact_frame_ancestors
        // still carries the app origin. The draft recovery link must resolve there or "Open it
        // inside ArtifactFlow" 404s back on the artifact host.
        config([
            'app.runtime_role' => 'artifact-host',
            'app.artifact_frame_ancestors' => 'http://localhost:18080',
            'app.url' => 'http://localhost',
        ]);

        $response = $this->withHeaders(['Sec-Fetch-Dest' => 'document'])
            ->post(self::DRAFT_URL, ['content' => '<p>draft</p>']);

        $response->assertStatus(403);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('can only be viewed inside ArtifactFlow', $body);
        $this->assertStringContainsString('http://localhost:18080/pages/create', $body);
        $this->assertStringNotContainsString('http://localhost/pages/create', $body);
    }

    public function test_top_level_recovery_notice_tolerates_an_uppercase_frame_ancestors_scheme(): void
    {
        // The production boot gate accepts an uppercase scheme (OriginNormalizer lowercases
        // it), so a deployment can boot with ARTIFACT_FRAME_ANCESTORS=HTTPS://... The recovery
        // link must resolve to that same app origin -- normalised to lowercase -- instead of
        // falling back to app.url (the artifact host here), which would 404.
        config([
            'app.runtime_role' => 'artifact-host',
            'app.artifact_frame_ancestors' => 'HTTPS://app.example.test',
            'app.url' => 'https://artifacts.example.test',
        ]);

        $response = $this->withHeaders(['Sec-Fetch-Dest' => 'document'])
            ->post(self::DRAFT_URL, ['content' => '<p>draft</p>']);

        $response->assertStatus(403);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('https://app.example.test/pages/create', $body);
        $this->assertStringNotContainsString('artifacts.example.test', $body);
    }

    public function test_it_fails_closed_when_sec_fetch_dest_is_absent(): void
    {
        // The unsigned draft reflector must not be servable to a client that omits
        // Sec-Fetch-Dest (legacy browsers, header-stripping proxies): the legitimate
        // in-app iframe POST always sends Sec-Fetch-Dest: iframe, so requiring it
        // removes the top-level reflection surface without affecting real previews.
        config(['app.runtime_role' => 'artifact-host']);

        $this->post(self::DRAFT_URL, ['content' => '<p>draft</p>'])
            ->assertStatus(403);
    }

    public function test_it_rejects_empty_content(): void
    {
        config(['app.runtime_role' => 'artifact-host']);

        $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])
            ->post(self::DRAFT_URL, ['content' => "   \n\t "])
            ->assertStatus(422);
    }

    public function test_it_rejects_content_larger_than_the_html_limit(): void
    {
        config([
            'app.runtime_role' => 'artifact-host',
            'pages.max_html_bytes' => 64,
        ]);

        $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])
            ->post(self::DRAFT_URL, ['content' => str_repeat('a', 65)])
            ->assertStatus(422);
    }
}
