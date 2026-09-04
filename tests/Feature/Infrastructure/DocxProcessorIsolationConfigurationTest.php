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

        $this->assertStringContainsString('FROM dunglas/frankenphp:builder-php8.5-alpine@sha256:', $dockerfile);
        $this->assertStringContainsString('FROM dunglas/frankenphp:1-php8.5-alpine@sha256:', $dockerfile);
        $this->assertStringContainsString("go1\\.26\\.7[[:space:]]", $dockerfile);
        $this->assertStringContainsString('go get google.golang.org/grpc@v1.83.1', $dockerfile);
        $this->assertStringContainsString("grep -E 'google\\.golang\\.org/grpc", $dockerfile);
        $this->assertStringContainsString("RUN install-php-extensions \\\n    pcntl \\\n    zip", $dockerfile);
        $this->assertStringNotContainsString('docker-php-ext-install', $dockerfile);
        $this->assertStringContainsString('libreoffice-writer=25.8.7.3-r0', $dockerfile);
        $this->assertStringContainsString('font-dejavu=2.37-r6', $dockerfile);
        $this->assertStringContainsString('font-liberation=2.1.5-r2', $dockerfile);
        $this->assertStringContainsString('tzdata=2026c-r0', $dockerfile);
        $this->assertStringContainsString('util-linux=2.42.1-r0', $dockerfile);
        $this->assertStringContainsString('"libcrypto3>=3.5.8-r0"', $dockerfile);
        $this->assertStringContainsString('"libssl3>=3.5.8-r0"', $dockerfile);
        $this->assertStringContainsString('"openssl>=3.5.8-r0"', $dockerfile);
        $this->assertStringNotContainsString('apt-get', $dockerfile);
        $this->assertStringNotContainsString('dpkg', $dockerfile);
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
        $this->assertSame(2, substr_count($index, 'ProcessorContainment::verifyNetworkIsolation'));
        $this->assertStringContainsString('catch (ProcessorAuthenticationFailure)', $index);
        $this->assertStringContainsString('catch (ProcessorRejection $exception)', $index);
        $this->assertStringContainsString('catch (ProcessorUnavailable $exception)', $index);
        $this->assertStringContainsString('$exception->diagnosticCode()', $index);
        $this->assertStringContainsString('catch (Throwable $exception)', $index);
        $this->assertStringContainsString("hash('sha256', \$exception::class)", $index);
        $this->assertStringNotContainsString('getMessage()', $index);
        $this->assertStringContainsString('frankenphp run', $start);
        $this->assertStringContainsString('${PHP_CLI_SERVER_WORKERS:-1}', $start);
        $this->assertStringContainsString('chmod 0660 "$socket_path"', $start);
        $this->assertStringNotContainsString('php -S', $start);
        $this->assertStringNotContainsString('socat', $start);
        $this->assertStringNotContainsString('php-fpm', $start);
        $this->assertStringNotContainsString('nginx', $start);
        $caddy = $this->readProjectFile('docx-processor/Caddyfile');
        $this->assertStringContainsString('frankenphp {', $caddy);
        $this->assertStringContainsString('num_threads 1', $caddy);
        $this->assertStringContainsString('max_threads 1', $caddy);
        $this->assertStringContainsString('bind {$DOCX_PROCESSOR_BIND}', $caddy);
        $this->assertStringContainsString('unix/${socket_path}|0660', $start);
        $this->assertStringContainsString('max_size 17MB', $caddy);
        $this->assertStringContainsString('php_server', $caddy);
        $this->assertStringContainsString('memory_limit=384M', $this->readProjectFile('docx-processor/docx-processor.ini'));
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

    public function test_engine_version_is_identical_across_the_image_service_and_application_protocol(): void
    {
        $version = '25.8.7.3';

        $this->assertStringContainsString(
            "public const string ENGINE_VERSION = '{$version}';",
            $this->readProjectFile('docx-processor/src/DocxProcessor.php'),
        );
        $this->assertStringContainsString(
            "public const string ENGINE_VERSION = '{$version}';",
            $this->readProjectFile('app/Application/PageCatalog/DocxProcessorProtocol.php'),
        );
        $this->assertStringContainsString(
            'libreoffice-writer=' . $version . '-r0',
            $this->readProjectFile('docx-processor/Dockerfile'),
        );
        $this->assertStringContainsString(
            '"version":"' . $version . '"',
            $this->readProjectFile('docx-processor/public/index.php'),
        );
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
