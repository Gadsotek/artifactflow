<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

final class HealthProbeTrustedHostTest extends TestCase
{
    /**
     * In production TrustHosts (bootstrap/app.php) trusts only the hosts of
     * APP_URL and ARTIFACT_URL. The container health probe connects over
     * loopback, so a default Host of 127.0.0.1 is rejected as a suspicious
     * operation before /up can serve its intentionally database-free response,
     * leaving the app and artifact-host containers permanently unhealthy behind
     * an otherwise-working stack. The framework disables trusted-host
     * enforcement while running unit tests (TrustHosts::shouldSpecifyTrustedHosts
     * is false under runningUnitTests()), which is exactly why an HTTP-level test
     * cannot reproduce this. Assert the shipped probe script instead: both HTTP
     * probes must present a Host derived from APP_URL, with a loopback fallback
     * for local/dev where enforcement is off.
     */
    public function test_both_http_probes_send_a_host_derived_from_app_url(): void
    {
        $script = $this->readScript('docker/healthcheck-app.sh');

        $hostExpression = 'parse_url((string) getenv("APP_URL"), PHP_URL_HOST) ?: "127.0.0.1"';

        $this->assertStringContainsString(
            $hostExpression,
            $script,
            'Both health probes must derive the request Host from APP_URL with a loopback fallback.',
        );
        $this->assertSame(
            2,
            substr_count($script, $hostExpression),
            'Both the app /up probe and the artifact-host /login probe must derive the trusted Host from APP_URL.',
        );
        $this->assertSame(
            2,
            substr_count($script, '"Host: " . $host'),
            'Both HTTP probes must send a Host header built from the APP_URL host so production TrustHosts accepts them.',
        );
    }

    private function readScript(string $relativePath): string
    {
        $contents = file_get_contents(base_path($relativePath));
        $this->assertIsString($contents, "Could not read {$relativePath}.");

        return $contents;
    }
}
