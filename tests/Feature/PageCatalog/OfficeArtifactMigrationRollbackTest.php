<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class OfficeArtifactMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_removes_office_schema_after_office_pages_are_gone(): void
    {
        $markdown = Page::factory()->create(['type' => PageType::Markdown]);

        $migration = require database_path('migrations/2026_08_30_000001_add_document_artifact_types_and_xlsx_facts.php');
        $this->assertIsObject($migration);

        (new ReflectionMethod($migration, 'down'))->invoke($migration);

        $this->assertFalse(Schema::hasTable('page_version_derivatives'));
        $this->assertFalse(Schema::hasTable('xlsx_version_facts'));
        $this->assertFalse(Schema::hasTable('docx_version_facts'));
        $this->assertTrue($markdown->fresh()?->type === PageType::Markdown);
    }

    public function test_rollback_refuses_to_destroy_office_artifact_schema_while_office_pages_exist(): void
    {
        $xlsx = Page::factory()->create(['type' => PageType::Xlsx]);
        $docx = Page::factory()->create(['type' => PageType::Docx]);

        $migration = require database_path('migrations/2026_08_30_000001_add_document_artifact_types_and_xlsx_facts.php');
        $this->assertIsObject($migration);

        try {
            (new ReflectionMethod($migration, 'down'))->invoke($migration);
            $this->fail('Rollback must fail before deleting Office artifact schema or page data.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Cannot roll back XLSX/DOCX support while Office artifact pages exist. Delete those pages deliberately before retrying the downgrade.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasTable('page_version_derivatives'));
        $this->assertTrue(Schema::hasTable('xlsx_version_facts'));
        $this->assertTrue(Schema::hasTable('docx_version_facts'));
        $this->assertTrue($xlsx->fresh()?->type === PageType::Xlsx);
        $this->assertTrue($docx->fresh()?->type === PageType::Docx);
    }

    public function test_media_count_limit_rollback_restores_the_preceding_validated_constraint(): void
    {
        $migration = require database_path('migrations/2026_08_31_000001_raise_docx_media_count_limit.php');
        $this->assertIsObject($migration);

        (new ReflectionMethod($migration, 'down'))->invoke($migration);

        $constraint = DB::table('pg_constraint')
            ->selectRaw('pg_get_constraintdef(oid) AS definition, convalidated AS validated')
            ->whereRaw("conrelid = 'docx_version_facts'::regclass")
            ->where('conname', 'docx_version_facts_counts_check')
            ->first();
        $this->assertNotNull($constraint);

        $definition = $constraint->definition ?? null;
        $validated = $constraint->validated ?? null;
        $this->assertIsString($definition);
        $this->assertMatchesRegularExpression('/media_count <= 1024/u', $definition);
        $this->assertTrue($validated === true);
    }
}
