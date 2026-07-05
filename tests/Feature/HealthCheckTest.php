<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HealthCheckTest extends TestCase
{
    public function test_it_responds_to_the_laravel_health_endpoint(): void
    {
        $this->get('/up')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_artifact_runtime_does_not_expose_health_endpoint(): void
    {
        config(['app.runtime_role' => 'artifact-host']);

        $response = $this->get('/up');

        $response->assertNotFound()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy');

        $this->assertStringContainsString(
            "default-src 'none'",
            (string) $response->headers->get('Content-Security-Policy'),
        );
        $this->assertStringContainsString(
            'sandbox',
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_unknown_runtime_role_exposes_no_application_http_surface(): void
    {
        config(['app.runtime_role' => 'artifact_host']);

        $this->get('/login')->assertNotFound();
        $this->get('/up')->assertNotFound();
    }

    public function test_it_renders_the_infrastructure_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('artifactflow')
            ->assertSee('data-brand-mark', false)
            ->assertSee('href="/favicon.svg"', false)
            ->assertSee('FrankenPHP');
    }
}
