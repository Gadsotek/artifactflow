<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

final class PdfProcessorIsolationConfigurationTest extends TestCase
{
    public function test_local_processor_is_started_with_the_app_but_pdf_capability_stays_default_off(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');
        $localProcessor = $this->serviceBlock($compose, 'pdf-processor', 'app');
        $app = $this->serviceBlock($compose, 'app', 'artifact-host');
        $networks = $this->afterLastNeedle($compose, "\nnetworks:\n");
        $localNetwork = $this->serviceBlock($networks, 'local-pdf-processor', 'pdf-processor');

        $this->assertStringNotContainsString('profiles:', $localProcessor);
        $this->assertStringContainsString('target: pdf-processor-service', $localProcessor);
        $this->assertStringContainsString('image: artifactflow-pdf-processor-service:local', $localProcessor);
        $this->assertStringContainsString(
            'PDF_PROCESSOR_SHARED_SECRET: ${PDF_PROCESSOR_SHARED_SECRET:-artifactflow-local-pdf-processor-secret-not-for-production}',
            $localProcessor,
        );
        $this->assertStringContainsString('read_only: true', $localProcessor);
        $this->assertStringContainsString('/tmp:rw,noexec,nosuid,size=32m', $localProcessor);
        $this->assertStringContainsString('no-new-privileges:true', $localProcessor);
        $this->assertStringContainsString('- ALL', $localProcessor);
        $this->assertStringContainsString('pids_limit: 32', $localProcessor);
        $this->assertStringContainsString('mem_limit: 512m', $localProcessor);
        $this->assertStringContainsString('cpus: 1.0', $localProcessor);
        $this->assertStringContainsString('- local-pdf-processor', $localProcessor);
        $this->assertStringContainsString('test: ["CMD", "php", "/srv/pdf-processor-spike/healthcheck.php"]', $localProcessor);
        $this->assertStringContainsString('interval: 1m', $localProcessor);
        $this->assertStringContainsString('start_interval: 5s', $localProcessor);
        $this->assertStringNotContainsString('ports:', $localProcessor);
        $this->assertStringNotContainsString('- default', $localProcessor);
        $this->assertStringContainsString('internal: true', $localNetwork);
        $this->assertStringNotContainsString('ipam:', $localNetwork);
        $this->assertStringNotContainsString('subnet:', $localNetwork);

        $this->assertStringContainsString('PDF_PROCESSOR_ENABLED: ${PDF_PROCESSOR_ENABLED:-false}', $app);
        $this->assertStringContainsString('PDF_PROCESSOR_URL: ${PDF_PROCESSOR_URL:-http://pdf-processor:8080}', $app);
        $this->assertStringContainsString(
            'PDF_PROCESSOR_SHARED_SECRET: ${PDF_PROCESSOR_SHARED_SECRET:-artifactflow-local-pdf-processor-secret-not-for-production}',
            $app,
        );
        $this->assertMatchesRegularExpression(
            '/depends_on:.*?pdf-processor:\s+condition: service_healthy/s',
            $app,
        );
        $this->assertMatchesRegularExpression('/networks:.*?- local-pdf-processor/s', $app);

        foreach (
            [
                $this->serviceBlock($compose, 'artifact-host', 'e2e-pdf-processor'),
                $this->serviceBlock($compose, 'worker', 'scheduler'),
                $this->serviceBlock($compose, 'scheduler', 'reverb'),
                $this->serviceBlock($compose, 'reverb', 'vite'),
            ] as $unrelatedRuntime
        ) {
            $this->assertStringContainsString('PDF_PROCESSOR_URL: ""', $unrelatedRuntime);
            $this->assertStringContainsString('PDF_PROCESSOR_SHARED_SECRET: ""', $unrelatedRuntime);
            $this->assertStringNotContainsString('- local-pdf-processor', $unrelatedRuntime);
        }
    }

    public function test_e2e_service_is_single_worker_non_root_and_wired_only_into_the_e2e_app(): void
    {
        $dockerfile = $this->readProjectFile('pdf-processor-spike/Dockerfile');
        $stage = $this->afterNeedle($dockerfile, ' AS pdf-processor-service');
        $compose = $this->readProjectFile('docker-compose.yml');
        $makefile = $this->readProjectFile('Makefile');
        $e2eApp = $this->serviceBlock($compose, 'e2e-app', 'e2e-artifact-host');
        $networks = $this->afterLastNeedle($compose, "\nnetworks:\n");
        $e2eNetwork = substr($networks, (int) strpos($networks, "  pdf-processor:\n"));

        $this->assertStringContainsString('COPY src/PdfProcessor.php', $stage);
        $this->assertStringContainsString('COPY public', $stage);
        $this->assertStringContainsString('COPY start.sh healthcheck.php', $stage);
        $this->assertStringContainsString('USER pdf-spike', $stage);
        $this->assertStringContainsString('CMD ["/srv/pdf-processor-spike/start.sh"]', $stage);
        $this->assertStringNotContainsString('COPY app ', $stage);
        $this->assertStringNotContainsString('COPY . ', $stage);
        $this->assertStringContainsString('e2e-pdf-processor:', $compose);
        $this->assertStringContainsString('target: pdf-processor-service', $compose);
        $this->assertStringContainsString('image: artifactflow-pdf-processor-service:local', $compose);
        $this->assertStringContainsString('PDF_PROCESSOR_SHARED_SECRET: ${E2E_PDF_PROCESSOR_SHARED_SECRET:-artifactflow-e2e-pdf-processor-secret}', $compose);
        $this->assertStringContainsString('PDF_PROCESSOR_ENABLED: "true"', $compose);
        $this->assertStringContainsString('PDF_PROCESSOR_URL: http://e2e-pdf-processor:8080', $compose);
        $this->assertStringContainsString('PDF_PROCESSOR_URL: ""', $compose);
        $this->assertStringContainsString('PDF_PROCESSOR_SHARED_SECRET: ""', $compose);
        $this->assertStringContainsString('pdf-processor:', $networks);
        $this->assertStringContainsString('internal: true', $e2eNetwork);
        $this->assertStringContainsString('subnet: ${E2E_PDF_PROCESSOR_NETWORK_SUBNET:-10.254.70.0/24}', $e2eNetwork);
        $this->assertStringContainsString('- pdf-processor', $e2eApp);
        $this->assertStringContainsString('E2E_PDF_PROCESSOR_SERVICE ?= e2e-pdf-processor', $makefile);
        $this->assertStringContainsString('pdf-processor-service-build:', $makefile);
        $this->assertStringContainsString('pdf-processor-service-test:', $makefile);
        $this->assertStringContainsString('--target pdf-processor-service', $makefile);
        $this->assertStringContainsString('/srv/pdf-processor-spike/healthcheck.php', $makefile);

        $matched = preg_match(
            '/\n  e2e-pdf-processor:(?<block>.*?)\n  e2e-app:/s',
            $compose,
            $matches,
        );
        $this->assertSame(1, $matched);
        $service = $matches['block'];
        $this->assertStringContainsString('profiles: ["e2e"]', $service);
        $this->assertStringContainsString('read_only: true', $service);
        $this->assertStringContainsString('/tmp:rw,noexec,nosuid,size=32m', $service);
        $this->assertStringContainsString('no-new-privileges:true', $service);
        $this->assertStringContainsString('- ALL', $service);
        $this->assertStringContainsString('pids_limit: 32', $service);
        $this->assertStringContainsString('mem_limit: 512m', $service);
        $this->assertStringContainsString('cpus: 1.0', $service);
        $this->assertStringContainsString('- pdf-processor', $service);
        $this->assertStringNotContainsString('ports:', $service);
        $this->assertStringNotContainsString('- default', $service);

        $start = $this->readProjectFile('pdf-processor-spike/start.sh');
        $this->assertStringContainsString('PHP_CLI_SERVER_WORKERS', $start);
        $this->assertStringContainsString('must stay at one worker', $start);
        $this->assertStringContainsString('unset PHP_CLI_SERVER_WORKERS', $start);
        $this->assertStringContainsString('memory_limit=64M', $start);
        $this->assertStringContainsString('post_max_size=17M', $start);
        $this->assertStringContainsString('0.0.0.0:${port}', $start);
    }

    public function test_http_boundary_is_narrow_authenticated_and_fail_closed(): void
    {
        $index = $this->readProjectFile('pdf-processor-spike/public/index.php');

        $this->assertStringContainsString('$path === \'/health\'', $index);
        $this->assertStringContainsString('$path !== \'/v1/inspect\'', $index);
        $this->assertStringContainsString('ProcessorRequest::fromGlobals', $index);
        $this->assertStringContainsString('PdfBoxEngine::production()', $index);
        $this->assertStringContainsString('->signature($request, $configuration->sharedSecret)', $index);
        $this->assertStringContainsString("'Cache-Control' => 'no-store'", $index);
        $this->assertStringContainsString("'X-Content-Type-Options' => 'nosniff'", $index);
        $this->assertStringContainsString('catch (ProcessorAuthenticationFailure)', $index);
        $this->assertStringContainsString('catch (ProcessorRejection|EngineRejection)', $index);
        $this->assertStringContainsString('catch (Throwable)', $index);
        $this->assertStringNotContainsString('getMessage()', $index);

        $healthcheck = $this->readProjectFile('pdf-processor-spike/healthcheck.php');
        $this->assertStringContainsString('ProcessorConfiguration::fromEnvironment()', $healthcheck);
        $this->assertStringContainsString('PdfBoxEngine::production()->verifyHealth()', $healthcheck);
        $this->assertStringNotContainsString('fsockopen', $healthcheck);
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        return $contents;
    }

    private function afterNeedle(string $haystack, string $needle): string
    {
        $position = strpos($haystack, $needle);
        $this->assertNotFalse($position, sprintf('Expected to find [%s].', $needle));

        return substr($haystack, $position + strlen($needle));
    }

    private function afterLastNeedle(string $haystack, string $needle): string
    {
        $position = strrpos($haystack, $needle);
        $this->assertNotFalse($position, sprintf('Expected to find [%s].', $needle));

        return substr($haystack, $position + strlen($needle));
    }

    private function serviceBlock(string $compose, string $service, string $nextService): string
    {
        $matched = preg_match(
            sprintf('/\n  %s:(?<block>.*?)\n  %s:/s', preg_quote($service, '/'), preg_quote($nextService, '/')),
            $compose,
            $matches,
        );
        $this->assertSame(1, $matched, sprintf('Expected Compose service [%s].', $service));

        return $matches['block'];
    }
}
