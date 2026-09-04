<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

final class XlsxProcessorSpikeContractTest extends TestCase
{
    public function test_processor_is_pinned_and_wired_as_a_networkless_bounded_service(): void
    {
        $dockerfile = $this->readProjectFile('xlsx-processor-spike/Dockerfile');
        $productionDockerfile = $this->readProjectFile('Dockerfile');
        $compose = $this->readProjectFile('docker-compose.yml');
        $package = $this->readProjectFile('xlsx-processor-spike/package.json');
        $lock = $this->readProjectFile('xlsx-processor-spike/package-lock.json');
        $makefile = $this->readProjectFile('Makefile');
        $readme = $this->readProjectFile('xlsx-processor-spike/README.md');
        $notices = $this->readProjectFile('THIRD_PARTY_NOTICES.md');
        $vite = $this->readProjectFile('vite.config.js');
        $viewerCss = $this->readProjectFile('resources/css/xlsx-viewer.css');
        $saxesLicense = $this->readProjectFile('xlsx-processor-spike/licenses/saxes-6.0.0-LICENSE');
        $processor = $this->serviceBlock($compose, 'xlsx-processor', 'docx-processor');
        $app = $this->serviceBlock($compose, 'app', 'artifact-host');

        $this->assertStringContainsString(' AS xlsx-processor-base', $dockerfile);
        $stage = $this->afterNeedle($dockerfile, ' AS xlsx-processor-base');

        $this->assertStringContainsString('node:26-alpine@sha256:', $dockerfile);
        $this->assertStringContainsString('apk upgrade --no-cache', $stage);
        $this->assertStringContainsString('npm ci --ignore-scripts', $stage);
        $this->assertStringContainsString('COPY package.json package-lock.json', $stage);
        $this->assertStringContainsString('COPY --chown=xlsx-spike:xlsx-spike src ./src', $stage);
        $this->assertStringContainsString('COPY --chown=xlsx-spike:xlsx-spike licenses ./licenses', $stage);
        $this->assertStringContainsString('USER xlsx-spike', $stage);
        $this->assertStringContainsString('FROM xlsx-processor-base AS xlsx-processor-spike', $stage);
        $testStage = $this->afterNeedle($stage, 'FROM xlsx-processor-base AS xlsx-processor-spike');
        $this->assertStringContainsString('COPY --chown=xlsx-spike:xlsx-spike test ./test', $testStage);
        $this->assertStringContainsString('CMD ["npm", "test"]', $testStage);
        $this->assertStringContainsString(' AS xlsx-processor-spike-service', $stage);
        $serviceStage = $this->afterNeedle($stage, ' AS xlsx-processor-spike-service');
        $this->assertStringContainsString('npm uninstall --global npm', $serviceStage);
        $this->assertStringNotContainsString('COPY --chown=xlsx-spike:xlsx-spike test ./test', $serviceStage);
        $this->assertStringContainsString('CMD ["node", "src/start-server.cjs"]', $stage);
        $this->assertStringNotContainsString('COPY app ', $stage);
        $this->assertStringNotContainsString('COPY . ', $stage);

        $this->assertStringContainsString('xlsx-0.20.3.tgz', $package);
        $this->assertStringContainsString('xlsx-0.20.3.tgz', $lock);
        $this->assertStringContainsString('"saxes": "6.0.0"', $package);
        $this->assertStringContainsString('node_modules/saxes', $lock);
        $this->assertStringContainsString('integrity', $lock);
        $this->assertStringContainsString('Copyright (c) Contributors', $saxesLicense);
        $this->assertStringContainsString('copyright notice and this permission notice appear in all copies', $saxesLicense);
        $this->assertStringContainsString('Saxes 6.0.0', $notices);
        $this->assertStringContainsString('/srv/xlsx-processor-spike/licenses/saxes-6.0.0-LICENSE', $notices);
        $this->assertStringContainsString('COPY LICENSE THIRD_PARTY_NOTICES.md ./', $productionDockerfile);
        $this->assertStringContainsString('Tabulator 6.5.0', $vite);
        $this->assertStringContainsString('Permission is hereby granted', $vite);
        $this->assertStringContainsString('enforceStandaloneXlsxViewer()', $vite);
        $this->assertStringContainsString("output.facadeModuleId?.endsWith('/resources/js/xlsx-viewer.js')", $vite);
        $this->assertStringContainsString('viewer.imports.length > 0 || viewer.dynamicImports.length > 0', $vite);
        $this->assertStringContainsString('Tabulator 6.5.0', $viewerCss);
        $this->assertStringContainsString('Permission is hereby granted', $viewerCss);

        $this->assertStringContainsString('target: xlsx-processor-spike-service', $processor);
        $this->assertStringContainsString('image: artifactflow-xlsx-processor-service:local', $processor);
        $this->assertStringContainsString('read_only: true', $processor);
        $this->assertStringContainsString('/tmp:rw,noexec,nosuid,size=64m', $processor);
        $this->assertStringContainsString('no-new-privileges:true', $processor);
        $this->assertStringContainsString('- ALL', $processor);
        $this->assertStringContainsString('pids_limit: 32', $processor);
        $this->assertStringContainsString('mem_limit: 384m', $processor);
        $this->assertStringContainsString('cpus: 1.0', $processor);
        $this->assertStringContainsString('network_mode: none', $processor);
        $this->assertStringContainsString('xlsx-processor-socket:/run/artifactflow/xlsx-processor', $processor);
        $this->assertStringNotContainsString('ports:', $processor);
        $this->assertStringNotContainsString('networks:', $processor);
        $this->assertStringContainsString('XLSX_PROCESSOR_ENABLED: ${XLSX_PROCESSOR_ENABLED:-false}', $app);
        $this->assertStringContainsString('xlsx-processor-socket:/run/artifactflow/xlsx-processor:ro', $app);
        $this->assertStringNotContainsString('xlsx-processor-spike', $productionDockerfile);
        $this->assertStringContainsString("ArtifactFlow's dedicated XLSX processor", $readme);
        $this->assertStringContainsString('default-off', $readme);
        $this->assertStringContainsString('Unix socket', $readme);
        $this->assertStringContainsString('process-group timeout', $readme);
        $this->assertStringContainsString('--target xlsx-processor-spike', $makefile);
        $this->assertStringContainsString('$(XLSX_PROCESSOR_TEST_IMAGE)', $makefile);
        $this->assertStringContainsString('test ! -e /srv/xlsx-processor-spike/test', $makefile);

        foreach ([
            '--network none',
            '--read-only',
            '--cap-drop ALL',
            '--security-opt no-new-privileges',
            '--pids-limit 32',
            '--memory 384m',
            '--cpus 1',
            '--tmpfs /tmp:rw,noexec,nosuid,size=64m',
        ] as $requiredRuntimeBoundary) {
            $this->assertStringContainsString($requiredRuntimeBoundary, $readme);
        }
    }

    public function test_pid_budget_allows_the_listener_worker_and_node_health_probe_to_overlap(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');
        $makefile = $this->readProjectFile('Makefile');
        $localProcessor = $this->serviceBlock($compose, 'xlsx-processor', 'docx-processor');
        $e2eProcessor = $this->serviceBlock($compose, 'e2e-xlsx-processor', 'e2e-docx-processor');
        $runtimeTest = $this->betweenNeedles(
            $makefile,
            "xlsx-processor-service-runtime-test:\n",
            "\ndocx-processor-build:",
        );

        // A Node listener, projection child, and Docker's Node health probe each
        // use seven cgroup tasks. A 16-task limit made a probe overlapping an
        // upload fail pthread creation and stall the upload until its timeout.
        foreach ([$localProcessor, $e2eProcessor] as $processor) {
            $this->assertStringContainsString('pids_limit: 32', $processor);
            $this->assertStringNotContainsString('pids_limit: 16', $processor);
        }
        $this->assertGreaterThanOrEqual(2, substr_count($runtimeTest, '--pids-limit 32'));
        $this->assertStringNotContainsString('--pids-limit 16', $runtimeTest);
        $this->assertStringContainsString(
            'XLSX processor health probe overlapped a Node worker within the bounded PID budget.',
            $runtimeTest,
        );
    }

    public function test_projection_source_has_explicit_format_and_resource_boundaries(): void
    {
        $source = implode("\n", [
            $this->readProjectFile('xlsx-processor-spike/src/project-xlsx.cjs'),
            $this->readProjectFile('xlsx-processor-spike/src/opc-profile.cjs'),
            $this->readProjectFile('xlsx-processor-spike/src/zip-package.cjs'),
        ]);

        foreach ([
            "require('node:child_process')",
            "require('node:http')",
            "require('node:https')",
            "require('node:net')",
            "require('node:tls')",
            "require('node:dns')",
            'sheet_to_html',
            'sheet_to_json',
            'eval(',
            'new Function',
        ] as $forbiddenApi) {
            $this->assertStringNotContainsString($forbiddenApi, $source);
        }

        foreach ([
            'maxInputBytes',
            'maxExpandedBytes',
            'maxCentralDirectoryBytes',
            'maxControlXmlBytes',
            'maxEntryNameBytes',
            'maxEntryPathDepth',
            'maxEntries',
            'maxRelationships',
            'maxCells',
            'maxFormulaLength',
            'maxStringBytes',
            'maxManifestBytes',
            'maxSearchTextBytes',
            'maxXmlAttributes',
            'maxXmlDepth',
            'maxXmlNodes',
            'maxXmlTextBytes',
            'cellHTML: false',
            'bookVBA: false',
            'crc32',
            'inflateRawSync',
            'SaxesParser',
            'validateOpcProfile',
            "profile: 'xlsx-typed-view-v1'",
        ] as $requiredBoundary) {
            $this->assertStringContainsString($requiredBoundary, $source);
        }
    }

    public function test_service_protocol_authenticates_exact_bytes_and_hard_stops_one_worker(): void
    {
        $protocol = $this->readProjectFile('xlsx-processor-spike/src/processor-protocol.cjs');
        $worker = $this->readProjectFile('xlsx-processor-spike/src/projection-worker.cjs');
        $starter = $this->readProjectFile('xlsx-processor-spike/src/start-server.cjs');
        $healthcheck = $this->readProjectFile('xlsx-processor-spike/healthcheck.cjs');

        foreach ([
            'artifactflow-xlsx-processor-request-v1',
            'artifactflow-xlsx-processor-response-v1',
            'artifactflow-xlsx-processor-health-v1',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.artifactflow.xlsx-manifest+json',
            'x-artifactflow-input-sha256',
            'x-artifactflow-processor-profile',
            'crypto.timingSafeEqual',
            'ReplayCache',
            'hasOnlyLoopbackInterfaces',
            "require('node:child_process')",
            'detached:',
            "process.kill(-child.pid, 'SIGKILL')",
            'processor_timeout',
            'processing = true',
            "request.url !== '/v1/xlsx/manifests'",
            "env: { NODE_ENV: 'production' }",
            'function secretFromEnvironment',
            "decoded.toString('base64') !== encoded",
        ] as $requiredBoundary) {
            $this->assertStringContainsString($requiredBoundary, $protocol);
        }

        foreach ([
            "require('node:http')",
            "require('node:https')",
            "require('node:net')",
            "require('node:tls')",
            "require('node:dns')",
            "require('node:child_process')",
            'process.env',
        ] as $forbiddenWorkerApi) {
            $this->assertStringNotContainsString($forbiddenWorkerApi, $worker);
        }

        $this->assertStringContainsString('MAX_INPUT_BYTES', $worker);
        $this->assertStringContainsString('projectXlsxWithFacts', $worker);
        $this->assertStringContainsString('XLSX_PROCESSOR_SOCKET_PATH', $starter);
        $this->assertStringContainsString('XLSX_PROCESSOR_SHARED_SECRET', $starter);
        $this->assertStringContainsString('secretFromEnvironment', $starter);
        $this->assertStringContainsString('secretFromEnvironment', $healthcheck);
        $this->assertStringContainsString('path.isAbsolute(socketPath)', $starter);
        $this->assertStringContainsString('fs.chmodSync(socketPath, 0o660)', $starter);
        $this->assertSame(1, substr_count($starter, 'server.listen('));
        $this->assertGreaterThanOrEqual(2, substr_count($protocol, 'containmentCheck()'));
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

    private function betweenNeedles(string $haystack, string $start, string $end): string
    {
        $afterStart = $this->afterNeedle($haystack, $start);
        $endPosition = strpos($afterStart, $end);
        $this->assertNotFalse($endPosition, sprintf('Expected to find [%s].', $end));

        return substr($afterStart, 0, $endPosition);
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
