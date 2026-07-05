<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

final class PublicWelcomePageTest extends TestCase
{
    public function test_public_welcome_page_does_not_leak_runtime_metadata(): void
    {
        config([
            'app.artifact_url' => 'https://artifacts.example.internal',
            'app.runtime_role' => 'app',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('https://artifacts.example.internal')
            ->assertDontSee('Runtime')
            ->assertDontSee('/up');
    }
}
