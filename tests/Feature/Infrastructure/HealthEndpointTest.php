<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_works_before_migrations_and_without_sessions(): void
    {
        // Container healthchecks and `make up` probe /up on a freshly booted
        // stack, before `make migrate` has run. Health must not depend on the
        // database schema or the session store. The database driver mirrors
        // the shipped .env defaults; tests otherwise run the array driver.
        config(['session.driver' => 'database']);
        Schema::drop('sessions');

        $response = $this->get('/up');

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
    }

    public function test_health_endpoint_does_not_start_a_session_or_set_cookies(): void
    {
        config(['session.driver' => 'database']);

        $response = $this->get('/up');

        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame(0, \DB::table('sessions')->count());
    }

    public function test_health_endpoint_still_carries_the_security_headers(): void
    {
        // The e2e suite reads the per-request CSP nonce from /up; the header
        // middleware must keep applying even without the session stack.
        $response = $this->get('/up');

        $response->assertOk();
        $this->assertStringContainsString("script-src", (string) $response->headers->get('Content-Security-Policy'));
    }
}
