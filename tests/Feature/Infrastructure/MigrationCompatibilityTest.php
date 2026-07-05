<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MigrationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_schema_provides_framework_password_reset_tokens_table(): void
    {
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $this->assertTrue(Schema::hasColumn('password_reset_tokens', 'email'));
        $this->assertTrue(Schema::hasColumn('password_reset_tokens', 'token'));
        $this->assertTrue(Schema::hasColumn('password_reset_tokens', 'created_at'));
    }
}
