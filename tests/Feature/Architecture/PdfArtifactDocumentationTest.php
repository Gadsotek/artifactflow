<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

final class PdfArtifactDocumentationTest extends TestCase
{
    public function test_unreleased_changelog_identifies_external_pdf_sharing_as_gated_not_absent(): void
    {
        $changelog = file_get_contents(base_path('CHANGELOG.md'));
        $this->assertIsString($changelog);
        $unreleased = strstr($changelog, '## v0.0.9', true);
        $this->assertIsString($unreleased);

        $this->assertStringContainsString(
            'External PDF sharing is implemented behind the default-off PDF feature gate',
            $unreleased,
        );
        $this->assertStringContainsString(
            'production enablement remain deliberately closed',
            $unreleased,
        );
        $this->assertStringNotContainsString(
            'External PDF sharing, OCR',
            $unreleased,
        );
    }
}
