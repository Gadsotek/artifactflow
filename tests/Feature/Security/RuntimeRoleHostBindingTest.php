<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Application\PageCatalog\ArtifactDraftPreviewCapabilities;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The runtime-role surface split (EnforceRuntimeRoleSurface) must bind each role
 * to its configured origin *host*, not only to its route paths. Otherwise an
 * operator who misroutes the artifact hostname to the app service (or pools both
 * services behind it) would have the app runtime answer login/MCP/admin routes on
 * the artifact hostname and mint a host-only session cookie scoped to it, which a
 * later request to the real artifact host would then carry -- collapsing the
 * two-origin boundary the whole product depends on.
 *
 * The host is set through an absolute request URL (getHost() reads it directly);
 * trusted-host validation is disabled under unit tests, so any host resolves.
 */
final class RuntimeRoleHostBindingTest extends TestCase
{
    private const string APP_ORIGIN = 'https://app.example.internal';

    private const string ARTIFACT_ORIGIN = 'https://artifacts.example.internal';

    private function configureOrigins(string $role): void
    {
        config([
            'app.url' => self::APP_ORIGIN,
            'app.artifact_url' => self::ARTIFACT_ORIGIN,
            'app.artifact_frame_ancestors' => self::APP_ORIGIN,
            'app.runtime_role' => $role,
        ]);
    }

    public function test_app_runtime_refuses_application_routes_on_the_artifact_hostname(): void
    {
        $this->configureOrigins('app');

        $sessionCookie = config('session.cookie');
        $this->assertIsString($sessionCookie);

        foreach (['/login', '/dashboard', '/admin/users'] as $path) {
            $response = $this->get(self::ARTIFACT_ORIGIN . $path);

            $response->assertNotFound();
            $response->assertCookieMissing($sessionCookie);
        }
    }

    public function test_app_runtime_refuses_mcp_on_the_artifact_hostname(): void
    {
        $this->configureOrigins('app');

        $this
            ->postJson(self::ARTIFACT_ORIGIN . '/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertNotFound();
    }

    public function test_app_runtime_serves_application_routes_on_the_application_hostname(): void
    {
        $this->configureOrigins('app');

        $this->get(self::APP_ORIGIN . '/login')->assertOk();
    }

    public function test_health_probe_is_exempt_from_host_binding_under_app_role(): void
    {
        $this->configureOrigins('app');

        // Docker probes /up on the container's loopback address, not the public
        // origin host, so the stateless health route must stay reachable there.
        $this->get('http://127.0.0.1/up')->assertOk();
    }

    public function test_artifact_runtime_serves_previews_on_the_artifact_hostname(): void
    {
        $this->configureOrigins('artifact-host');

        $content = ' ';
        $capability = app(ArtifactDraftPreviewCapabilities::class)->issue(
            str_repeat('A', 26),
            strlen($content),
            hash('sha256', $content),
        );

        // A content-bound empty draft reaches the controller only after passing
        // the artifact runtime's route and host binding. Its 422 validation result
        // proves the surface is reachable without reopening an unsigned public
        // reflector or requiring this host-binding test to migrate the database.
        $this
            ->withServerVariables(['HTTP_SEC_FETCH_DEST' => 'iframe'])
            ->post(self::ARTIFACT_ORIGIN . '/artifact-previews/draft', [
                'capability' => $capability->token,
                'content' => UploadedFile::fake()->createWithContent('draft.html', $content),
            ])
            ->assertStatus(422);
    }

    public function test_artifact_runtime_refuses_a_preview_request_on_the_application_hostname(): void
    {
        $this->configureOrigins('artifact-host');

        $pageUid = str_repeat('A', 26);
        $versionUid = str_repeat('B', 26);

        $this
            ->get(sprintf('%s/artifact-previews/%s/versions/%s?expires=1&signature=x', self::APP_ORIGIN, $pageUid, $versionUid))
            ->assertNotFound();
    }
}
