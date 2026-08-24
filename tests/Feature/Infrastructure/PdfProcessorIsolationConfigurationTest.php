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
        $this->assertStringContainsString('network_mode: none', $localProcessor);
        $this->assertStringContainsString('PDF_PROCESSOR_SOCKET_PATH: /run/artifactflow/pdf-processor/processor.sock', $localProcessor);
        $this->assertStringContainsString('pdf-processor-socket:/run/artifactflow/pdf-processor', $localProcessor);
        $this->assertStringContainsString(
            "pdf-processor-socket-init:\n        condition: service_completed_successfully",
            $localProcessor,
        );
        $this->assertStringContainsString('test: ["CMD", "php", "/srv/pdf-processor-spike/healthcheck.php"]', $localProcessor);
        $this->assertStringContainsString('interval: 1m', $localProcessor);
        $this->assertStringContainsString('start_interval: 5s', $localProcessor);
        $this->assertStringNotContainsString('ports:', $localProcessor);
        $this->assertStringNotContainsString('networks:', $localProcessor);

        $this->assertStringContainsString('PDF_PROCESSOR_ENABLED: ${PDF_PROCESSOR_ENABLED:-false}', $app);
        $this->assertStringContainsString('PDF_PROCESSOR_URL: ${PDF_PROCESSOR_URL:-http://localhost}', $app);
        $this->assertStringContainsString('PDF_PROCESSOR_SOCKET_PATH: /run/artifactflow/pdf-processor/processor.sock', $app);
        $this->assertStringContainsString(
            'PDF_PROCESSOR_SHARED_SECRET: ${PDF_PROCESSOR_SHARED_SECRET:-artifactflow-local-pdf-processor-secret-not-for-production}',
            $app,
        );
        $this->assertMatchesRegularExpression(
            '/depends_on:.*?pdf-processor:\s+condition: service_healthy/s',
            $app,
        );
        $this->assertStringContainsString('pdf-processor-socket:/run/artifactflow/pdf-processor:ro', $app);

        foreach (
            [
                $this->serviceBlock($compose, 'artifact-host', 'e2e-pdf-processor'),
                $this->serviceBlock($compose, 'worker', 'scheduler'),
                $this->serviceBlock($compose, 'scheduler', 'reverb'),
                $this->serviceBlock($compose, 'reverb', 'vite'),
            ] as $unrelatedRuntime
        ) {
            $this->assertStringContainsString('PDF_PROCESSOR_URL: ""', $unrelatedRuntime);
            $this->assertStringContainsString('PDF_PROCESSOR_SOCKET_PATH: ""', $unrelatedRuntime);
            $this->assertStringContainsString('PDF_PROCESSOR_SHARED_SECRET: ""', $unrelatedRuntime);
            $this->assertStringNotContainsString('pdf-processor-socket:', $unrelatedRuntime);
        }
    }

    public function test_e2e_service_is_single_worker_non_root_and_wired_only_into_the_e2e_app(): void
    {
        $dockerfile = $this->readProjectFile('pdf-processor-spike/Dockerfile');
        $stage = $this->afterNeedle($dockerfile, ' AS pdf-processor-service');
        $compose = $this->readProjectFile('docker-compose.yml');
        $makefile = $this->readProjectFile('Makefile');
        $e2eApp = $this->serviceBlock($compose, 'e2e-app', 'e2e-artifact-host');

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
        $this->assertStringContainsString('PDF_PROCESSOR_URL: http://localhost', $compose);
        $this->assertStringContainsString('PDF_PROCESSOR_SOCKET_PATH: /run/artifactflow/e2e-pdf-processor/processor.sock', $compose);
        $this->assertStringContainsString('PDF_PROCESSOR_URL: ""', $compose);
        $this->assertStringContainsString('PDF_PROCESSOR_SHARED_SECRET: ""', $compose);
        $this->assertStringContainsString('e2e-pdf-processor-socket:/run/artifactflow/e2e-pdf-processor:ro', $e2eApp);
        $this->assertStringContainsString('E2E_PDF_PROCESSOR_SERVICE ?= e2e-pdf-processor', $makefile);
        $this->assertStringContainsString('pdf-processor-service-build:', $makefile);
        $this->assertStringContainsString('pdf-processor-service-test:', $makefile);
        $this->assertStringContainsString('--target pdf-processor-service', $makefile);

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
        $this->assertStringContainsString('network_mode: none', $service);
        $this->assertStringContainsString(
            "e2e-pdf-processor-socket-init:\n        condition: service_completed_successfully",
            $service,
        );
        $this->assertStringNotContainsString('ports:', $service);
        $this->assertStringNotContainsString('networks:', $service);
        $this->assertStringContainsString('chmod 0755 /socket && chown 10002:10002 /socket', $compose);

        $start = $this->readProjectFile('pdf-processor-spike/start.sh');
        $this->assertStringContainsString('PHP_CLI_SERVER_WORKERS', $start);
        $this->assertStringContainsString('must stay at one worker', $start);
        $this->assertStringContainsString('unset PHP_CLI_SERVER_WORKERS', $start);
        $this->assertStringContainsString('memory_limit=64M', $start);
        $this->assertStringContainsString('post_max_size=17M', $start);
        $this->assertStringContainsString('127.0.0.1:${port}', $start);
        $this->assertStringContainsString('UNIX-LISTEN:', $start);
    }

    public function test_http_boundary_is_narrow_authenticated_and_fail_closed(): void
    {
        $index = $this->readProjectFile('pdf-processor-spike/public/index.php');
        $makefile = $this->readProjectFile('Makefile');

        $this->assertStringContainsString('$path === \'/health\'', $index);
        $this->assertStringContainsString('$path !== \'/v1/inspect\'', $index);
        $this->assertStringContainsString('ProcessorRequest::fromGlobals', $index);
        $this->assertStringContainsString('PdfBoxEngine::production()', $index);
        $this->assertStringContainsString('->signature($request, $configuration->sharedSecret)', $index);
        $this->assertStringContainsString("'Cache-Control' => 'no-store'", $index);
        $this->assertStringContainsString("'X-Content-Type-Options' => 'nosniff'", $index);
        $this->assertStringContainsString('catch (ProcessorAuthenticationFailure)', $index);
        $this->assertStringContainsString('catch (EngineRejection $exception)', $index);
        $this->assertStringContainsString('$exception->reason', $index);
        $this->assertStringContainsString('catch (ProcessorRejection)', $index);
        $this->assertStringContainsString('catch (Throwable)', $index);
        $this->assertStringNotContainsString('getMessage()', $index);

        $healthcheck = $this->readProjectFile('pdf-processor-spike/healthcheck.php');
        $this->assertStringContainsString('ProcessorConfiguration::fromEnvironment()', $healthcheck);
        $this->assertStringNotContainsString('PdfBoxEngine::production()->verifyHealth()', $healthcheck);
        $this->assertStringContainsString('unix://', $healthcheck);

        $serviceTest = $this->afterNeedle($makefile, 'pdf-processor-service-test:');
        $this->assertStringContainsString('docker run -d --name "$$processor_container"', $serviceTest);
        $this->assertStringContainsString('.State.Health.Status', $serviceTest);
        $this->assertStringNotContainsString('--entrypoint php', $serviceTest);
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
