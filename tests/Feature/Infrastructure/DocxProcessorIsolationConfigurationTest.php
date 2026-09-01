<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

final class DocxProcessorIsolationConfigurationTest extends TestCase
{
    public function test_docx_processor_is_dedicated_pinned_and_networkless(): void
    {
        $dockerfile = $this->readProjectFile('docx-processor/Dockerfile');
        $compose = $this->readProjectFile('docker-compose.yml');
        $processor = $this->serviceBlock($compose, 'docx-processor', 'app');
        $app = $this->serviceBlock($compose, 'app', 'artifact-host');

        $this->assertStringContainsString('ubuntu:26.04@sha256:', $dockerfile);
        $this->assertStringContainsString('php8.5-cli php8.5-xml php8.5-zip', $dockerfile);
        $this->assertStringContainsString('unlink /usr/bin/pebble', $dockerfile);
        $this->assertStringNotContainsString('docker-php-ext-install', $dockerfile);
        $this->assertStringContainsString('ARG LIBREOFFICE_VERSION=26.2.5', $dockerfile);
        $this->assertStringContainsString('LIBREOFFICE_AMD64_SHA256', $dockerfile);
        $this->assertStringContainsString('LIBREOFFICE_ARM64_SHA256', $dockerfile);
        $this->assertStringContainsString('lo_directory=x86_64; lo_archive_arch=x86-64;', $dockerfile);
        $this->assertStringContainsString('lo_directory=aarch64; lo_archive_arch=aarch64;', $dockerfile);
        $this->assertStringContainsString(
            'LibreOffice_${LIBREOFFICE_VERSION}_Linux_${lo_archive_arch}_deb.tar.gz',
            $dockerfile,
        );
        $this->assertStringContainsString('/deb/${lo_directory}/${archive}', $dockerfile);
        $this->assertStringContainsString('USER docx-processor', $dockerfile);
        $this->assertStringNotContainsString('COPY app ', $dockerfile);
        $this->assertStringNotContainsString('COPY . ', $dockerfile);

        foreach ([
            'read_only: true',
            '/tmp:rw,noexec,nosuid,nodev,size=192m,mode=1777',
            'no-new-privileges:true',
            '- ALL',
            'pids_limit: 128',
            'mem_limit: 768m',
            'cpus: 1.0',
            'network_mode: none',
            'docx-processor-socket:/run/artifactflow/docx-processor',
        ] as $boundary) {
            $this->assertStringContainsString($boundary, $processor);
        }
        $this->assertStringNotContainsString('ports:', $processor);
        $this->assertStringNotContainsString('networks:', $processor);

        $this->assertStringContainsString('DOCX_PROCESSOR_ENABLED: ${DOCX_PROCESSOR_ENABLED:-false}', $app);
        $this->assertStringContainsString('docx-processor-socket:/run/artifactflow/docx-processor:ro', $app);
    }

    public function test_protocol_preflight_and_converter_are_fail_closed(): void
    {
        $source = $this->readProjectFile('docx-processor/src/DocxProcessor.php');
        $index = $this->readProjectFile('docx-processor/public/index.php');
        $healthcheck = $this->readProjectFile('docx-processor/healthcheck.php');
        $start = $this->readProjectFile('docx-processor/start.sh');

        foreach ([
            'artifactflow-docx-processor-request-v1',
            'artifactflow-docx-processor-response-v1',
            'docx-passive-pdf-v1',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'MAX_INPUT_BYTES',
            'MAX_OUTPUT_BYTES',
            'MAX_EXPANDED_BYTES',
            'MAX_ENTRIES',
            'MAX_MEDIA_PIXELS',
            'RELATIONSHIPS_NAMESPACE',
            'CONTENT_TYPES_NAMESPACE',
            'internalRelationshipTarget',
            'rejectActiveFields',
            'artifactflow-docx-processor-health-v1',
            'verifyNetworkIsolation',
            "'/sys/class/net/*/operstate'",
            "trim(\$operationalState) !== 'down'",
            "posix_kill(-\$pid, SIGKILL)",
            "'/usr/bin/setsid'",
            "\$handle = @fopen(\$directory . '/' . \$nonce, 'x')",
        ] as $boundary) {
            $this->assertStringContainsString($boundary, $source);
        }

        $this->assertStringContainsString("\$path !== '/v1/docx/previews'", $index);
        $this->assertStringContainsString("\$path === '/health'", $index);
        $this->assertStringContainsString('ProcessorHealthRequest::fromGlobals', $index);
        $this->assertStringContainsString('ProcessorContainment::verifyNetworkIsolation', $index);
        $this->assertStringContainsString('catch (ProcessorAuthenticationFailure)', $index);
        $this->assertStringContainsString('catch (ProcessorRejection $exception)', $index);
        $this->assertStringContainsString('catch (ProcessorUnavailable $exception)', $index);
        $this->assertStringContainsString('$exception->diagnosticCode()', $index);
        $this->assertStringContainsString('catch (Throwable $exception)', $index);
        $this->assertStringContainsString("hash('sha256', \$exception::class)", $index);
        $this->assertStringNotContainsString('getMessage()', $index);
        $this->assertStringContainsString('must stay at one HTTP worker', $start);
        $this->assertStringContainsString('unset PHP_CLI_SERVER_WORKERS', $start);
        $this->assertStringContainsString('UNIX-LISTEN:', $start);
        $this->assertStringNotContainsString('0.0.0.0:', $start);
        $this->assertStringContainsString('ProcessorConfiguration::fromEnvironment()', $healthcheck);
        $this->assertStringContainsString('ProcessorHealthRequest(bin2hex(random_bytes(16)))', $healthcheck);
        $this->assertStringContainsString('->signedHeaders($configuration)', $healthcheck);
        $this->assertStringContainsString('stream_socket_client(', $healthcheck);
        $this->assertStringContainsString('MAX_HEALTH_RESPONSE_BYTES', $healthcheck);
        $this->assertStringContainsString("json_decode(\$body, true, 8, JSON_THROW_ON_ERROR)", $healthcheck);
        $this->assertStringContainsString("'containment'] ?? null) !== 'network-isolated'", $healthcheck);
        $this->assertStringContainsString("'profile'] ?? null) !== 'docx-passive-pdf-v1'", $healthcheck);
        $this->assertStringNotContainsString('is_socket(', $healthcheck);
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        return $contents;
    }

    private function serviceBlock(string $compose, string $service, string $nextService): string
    {
        $matched = preg_match(
            sprintf('/\n  %s:(?<block>.*?)\n  %s:/s', preg_quote($service, '/'), preg_quote($nextService, '/')),
            $compose,
            $matches,
        );
        $this->assertSame(1, $matched);

        return $matches['block'];
    }
}
