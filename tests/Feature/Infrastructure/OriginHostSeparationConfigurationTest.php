<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Infrastructure\Security\OriginNormalizer;
use Tests\TestCase;

/**
 * CAGE invariant: no app cookies on the artifact host. Cookies are scoped by
 * host and ignore the port (RFC 6265), and ports do not make requests
 * cross-site for SameSite processing, so an app and artifact origin that share
 * a host and differ only by port leak the app session cookie onto every
 * artifact request. Production hard-fails on a shared host in the boot gate;
 * these tests pin the same invariant onto every shipped local and e2e default
 * (localhost for the app, 127.0.0.1 for the artifact host).
 */
final class OriginHostSeparationConfigurationTest extends TestCase
{
    public function test_config_fallback_defaults_use_different_app_and_artifact_hosts(): void
    {
        // Parse the file rather than reading config(): at runtime both values
        // reflect the injected environment (including a developer's .env), so
        // only the literal env() fallbacks prove what a fresh install ships.
        $config = $this->readProjectFile('config/app.php');

        $this->assertHostsDiffer(
            $this->extract("/'url' => env\('APP_URL', '([^']+)'\)/", $config, 'config/app.php APP_URL fallback'),
            $this->extract("/'artifact_url' => env\('ARTIFACT_URL', '([^']+)'\)/", $config, 'config/app.php ARTIFACT_URL fallback'),
            'config/app.php fallbacks',
        );
    }

    public function test_env_example_defaults_use_different_app_and_artifact_hosts(): void
    {
        $env = $this->readProjectFile('.env.example');

        $this->assertHostsDiffer(
            $this->extract('/^APP_URL=(.+)$/m', $env, '.env.example APP_URL'),
            $this->extract('/^ARTIFACT_URL=(.+)$/m', $env, '.env.example ARTIFACT_URL'),
            '.env.example',
        );
    }

    public function test_compose_local_defaults_use_different_app_and_artifact_hosts(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');

        $appDefault = $this->extract('/APP_URL: \$\{APP_URL:-([^}]+)\}/', $compose, 'compose APP_URL default');
        $artifactDefaults = $this->extractAll('/\$\{ARTIFACT_URL:-([^}]+)\}/', $compose, 'compose ARTIFACT_URL default');

        foreach ($artifactDefaults as $artifactDefault) {
            $this->assertHostsDiffer($appDefault, $artifactDefault, 'docker-compose.yml local defaults');
        }
    }

    public function test_compose_e2e_defaults_use_different_app_and_artifact_hosts(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');

        $appDefault = $this->extract('/\$\{E2E_APP_URL:-([^}]+)\}/', $compose, 'compose E2E_APP_URL default');
        $artifactDefaults = $this->extractAll('/\$\{E2E_ARTIFACT_URL:-([^}]+)\}/', $compose, 'compose E2E_ARTIFACT_URL default');

        foreach ($artifactDefaults as $artifactDefault) {
            $this->assertHostsDiffer($appDefault, $artifactDefault, 'docker-compose.yml e2e defaults');
        }
    }

    public function test_makefile_e2e_defaults_use_different_app_and_artifact_hosts(): void
    {
        $makefile = $this->readProjectFile('Makefile');

        $this->assertHostsDiffer(
            $this->extract('/^E2E_APP_URL \?= (.+)$/m', $makefile, 'Makefile E2E_APP_URL'),
            $this->extract('/^E2E_ARTIFACT_URL \?= (.+)$/m', $makefile, 'Makefile E2E_ARTIFACT_URL'),
            'Makefile e2e defaults',
        );
    }

    public function test_playwright_fallbacks_use_different_app_and_artifact_hosts(): void
    {
        $appFallbacks = [
            $this->extract(
                '/PLAYWRIGHT_BASE_URL \?\? \'([^\']+)\'/',
                $this->readProjectFile('playwright.config.ts'),
                'playwright.config.ts baseURL fallback',
            ),
        ];
        $artifactFallbacks = [];

        $specs = glob(base_path('tests/e2e/*.spec.ts'));
        $this->assertIsArray($specs);
        $this->assertNotSame([], $specs, 'No Playwright specs found.');

        foreach ($specs as $specPath) {
            $spec = file_get_contents($specPath);
            $this->assertIsString($spec);

            if (preg_match('/PLAYWRIGHT_BASE_URL \?\? \'([^\']+)\'/', $spec, $matches) === 1) {
                $appFallbacks[] = $matches[1];
            }

            if (preg_match('/E2E_ARTIFACT_URL \?\? \'([^\']+)\'/', $spec, $matches) === 1) {
                $artifactFallbacks[] = $matches[1];
            }
        }

        $this->assertNotSame([], $artifactFallbacks, 'No spec pins an artifact-origin fallback.');

        foreach ($appFallbacks as $appFallback) {
            foreach ($artifactFallbacks as $artifactFallback) {
                $this->assertHostsDiffer($appFallback, $artifactFallback, 'Playwright fallbacks');
            }
        }
    }

    private function assertHostsDiffer(string $appUrl, string $artifactUrl, string $where): void
    {
        $appHost = $this->canonicalHost($appUrl, $where);
        $artifactHost = $this->canonicalHost($artifactUrl, $where);

        $this->assertNotSame(
            $appHost,
            $artifactHost,
            sprintf(
                '%s: app URL [%s] and artifact URL [%s] must use different canonical hosts, not merely different ports; a shared host sends the app session cookie to the artifact origin.',
                $where,
                $appUrl,
                $artifactUrl,
            ),
        );
    }

    private function canonicalHost(string $url, string $where): string
    {
        // Makefile defaults embed the port as a Make expansion ("$(E2E_APP_PORT)"),
        // which is not a parseable URL, so isolate the host before canonicalising.
        $matched = preg_match('~^https?://([^:/\s]+)~', trim($url), $matches);
        $this->assertSame(1, $matched, sprintf('%s: [%s] is not an http(s) URL.', $where, $url));

        $host = OriginNormalizer::tryHost($matches[1]);
        $this->assertIsString($host, sprintf('%s: [%s] does not carry a canonical host.', $where, $url));

        return $host;
    }

    private function extract(string $pattern, string $haystack, string $what): string
    {
        $matched = preg_match($pattern, $haystack, $matches);
        $this->assertSame(1, $matched, sprintf('Expected to find %s.', $what));

        return trim($matches[1]);
    }

    /**
     * @return list<string>
     */
    private function extractAll(string $pattern, string $haystack, string $what): array
    {
        $matched = preg_match_all($pattern, $haystack, $matches);
        $this->assertIsInt($matched);
        $this->assertGreaterThan(0, $matched, sprintf('Expected to find %s.', $what));

        $values = array_values(array_unique(array_map(trim(...), $matches[1])));

        return $values;
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        return $contents;
    }
}
