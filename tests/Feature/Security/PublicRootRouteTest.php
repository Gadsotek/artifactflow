<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicRootRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_root_redirects_to_login_without_leaking_runtime_metadata(): void
    {
        config([
            'app.artifact_url' => 'https://artifacts.example.internal',
            'app.runtime_role' => 'app',
        ]);

        $this->get('/')
            ->assertRedirect('/login')
            ->assertDontSee('https://artifacts.example.internal')
            ->assertDontSee('Runtime')
            ->assertDontSee('/up');
    }
}
