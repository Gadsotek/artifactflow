<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PdfExtractionState;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PdfVersionFact;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

final class PdfArtifactSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_version_facts_extend_an_immutable_version_without_duplicating_original_storage(): void
    {
        foreach (['page_version_uid', 'page_count', 'pdf_version', 'extraction_state', 'processor_profile'] as $column) {
            $this->assertTrue(Schema::hasColumn('pdf_version_facts', $column));
        }

        $this->assertFalse(Schema::hasColumn('pdf_version_facts', 'uid'));
        $this->assertFalse(Schema::hasColumn('pdf_version_facts', 'id'));
        $this->assertFalse(Schema::hasColumn('pdf_version_facts', 'original_storage_path'));
        $this->assertFalse(Schema::hasColumn('pdf_version_facts', 'original_hash'));
        $this->assertFalse(Schema::hasColumn('pdf_version_facts', 'original_byte_size'));

        $version = PageVersion::factory()->create();
        $fact = PdfVersionFact::query()->forceCreate([
            'page_version_uid' => $version->uid,
            'page_count' => 7,
            'pdf_version' => '1.7',
            'extraction_state' => PdfExtractionState::Indexed,
            'processor_profile' => 'pdfbox-3.0.8/text-v1',
        ]);

        $this->assertSame(7, $fact->page_count);
        $this->assertSame(PdfExtractionState::Indexed, $fact->extraction_state);
        $this->assertTrue($version->pdfFacts()->sole()->is($fact));

        $version->delete();
        $this->assertDatabaseMissing('pdf_version_facts', ['page_version_uid' => $version->uid]);
    }

    #[DataProvider('invalidPdfFactProvider')]
    public function test_pdf_fact_constraints_reject_impossible_states(string $field, int|string $invalidValue): void
    {
        $version = PageVersion::factory()->create();
        $row = [
            'page_version_uid' => $version->uid,
            'page_count' => 1,
            'pdf_version' => '1.7',
            'extraction_state' => PdfExtractionState::Indexed->value,
            'processor_profile' => 'pdfbox-3.0.8/text-v1',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $row[$field] = $invalidValue;

        $this->expectException(QueryException::class);

        DB::table('pdf_version_facts')->insert($row);
    }

    public function test_pdf_extraction_states_are_closed_and_user_honest(): void
    {
        $this->assertSame(
            ['indexed', 'no_embedded_text', 'partially_indexed'],
            array_column(PdfExtractionState::cases(), 'value'),
        );
    }

    public function test_page_type_constraint_accepts_pdf_and_its_migration_is_reversible(): void
    {
        $page = Page::factory()->create(['type' => PageType::Pdf]);
        $this->assertSame(PageType::Pdf, $page->type);
        $page->delete();

        $migration = require base_path(
            'database/migrations/2026_08_17_000002_add_pdf_artifact_type.php',
        );
        $this->assertIsObject($migration);

        (new ReflectionMethod($migration, 'down'))->invoke($migration);
        $rejected = false;
        DB::beginTransaction();

        try {
            Page::factory()->create(['type' => PageType::Pdf]);
        } catch (QueryException) {
            $rejected = true;
        } finally {
            DB::rollBack();
        }

        (new ReflectionMethod($migration, 'up'))->invoke($migration);
        $this->assertTrue($rejected, 'The previous page type constraint must reject PDF.');

        $restored = Page::factory()->create(['type' => PageType::Pdf]);
        $this->assertSame(PageType::Pdf, $restored->type);
    }

    public function test_pdf_facts_migration_is_reversible(): void
    {
        $migration = require base_path(
            'database/migrations/2026_08_17_000001_create_pdf_version_facts_table.php',
        );
        $this->assertIsObject($migration);

        (new ReflectionMethod($migration, 'down'))->invoke($migration);
        $this->assertFalse(Schema::hasTable('pdf_version_facts'));

        (new ReflectionMethod($migration, 'up'))->invoke($migration);
        $this->assertTrue(Schema::hasTable('pdf_version_facts'));
    }

    /**
     * @return array<string, array{0: string, 1: int|string}>
     */
    public static function invalidPdfFactProvider(): array
    {
        return [
            'zero pages' => ['page_count', 0],
            'malformed PDF version' => ['pdf_version', 'not-a-version'],
            'dishonest extraction state' => ['extraction_state', 'safe'],
            'blank processor profile' => ['processor_profile', ''],
        ];
    }
}
