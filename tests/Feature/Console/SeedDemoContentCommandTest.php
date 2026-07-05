<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\DomainRuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SeedDemoContentCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_demo_content_requires_an_email(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('User email is required.');

        Artisan::call('artifactflow:seed-demo-content');
    }

    public function test_seed_demo_content_rejects_an_unknown_user(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('User does not exist.');

        Artisan::call('artifactflow:seed-demo-content', [
            '--email' => 'nobody@example.test',
        ]);
    }
}
