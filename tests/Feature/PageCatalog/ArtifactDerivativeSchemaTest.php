<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Domain\PageCatalog\ArtifactDerivativeKind;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PdfExtractionState;
use App\Models\DocxVersionFact;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use App\Models\XlsxVersionFact;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ArtifactDerivativeSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_xlsx_version_owns_one_typed_manifest_derivative_and_bounded_facts(): void
    {
        $page = Page::factory()->create(['type' => PageType::Xlsx]);
        $version = PageVersion::factory()->forPage($page)->create();
        $derivative = PageVersionDerivative::query()->forceCreate([
            'uid' => (string) Str::ulid(),
            'page_version_uid' => $version->uid,
            'kind' => ArtifactDerivativeKind::XlsxManifest,
            'storage_path' => sprintf('pages/%s/versions/1/manifest.json', $page->uid),
            'content_hash' => str_repeat('a', 64),
            'byte_size' => 512,
        ]);
        $facts = XlsxVersionFact::query()->forceCreate([
            'page_version_uid' => $version->uid,
            'manifest_derivative_uid' => $derivative->uid,
            'processor_profile' => 'xlsx-typed-view-v1',
            'manifest_schema' => 'xlsx-view-manifest-v1',
            'engine_name' => 'sheetjs-ce',
            'engine_version' => '0.20.3',
            'package_entry_count' => 9,
            'expanded_bytes' => 2_048,
            'visible_sheet_count' => 1,
            'omitted_hidden_sheet_count' => 0,
            'projected_row_extent_count' => 2,
            'projected_column_extent_count' => 3,
            'omitted_hidden_row_count' => 0,
            'omitted_hidden_column_count' => 0,
            'cell_count' => 4,
            'formula_count' => 1,
            'uncached_formula_count' => 0,
            'link_count' => 1,
            'merge_count' => 1,
            'truncated' => false,
            'processed_at' => now(),
        ]);

        $this->assertTrue($version->derivatives()->sole()->is($derivative));
        $this->assertTrue($version->xlsxFacts()->sole()->is($facts));
        $this->assertTrue($derivative->pageVersion()->sole()->is($version));
        $this->assertTrue($facts->pageVersion()->sole()->is($version));
        $this->assertTrue($facts->manifestDerivative->is($derivative));
        $this->assertSame(ArtifactDerivativeKind::XlsxManifest, $derivative->kind);
        $this->assertSame(ArtifactDerivativeKind::XlsxManifest, $facts->manifest_derivative_kind);
        $this->assertFalse($facts->truncated);
    }

    public function test_derivative_kind_is_unique_per_version_and_xlsx_page_type_is_database_constrained(): void
    {
        $page = Page::factory()->create(['type' => PageType::Xlsx]);
        $version = PageVersion::factory()->forPage($page)->create();
        $attributes = [
            'page_version_uid' => $version->uid,
            'kind' => ArtifactDerivativeKind::XlsxManifest,
            'storage_path' => sprintf('pages/%s/versions/1/manifest.json', $page->uid),
            'content_hash' => str_repeat('b', 64),
            'byte_size' => 2,
        ];

        PageVersionDerivative::query()->forceCreate(['uid' => (string) Str::ulid()] + $attributes);
        $attributes['storage_path'] = sprintf('pages/%s/versions/1/other.json', $page->uid);

        $this->expectException(QueryException::class);
        PageVersionDerivative::query()->forceCreate(['uid' => (string) Str::ulid()] + $attributes);
    }

    public function test_docx_version_owns_one_typed_preview_derivative_and_bounded_facts(): void
    {
        $page = Page::factory()->create(['type' => PageType::Docx]);
        $version = PageVersion::factory()->forPage($page)->create();
        $derivative = PageVersionDerivative::query()->forceCreate([
            'uid' => (string) Str::ulid(),
            'page_version_uid' => $version->uid,
            'kind' => ArtifactDerivativeKind::DocxPreviewPdf,
            'storage_path' => sprintf('pages/%s/versions/1/preview.pdf', $page->uid),
            'content_hash' => str_repeat('c', 64),
            'byte_size' => 1_024,
        ]);
        $facts = DocxVersionFact::query()->forceCreate([
            'page_version_uid' => $version->uid,
            'preview_derivative_uid' => $derivative->uid,
            'docx_processor_profile' => 'docx-passive-pdf-v1',
            'pdf_processor_profile' => 'pdfbox-3.0.8-docx-preview-v1',
            'engine_name' => 'libreoffice',
            'engine_version' => '26.2.5',
            'package_entry_count' => 8,
            'expanded_bytes' => 4_096,
            'relationship_count' => 7,
            'media_count' => 2,
            'external_hyperlink_count' => 1,
            'page_count' => 2,
            'pdf_version' => '1.7',
            'extraction_state' => PdfExtractionState::Indexed,
            'processed_at' => now(),
        ]);

        $this->assertTrue($version->derivatives()->sole()->is($derivative));
        $this->assertTrue($version->docxFacts()->sole()->is($facts));
        $this->assertTrue($facts->pageVersion()->sole()->is($version));
        $this->assertTrue($facts->previewDerivative->is($derivative));
        $this->assertSame(ArtifactDerivativeKind::DocxPreviewPdf, $derivative->kind);
        $this->assertSame(ArtifactDerivativeKind::DocxPreviewPdf, $facts->preview_derivative_kind);
    }

    public function test_format_facts_cannot_bind_a_derivative_from_another_version_or_kind(): void
    {
        $page = Page::factory()->create(['type' => PageType::Xlsx]);
        $version = PageVersion::factory()->forPage($page)->create();
        $otherVersion = PageVersion::factory()->forPage($page)->create(['version_number' => 2]);
        $wrongDerivative = PageVersionDerivative::query()->forceCreate([
            'uid' => (string) Str::ulid(),
            'page_version_uid' => $otherVersion->uid,
            'kind' => ArtifactDerivativeKind::DocxPreviewPdf,
            'storage_path' => sprintf('pages/%s/versions/2/preview.pdf', $page->uid),
            'content_hash' => str_repeat('d', 64),
            'byte_size' => 256,
        ]);

        $this->expectException(QueryException::class);
        XlsxVersionFact::query()->forceCreate([
            'page_version_uid' => $version->uid,
            'manifest_derivative_uid' => $wrongDerivative->uid,
            'processor_profile' => 'xlsx-typed-view-v1',
            'manifest_schema' => 'xlsx-view-manifest-v1',
            'engine_name' => 'sheetjs-ce',
            'engine_version' => '0.20.3',
            'package_entry_count' => 3,
            'expanded_bytes' => 1_024,
            'visible_sheet_count' => 1,
            'omitted_hidden_sheet_count' => 0,
            'projected_row_extent_count' => 1,
            'projected_column_extent_count' => 1,
            'omitted_hidden_row_count' => 0,
            'omitted_hidden_column_count' => 0,
            'cell_count' => 1,
            'formula_count' => 0,
            'uncached_formula_count' => 0,
            'link_count' => 0,
            'merge_count' => 0,
            'truncated' => false,
            'processed_at' => now(),
        ]);
    }

    public function test_docx_facts_require_the_indexed_native_text_profile(): void
    {
        $page = Page::factory()->create(['type' => PageType::Docx]);
        $version = PageVersion::factory()->forPage($page)->create();
        $derivative = PageVersionDerivative::query()->forceCreate([
            'uid' => (string) Str::ulid(),
            'page_version_uid' => $version->uid,
            'kind' => ArtifactDerivativeKind::DocxPreviewPdf,
            'storage_path' => sprintf('pages/%s/versions/1/preview.pdf', $page->uid),
            'content_hash' => str_repeat('e', 64),
            'byte_size' => 512,
        ]);

        $this->expectException(QueryException::class);
        DocxVersionFact::query()->forceCreate([
            'page_version_uid' => $version->uid,
            'preview_derivative_uid' => $derivative->uid,
            'docx_processor_profile' => 'docx-passive-pdf-v1',
            'pdf_processor_profile' => 'pdfbox-3.0.8-docx-preview-v1',
            'engine_name' => 'libreoffice',
            'engine_version' => '26.2.5',
            'package_entry_count' => 3,
            'expanded_bytes' => 2_048,
            'relationship_count' => 1,
            'media_count' => 0,
            'external_hyperlink_count' => 0,
            'page_count' => 1,
            'pdf_version' => '1.7',
            'extraction_state' => PdfExtractionState::NoEmbeddedText,
            'processed_at' => now(),
        ]);
    }
}
