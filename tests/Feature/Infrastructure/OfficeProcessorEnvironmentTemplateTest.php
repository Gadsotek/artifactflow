<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

final class OfficeProcessorEnvironmentTemplateTest extends TestCase
{
    public function test_local_environment_template_exposes_default_off_office_processor_settings(): void
    {
        $this->assertTemplateContainsSettings('.env.example', [
            'PDF_PROCESSOR_ENABLED=false',
            'PDF_PROCESSOR_URL=http://localhost',
            'PDF_PROCESSOR_SOCKET_PATH=/run/artifactflow/pdf-processor/processor.sock',
            'PDF_PROCESSOR_SHARED_SECRET=',
            'PDF_PROCESSOR_CONNECT_TIMEOUT_SECONDS=2',
            'PDF_PROCESSOR_TIMEOUT_SECONDS=15',
            'XLSX_PROCESSOR_ENABLED=false',
            'XLSX_PROCESSOR_URL=http://localhost',
            'XLSX_PROCESSOR_SOCKET_PATH=/run/artifactflow/xlsx-processor/processor.sock',
            'XLSX_PROCESSOR_SHARED_SECRET=',
            'XLSX_PROCESSOR_CONNECT_TIMEOUT_SECONDS=2',
            'XLSX_PROCESSOR_TIMEOUT_SECONDS=15',
            'DOCX_PROCESSOR_ENABLED=false',
            'DOCX_PROCESSOR_URL=http://localhost',
            'DOCX_PROCESSOR_SOCKET_PATH=/run/artifactflow/docx-processor/processor.sock',
            'DOCX_PROCESSOR_SHARED_SECRET=',
            'DOCX_PROCESSOR_CONNECT_TIMEOUT_SECONDS=2',
            'DOCX_PROCESSOR_TIMEOUT_SECONDS=35',
        ]);
    }

    public function test_production_environment_template_exposes_default_off_office_processor_settings(): void
    {
        $this->assertTemplateContainsSettings('.env.production.example', [
            'PDF_PROCESSOR_ENABLED=false',
            'PDF_PROCESSOR_URL=',
            'PDF_PROCESSOR_SOCKET_PATH=',
            'PDF_PROCESSOR_SHARED_SECRET=',
            'PDF_PROCESSOR_CONNECT_TIMEOUT_SECONDS=2',
            'PDF_PROCESSOR_TIMEOUT_SECONDS=15',
            'XLSX_PROCESSOR_ENABLED=false',
            'XLSX_PROCESSOR_URL=',
            'XLSX_PROCESSOR_SOCKET_PATH=',
            'XLSX_PROCESSOR_SHARED_SECRET=',
            'XLSX_PROCESSOR_CONNECT_TIMEOUT_SECONDS=2',
            'XLSX_PROCESSOR_TIMEOUT_SECONDS=15',
            'DOCX_PROCESSOR_ENABLED=false',
            'DOCX_PROCESSOR_URL=',
            'DOCX_PROCESSOR_SOCKET_PATH=',
            'DOCX_PROCESSOR_SHARED_SECRET=',
            'DOCX_PROCESSOR_CONNECT_TIMEOUT_SECONDS=2',
            'DOCX_PROCESSOR_TIMEOUT_SECONDS=35',
        ]);
    }

    /**
     * @param list<string> $settings
     */
    private function assertTemplateContainsSettings(string $path, array $settings): void
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        foreach ($settings as $setting) {
            $this->assertMatchesRegularExpression(
                '/^' . preg_quote($setting, '/') . '$/m',
                $contents,
                sprintf('%s must contain %s.', $path, $setting),
            );
        }
    }
}
