<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Tests\TestCase;

final class ArtifactDraftPreviewHttpTest extends TestCase
{
    use RefreshDatabase;

    private const string DRAFT_URL = '/artifact-previews/draft';

    public function test_unsigned_html_is_rejected_even_when_the_client_spoofs_an_iframe_request(): void
    {
        config(['app.runtime_role' => 'artifact-host']);
        $log = Log::spy();
        $this->assertInstanceOf(MockInterface::class, $log);

        $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])
            ->post(self::DRAFT_URL, [
                'content' => UploadedFile::fake()->createWithContent(
                    'draft.html',
                    '<script>document.title = "public reflector"</script>',
                ),
            ])
            ->assertNotFound();

        $log->shouldNotHaveReceived('warning');
        $log->shouldNotHaveReceived('info');
    }

    public function test_it_renders_content_bound_authorized_html_with_the_hardened_sandbox_response(): void
    {
        $content = '<!doctype html><html><head><style>#result{color:rgb(1,2,3)}</style></head>'
            . '<body><p id="result">draft body</p><script>document.title = "ran"</script></body></html>';
        $capability = $this->issueCapability($content);

        $response = $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])
            ->post(self::DRAFT_URL, $this->draftPayload($capability, $content));

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
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
        $this->assertStringContainsString('data-artifactflow-preview-guard', (string) $body);
        $this->assertStringContainsString('#result{color:rgb(1,2,3)}', (string) $body);
        $this->assertStringContainsString('document.title = "ran"', (string) $body);
    }

    public function test_a_capability_cannot_be_moved_to_different_html(): void
    {
        $capability = $this->issueCapability('<p>authorized content</p>');

        $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])
            ->post(self::DRAFT_URL, $this->draftPayload($capability, '<p>attacker replacement</p>'))
            ->assertNotFound();
    }

    public function test_a_capability_cannot_be_moved_to_a_different_artifact_origin(): void
    {
        $content = '<p>origin-bound content</p>';
        $capability = $this->issueCapability($content);
        config(['app.artifact_url' => 'https://other-artifacts.example.test']);

        $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])
            ->post(self::DRAFT_URL, $this->draftPayload($capability, $content))
            ->assertNotFound();
    }

    public function test_a_tampered_capability_is_rejected(): void
    {
        $content = '<p>authorized content</p>';
        $capability = $this->issueCapability($content);
        $lastByte = substr($capability, -1);
        $tampered = substr($capability, 0, -1) . ($lastByte === 'a' ? 'b' : 'a');

        $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])
            ->post(self::DRAFT_URL, $this->draftPayload($tampered, $content))
            ->assertNotFound();
    }

    public function test_an_expired_capability_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00'));

        try {
            $content = '<p>short lived</p>';
            $capability = $this->issueCapability($content);
            Carbon::setTestNow(Carbon::now()->addSeconds(61));

            $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])
                ->post(self::DRAFT_URL, $this->draftPayload($capability, $content))
                ->assertNotFound();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_is_absent_outside_the_artifact_host_runtime(): void
    {
        config(['app.runtime_role' => 'app']);

        $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])
            ->post(self::DRAFT_URL, $this->draftPayload('not-a-capability', '<p>draft</p>'))
            ->assertNotFound();
    }

    public function test_it_refuses_a_valid_capability_as_a_top_level_document(): void
    {
        $content = '<p>draft</p>';
        $capability = $this->issueCapability($content);

        $response = $this->withHeaders(['Sec-Fetch-Dest' => 'document'])
            ->post(self::DRAFT_URL, $this->draftPayload($capability, $content));

        $response->assertStatus(403);
        $this->assertStringContainsString(
            'can only be viewed inside ArtifactFlow',
            (string) $response->getContent(),
        );
    }

    public function test_top_level_recovery_notice_links_to_the_app_origin_not_the_artifact_host(): void
    {
        $content = '<p>draft</p>';
        $capability = $this->issueCapability($content);
        config([
            'app.runtime_role' => 'artifact-host',
            'app.artifact_frame_ancestors' => 'http://localhost:18080',
            'app.url' => 'http://localhost',
        ]);

        $response = $this->withHeaders(['Sec-Fetch-Dest' => 'document'])
            ->post(self::DRAFT_URL, $this->draftPayload($capability, $content));

        $response->assertStatus(403);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('can only be viewed inside ArtifactFlow', $body);
        $this->assertStringContainsString('http://localhost:18080/pages/create', $body);
        $this->assertStringNotContainsString('http://localhost/pages/create', $body);
    }

    public function test_top_level_recovery_notice_tolerates_an_uppercase_frame_ancestors_scheme(): void
    {
        $content = '<p>draft</p>';
        $capability = $this->issueCapability($content);
        config([
            'app.runtime_role' => 'artifact-host',
            'app.artifact_frame_ancestors' => 'HTTPS://app.example.test',
            'app.url' => 'https://artifacts.example.test',
        ]);

        $response = $this->withHeaders(['Sec-Fetch-Dest' => 'document'])
            ->post(self::DRAFT_URL, $this->draftPayload($capability, $content));

        $response->assertStatus(403);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('https://app.example.test/pages/create', $body);
        $this->assertStringNotContainsString('artifacts.example.test', $body);
    }

    public function test_it_fails_closed_when_sec_fetch_dest_is_absent(): void
    {
        $content = '<p>draft</p>';
        $capability = $this->issueCapability($content);

        $this->post(self::DRAFT_URL, $this->draftPayload($capability, $content))
            ->assertStatus(403);
    }

    public function test_it_rejects_empty_content_even_with_a_matching_capability(): void
    {
        $content = "   \n\t ";
        $capability = $this->issueCapability($content);

        $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])
            ->post(self::DRAFT_URL, $this->draftPayload($capability, $content))
            ->assertStatus(422);
    }

    public function test_it_preserves_the_exact_utf8_bytes_bound_to_the_capability(): void
    {
        $content = " \n<p>Žluťoučký 🧪</p>\n ";
        $capability = $this->issueCapability($content);

        $response = $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])
            ->post(self::DRAFT_URL, $this->draftPayload($capability, $content));

        $response->assertOk();
        $this->assertStringContainsString($content, (string) $response->getContent());
    }

    public function test_it_rejects_content_larger_than_the_current_html_limit(): void
    {
        config(['pages.max_html_bytes' => 65]);
        $content = str_repeat('a', 65);
        $capability = $this->issueCapability($content);
        config([
            'app.runtime_role' => 'artifact-host',
            'pages.max_html_bytes' => 64,
        ]);

        $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])
            ->post(self::DRAFT_URL, $this->draftPayload($capability, $content))
            ->assertStatus(422);
    }

    private function issueCapability(string $content): string
    {
        config(['app.runtime_role' => 'app']);

        $user = User::query()->create([
            'name' => 'Draft Preview Editor',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
        ]);
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Draft Preview Workspace');

        $response = $this->actingAs($user)->postJson('/pages/draft-preview-capabilities', [
            'workspace_uid' => $workspace->uid,
            'content_bytes' => strlen($content),
            'content_sha256' => hash('sha256', $content),
        ]);

        $response->assertOk();
        $capability = $response->json('capability');
        $this->assertIsString($capability);
        config(['app.runtime_role' => 'artifact-host']);

        return $capability;
    }

    /**
     * @return array{capability: string, content: UploadedFile}
     */
    private function draftPayload(string $capability, string $content): array
    {
        return [
            'capability' => $capability,
            'content' => UploadedFile::fake()->createWithContent('draft.html', $content),
        ];
    }
}
