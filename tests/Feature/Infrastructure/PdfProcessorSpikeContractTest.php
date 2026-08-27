<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

final class PdfProcessorSpikeContractTest extends TestCase
{
    private const string PDFBOX_VERSION = '3.0.8';

    private const string PDFBOX_SHA512 = '768847238f683568507bf73570a2b6fedcbe58b25c7b4f97fba536ba110b290fe96ba065aed58629d41fb94857d76bc1978c2f31d294b553c69f287f71ee9600';

    public function test_spike_is_pinned_and_local_topology_remains_networkless(): void
    {
        $dockerfile = $this->readProjectFile('pdf-processor-spike/Dockerfile');
        $productionDockerfile = $this->readProjectFile('Dockerfile');
        $compose = $this->readProjectFile('docker-compose.yml');
        $makefile = $this->readProjectFile('Makefile');
        $readme = $this->readProjectFile('pdf-processor-spike/README.md');
        $timeoutHarness = $this->readProjectFile('pdf-processor-spike/timeout-harness.sh');

        $this->assertStringContainsString(' AS pdf-processor-spike', $dockerfile);
        $spikeStage = $this->afterNeedle($dockerfile, ' AS pdf-processor-spike');

        $this->assertStringContainsString('PDFBOX_VERSION=' . self::PDFBOX_VERSION, $spikeStage);
        $this->assertStringContainsString('PDFBOX_SHA512=' . self::PDFBOX_SHA512, $spikeStage);
        $this->assertStringContainsString('openjdk21-jre-headless=21.0.12_p8-r0', $spikeStage);
        $this->assertStringContainsString('"libcrypto3>=3.5.8-r0"', $spikeStage);
        $this->assertStringContainsString('"libssl3>=3.5.8-r0"', $spikeStage);
        $this->assertStringContainsString('"openssl>=3.5.8-r0"', $spikeStage);
        $this->assertStringContainsString('COPY --from=pdf-processor-spike-builder', $spikeStage);
        $this->assertStringContainsString('COPY README.md', $spikeStage);
        $this->assertStringContainsString('USER pdf-spike', $spikeStage);
        $this->assertStringNotContainsString('COPY app ', $spikeStage);
        $this->assertStringNotContainsString('COPY . ', $spikeStage);

        $this->assertStringNotContainsString('pdf-processor-spike:', $compose);
        $this->assertStringNotContainsString('pdf-processor-spike', $productionDockerfile);
        $this->assertStringContainsString('both reviewed processor topologies', $readme);
        $this->assertStringContainsString('private-network target', $readme);
        $this->assertStringContainsString('Passing proves only the image-level', $readme);
        $this->assertStringContainsString('production deployment must still prove private-only reachability', $readme);

        $this->assertStringContainsString('pdf-processor-spike-build:', $makefile);
        $this->assertStringContainsString('pdf-processor-spike-test:', $makefile);
        $this->assertStringContainsString('-f pdf-processor-spike/Dockerfile', $makefile);
        $this->assertStringContainsString('pdf-processor-spike', $makefile);
        $this->assertStringContainsString('--network none', $makefile);
        $this->assertStringContainsString('--read-only', $makefile);
        $this->assertStringContainsString('--cap-drop ALL', $makefile);
        $this->assertStringContainsString('--security-opt no-new-privileges', $makefile);
        $this->assertStringContainsString('--pids-limit 32', $makefile);
        $this->assertStringContainsString('--memory 512m', $makefile);
        $this->assertStringContainsString('--cpus 1', $makefile);
        $this->assertStringContainsString('--tmpfs /tmp:rw,noexec,nosuid,size=32m', $makefile);
        $this->assertStringContainsString('127.0.0.1', $makefile);
        $this->assertStringContainsString('169.254.169.254', $makefile);
        $this->assertStringContainsString('example.com', $makefile);
        $this->assertStringContainsString('network probe unexpectedly succeeded', $makefile);
        $this->assertStringContainsString('timeout-harness.sh', $makefile);
        $this->assertStringContainsString('hang-for-timeout-test', $timeoutHarness);
        $this->assertStringContainsString('docker kill', $timeoutHarness);
        $this->assertStringContainsString('hard timeout stopped hung processor', $timeoutHarness);
    }

    public function test_spike_source_has_no_process_or_network_escape_api(): void
    {
        $source = $this->readProjectFile('pdf-processor-spike/src/main/java/app/artifactflow/pdfspike/Main.java');

        foreach ([
            'ProcessBuilder',
            'Runtime.getRuntime',
            'java.net',
            'Socket',
            'URLConnection',
            'HttpClient',
        ] as $forbiddenApi) {
            $this->assertStringNotContainsString($forbiddenApi, $source);
        }

        $this->assertStringContainsString('MAX_INPUT_BYTES', $source);
        $this->assertStringContainsString('MAX_PAGES', $source);
        $this->assertStringContainsString('MAX_TEXT_CHARACTERS', $source);
        $this->assertStringContainsString('readNBytes(MAX_INPUT_BYTES + 1)', $source);
        $this->assertStringNotContainsString('Files.readAllBytes', $source);
        $this->assertStringContainsString('Loader.loadPDF', $source);
        $this->assertStringContainsString('PDFTextStripper', $source);
        $this->assertStringContainsString('self-test', $source);
        $this->assertStringContainsString('createAcroFormPdf', $source);
        $this->assertStringContainsString('createEmbeddedFilesPdf', $source);
        $this->assertStringContainsString('createExternalUriActionPdf', $source);
        $this->assertStringContainsString('createDeepObjectGraphPdf', $source);
        $this->assertStringContainsString('output cap', $source);
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
