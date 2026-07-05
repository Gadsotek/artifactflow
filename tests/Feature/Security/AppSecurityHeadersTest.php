<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Application\Identity\CreateUser;
use App\Http\Middleware\AddSecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AppSecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_origin_sets_frame_protection_and_hsts_headers(): void
    {
        $user = app(CreateUser::class)->handle('Header User', 'headers@example.test', 'correct horse battery staple');
        config(['app.artifact_url' => 'https://artifacts.example.test']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            // includeSubDomains/preload are opt-in, so the default is host-only.
            ->assertHeader('Strict-Transport-Security', 'max-age=63072000')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $content = (string) $response->getContent();

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringContainsString("style-src 'self' 'nonce-", $csp);
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertStringContainsString("img-src 'self' data: blob:", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
        $this->assertStringNotContainsString("base-uri 'self'", $csp);
        // The pre-save draft preview posts to the isolated artifact origin, so it
        // must be an allowed form target alongside 'self'.
        $this->assertStringContainsString("form-action 'self' https://artifacts.example.test", $csp);
        $this->assertStringContainsString('frame-src https://artifacts.example.test', $csp);
        $this->assertStringContainsString("webrtc 'block'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertSame('camera=(), microphone=(), geolocation=(), payment=()', $response->headers->get('Permissions-Policy'));
        $this->assertMatchesRegularExpression('/<script nonce="[^"]+" data-theme-bootstrap>/', $content);
    }

    public function test_hsts_include_subdomains_and_preload_are_opt_in(): void
    {
        $user = app(CreateUser::class)->handle('Hsts User', 'hsts@example.test', 'correct horse battery staple');
        config([
            'app.hsts.max_age' => 3600,
            'app.hsts.include_subdomains' => true,
            'app.hsts.preload' => true,
        ]);

        // Once an operator explicitly enables them, both reach-beyond-this-host
        // directives are appended in order after the max-age.
        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=3600; includeSubDomains; preload');
    }

    public function test_a_malformed_hsts_flag_falls_closed_to_host_only(): void
    {
        $user = app(CreateUser::class)->handle('Hsts Guard', 'hsts-guard@example.test', 'correct horse battery staple');
        // A non-boolean flag must not be read as "on"; it falls back to omitted.
        config([
            'app.hsts.include_subdomains' => 'yes-please',
            'app.hsts.preload' => 1,
        ]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=63072000');
    }

    public function test_app_origin_csp_nonce_is_unique_per_request(): void
    {
        // Vite stores the nonce on a framework singleton, so in a persistent
        // FrankenPHP worker its value survives between requests. Minting must not be
        // conditional on "no nonce yet" or every request and user served by that
        // worker would share one guessable nonce. Two handle() passes in a single
        // process (which is what a reused worker is) must still differ.
        $middleware = app(AddSecurityHeaders::class);
        $next = static fn (Request $request): Response => new Response('OK');

        $first = $this->cspNonce($middleware->handle(Request::create('/dashboard'), $next));
        $second = $this->cspNonce($middleware->handle(Request::create('/dashboard'), $next));

        $this->assertNotSame('', $first);
        $this->assertNotSame('', $second);
        $this->assertNotSame($first, $second, 'Each request must receive a unique CSP nonce.');
    }

    public function test_app_origin_csp_overwrites_upstream_weak_security_directives(): void
    {
        config(['app.artifact_url' => 'https://artifacts.example.test']);

        $request = Request::create('/dashboard');
        $response = response('OK', 200, [
            'Content-Security-Policy' => "default-src *; script-src 'unsafe-inline'; object-src *; base-uri 'self'; frame-ancestors *",
        ]);

        $hardened = app(AddSecurityHeaders::class)->apply($request, $response);
        $csp = (string) $hardened->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("webrtc 'block'", $csp);
        $this->assertStringNotContainsString("default-src *", $csp);
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertStringNotContainsString("object-src *", $csp);
        $this->assertStringNotContainsString("base-uri 'self'", $csp);
        $this->assertStringNotContainsString('frame-ancestors *', $csp);
        $this->assertSame('camera=(), microphone=(), geolocation=(), payment=()', $hardened->headers->get('Permissions-Policy'));
    }

    public function test_local_app_csp_allows_configured_vite_development_origin(): void
    {
        $user = app(CreateUser::class)->handle('Vite Header User', 'vite-headers@example.test', 'correct horse battery staple');
        config([
            'app.env' => 'local',
            'app.artifact_url' => 'https://artifacts.example.test',
            'app.local_vite_origin' => 'http://localhost:5181',
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('script-src', $csp);
        $this->assertStringContainsString('http://localhost:5181', $csp);
        $this->assertStringContainsString('ws://localhost:5181', $csp);
        $this->assertStringNotContainsString('http://0.0.0.0:5181', $csp);
    }

    public function test_production_app_csp_does_not_allow_vite_development_origin(): void
    {
        $user = app(CreateUser::class)->handle('Production Header User', 'production-headers@example.test', 'correct horse battery staple');
        config([
            'app.env' => 'production',
            'app.artifact_url' => 'https://artifacts.example.test',
            'app.local_vite_origin' => 'http://localhost:5181',
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('http://localhost:5181', $csp);
        $this->assertStringNotContainsString('ws://localhost:5181', $csp);
    }

    public function test_artifact_host_fallback_csp_blocks_webrtc(): void
    {
        config(['app.runtime_role' => 'artifact-host']);

        $request = Request::create('/not-an-artifact-preview');
        $response = response('Not found', 404);

        $hardened = app(AddSecurityHeaders::class)->apply($request, $response);
        $csp = (string) $hardened->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("connect-src 'none'", $csp);
        $this->assertStringContainsString("webrtc 'block'", $csp);
        $this->assertStringContainsString('sandbox', $csp);
    }

    private function cspNonce(Response $response): string
    {
        $csp = (string) $response->headers->get('Content-Security-Policy');

        return preg_match("/'nonce-([^']+)'/", $csp, $matches) === 1 ? $matches[1] : '';
    }
}
