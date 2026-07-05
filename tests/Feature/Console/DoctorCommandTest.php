<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class DoctorCommandTest extends TestCase
{
    public function test_doctor_reports_a_healthy_local_configuration_with_exit_code_zero(): void
    {
        $this->configureHealthyLocalEnvironment();

        $exitCode = Artisan::call('artifactflow:doctor');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('ArtifactFlow doctor (local mode)', $output);
        $this->assertStringContainsString('[PASS]', $output);
        $this->assertStringContainsString('[SKIP]', $output);
        $this->assertStringContainsString('All required checks passed.', $output);
        $this->assertStringNotContainsString('[FAIL]', $output);
    }

    public function test_doctor_warns_about_non_private_artifact_storage_locally_without_failing(): void
    {
        $this->configureHealthyLocalEnvironment();
        config(['filesystems.disks.artifacts.visibility' => 'public']);

        $exitCode = Artisan::call('artifactflow:doctor');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[WARN]', $output);
        $this->assertStringContainsString('Artifact disk visibility is not private.', $output);
        $this->assertStringContainsString('All required checks passed.', $output);
    }

    public function test_doctor_fails_locally_when_app_and_artifact_share_a_host_across_ports(): void
    {
        $this->configureHealthyLocalEnvironment();
        config(['app.artifact_url' => 'http://localhost:18081']);

        $exitCode = Artisan::call('artifactflow:doctor');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL]', $output);
        $this->assertStringContainsString('cookies ignore the port', $output);
        $this->assertStringContainsString('check(s) failed.', $output);
    }

    public function test_doctor_exits_non_zero_and_lists_failures_when_invariants_are_broken(): void
    {
        $this->configureHealthyLocalEnvironment();
        config([
            'app.artifact_url' => 'http://localhost:18080',
            'app.key' => 'base64:' . base64_encode('short'),
        ]);

        $exitCode = Artisan::call('artifactflow:doctor');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL]', $output);
        $this->assertStringContainsString('check(s) failed.', $output);
    }

    public function test_doctor_reports_production_mode_and_grades_hardening_failures(): void
    {
        $this->configureHealthyLocalEnvironment();
        config([
            'app.env' => 'production',
            'session.secure' => false,
            'trustedproxy.raw' => '*',
        ]);

        $exitCode = Artisan::call('artifactflow:doctor');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('ArtifactFlow doctor (production mode)', $output);
        $this->assertStringContainsString('[FAIL]', $output);
        $this->assertStringContainsString('check(s) failed.', $output);
    }

    private function configureHealthyLocalEnvironment(): void
    {
        config([
            'app.env' => 'testing',
            'app.runtime_role' => 'app',
            'app.url' => 'http://localhost:18080',
            'app.artifact_url' => 'http://127.0.0.1:18081',
            'app.artifact_frame_ancestors' => 'http://localhost:18080',
            'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
            'app.artifact_url_signing_key' => 'base64:' . base64_encode(str_repeat('b', 32)),
            'app.bootstrap_admin_password' => '',
            'app.create_user_password' => '',
            'app.reset_user_password' => '',
            'pages.artifact_max_bytes' => 2_000_000,
            'pages.max_html_bytes' => 1_000_000,
            'filesystems.disks.artifacts.visibility' => 'private',
        ]);
    }
}
