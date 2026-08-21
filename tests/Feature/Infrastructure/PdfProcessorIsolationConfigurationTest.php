<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

final class PdfProcessorIsolationConfigurationTest extends TestCase
{
    public function test_service_is_single_worker_non_root_and_wired_only_into_the_e2e_app(): void
    {
        $dockerfile = $this->readProjectFile('pdf-processor-spike/Dockerfile');
        $stage = $this->afterNeedle($dockerfile, ' AS pdf-processor-service');
        $compose = $this->readProjectFile('docker-compose.yml');
        $makefile = $this->readProjectFile('Makefile');

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
        $this->assertStringContainsString('pdf-processor:', $compose);
        $this->assertStringContainsString('internal: true', $compose);
        $this->assertStringContainsString('subnet: ${E2E_PDF_PROCESSOR_NETWORK_SUBNET:-10.254.70.0/24}', $compose);
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
}
