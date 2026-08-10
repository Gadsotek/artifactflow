<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

final class ProductionSecurityDocumentationTest extends TestCase
{
    public function test_architecture_overview_matches_the_database_only_production_limiter_contract(): void
    {
        $architecture = file_get_contents(base_path('docs/ARCHITECTURE.md'));

        $this->assertIsString($architecture);
        $this->assertStringContainsString(
            'production rate limiting currently supports only the dedicated database limiter stores',
            $architecture,
        );
        $this->assertStringNotContainsString(
            'production counters must use a shared database, Redis, Memcached, or DynamoDB store',
            $architecture,
        );
    }
}
