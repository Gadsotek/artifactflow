<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SourceLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_layout_shows_the_configured_source_link(): void
    {
        config(['app.source_url' => 'https://example.test/authenticated-fork']);

        $user = User::factory()->create([
            'name' => 'Source Link User',
            'email' => 'source-link@example.test',
        ]);

        app(CreatePersonalWorkspaceForUser::class)->handle($user);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Source')
            ->assertSee('href="https://example.test/authenticated-fork"', false)
            ->assertDontSee('href="https://github.com/Gadsotek/artifactflow"', false);
    }
}
