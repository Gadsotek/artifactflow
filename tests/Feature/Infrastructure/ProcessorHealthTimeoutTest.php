<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

final class ProcessorHealthTimeoutTest extends TestCase
{
    /**
     * The PDF and DOCX /health handlers run a real engine operation (a PDFBox
     * decode, a `soffice --version`). For a constrained deployment to ever
     * report healthy, the probe's socket read timeout must sit above the
     * engine's declared health deadline and below the outer HEALTHCHECK command
     * timeout, which is itself capped at the 15s outer ceiling. Read the real
     * engine deadlines from source so a wider engine window that outgrows the
     * probe fails here instead of only failing in production.
     */
    public function test_socket_probe_read_timeout_exceeds_the_engine_deadline_and_fits_under_the_outer_timeout(): void
    {
        $cases = [
            [
                'probe' => 'pdf-processor-spike/healthcheck.php',
                'dockerfile' => 'pdf-processor-spike/Dockerfile',
                'engineDeadline' => $this->intFromSource(
                    'pdf-processor-spike/src/PdfProcessor.php',
                    '/timeoutSeconds:\s*(\d+)/',
                    'PDF engine timeoutSeconds',
                ),
            ],
            [
                'probe' => 'docx-processor/healthcheck.php',
                'dockerfile' => 'docx-processor/Dockerfile',
                'engineDeadline' => $this->intFromSource(
                    'docx-processor/src/DocxProcessor.php',
                    '/HEALTH_TIMEOUT_SECONDS\s*=\s*(\d+)/',
                    'DOCX health engine deadline',
                ),
            ],
        ];

        foreach ($cases as $case) {
            $inner = $this->intFromSource(
                $case['probe'],
                '/stream_set_timeout\(\$socket,\s*(\d+)\)/',
                "socket read timeout in {$case['probe']}",
            );
            $outer = $this->intFromSource(
                $case['dockerfile'],
                '/HEALTHCHECK[^\n]*--timeout=(\d+)s/',
                "HEALTHCHECK timeout in {$case['dockerfile']}",
            );

            $this->assertGreaterThan(
                $case['engineDeadline'],
                $inner,
                "The {$case['probe']} read timeout must exceed the {$case['engineDeadline']}s engine health deadline.",
            );
            $this->assertLessThan(
                $outer,
                $inner,
                "The {$case['probe']} read timeout must be below the HEALTHCHECK command timeout, or the response is cut off.",
            );
            $this->assertLessThanOrEqual(
                15,
                $outer,
                "The {$case['dockerfile']} HEALTHCHECK timeout must stay at or under the 15s outer ceiling.",
            );
        }
    }

    private function intFromSource(string $relativePath, string $pattern, string $label): int
    {
        $source = file_get_contents(base_path($relativePath));
        $this->assertIsString($source, "Could not read {$relativePath}.");

        if (preg_match($pattern, $source, $match) !== 1) {
            $this->fail("Could not find the {$label}.");
        }

        return (int) $match[1];
    }
}
