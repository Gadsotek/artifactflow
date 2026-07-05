<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

final class LocalServerStartupTest extends TestCase
{
    public function test_local_docker_server_preserves_compose_runtime_environment(): void
    {
        $script = file_get_contents(base_path('docker/start-local.sh'));

        $this->assertIsString($script);
        $this->assertStringContainsString('php artisan serve', $script);
        $this->assertStringContainsString('--no-reload', $script);
    }
}
