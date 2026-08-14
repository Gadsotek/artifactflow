<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

final class DependencyBootstrapConfigurationTest extends TestCase
{
    public function test_composer_bootstrap_reinstalls_dependencies_when_the_lock_file_changes(): void
    {
        $script = file_get_contents(base_path('docker/ensure-vendor.sh'));

        $this->assertIsString($script);
        $this->assertStringContainsString('composer_lock_hash="$(sha256sum composer.lock', $script);
        $this->assertStringContainsString('vendor/.composer-lock-hash', $script);
        $this->assertStringContainsString('vendor_is_ready()', $script);
        $this->assertStringContainsString('[ "${installed_lock_hash}" = "${composer_lock_hash}" ]', $script);
        $this->assertStringContainsString('printf \'%s\' "${composer_lock_hash}" > "${composer_lock_hash_file}"', $script);
    }
}
