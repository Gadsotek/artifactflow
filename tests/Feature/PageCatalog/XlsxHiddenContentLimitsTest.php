<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Domain\PageCatalog\ArtifactDerivativeKind;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use App\Models\XlsxVersionFact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class XlsxHiddenContentLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_retained_facts_accept_bounded_hidden_content_beyond_preview_limits(): void
    {
        $facts = $this->facts();
        $facts->forceFill([
            'omitted_hidden_sheet_count' => 20,
            'omitted_hidden_row_count' => 1_048_576,
            'omitted_hidden_column_count' => 16_128,
        ])->save();
        $facts->refresh();

        $this->assertSame(20, $facts->omitted_hidden_sheet_count);
        $this->assertSame(1_048_576, $facts->omitted_hidden_row_count);
        $this->assertSame(16_128, $facts->omitted_hidden_column_count);
        $this->assertSame(1, $facts->visible_sheet_count);
    }

    public function test_database_still_rejects_out_of_budget_counts(): void
    {
        $facts = $this->facts();
        foreach ([
            'visible_sheet_count' => 21,
            'omitted_hidden_sheet_count' => 2_000,
            'omitted_hidden_row_count' => 20_971_521,
            'omitted_hidden_column_count' => 327_681,
            'projected_row_extent_count' => 400_001,
            'projected_column_extent_count' => 5_121,
            'expanded_bytes' => 67_108_865,
            'cell_count' => 100_001,
        ] as $field => $value) {
            $this->assertRejectedUpdate($facts, $field, $value);
        }
    }

    public function test_migration_restores_the_prior_constraint_and_can_be_reapplied(): void
    {
        $facts = $this->facts();
        $migration = $this->migration();
        (new ReflectionMethod($migration, 'down'))->invoke($migration);
        $this->assertRejectedUpdate($facts, 'omitted_hidden_column_count', 16_128);
        (new ReflectionMethod($migration, 'up'))->invoke($migration);
        $facts->forceFill(['omitted_hidden_column_count' => 16_128])->save();
        $this->assertSame(16_128, $facts->refresh()->omitted_hidden_column_count);
    }

    public function test_downgrade_refuses_to_invalidate_retained_artifact_facts(): void
    {
        $facts = $this->facts();
        $facts->forceFill(['omitted_hidden_sheet_count' => 20])->save();
        $migration = $this->migration();
        try {
            (new ReflectionMethod($migration, 'down'))->invoke($migration);
            $this->fail('Downgrade must preserve facts that require the expanded hidden-content profile.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cannot narrow XLSX hidden-content limits while retained versions exceed the previous profile.', $exception->getMessage());
        }
        $this->assertSame(20, $facts->refresh()->omitted_hidden_sheet_count);
        $facts->forceFill(['omitted_hidden_column_count' => 16_128])->save();
        $this->assertSame(16_128, $facts->refresh()->omitted_hidden_column_count);
    }

    private function migration(): Migration
    {
        $migration = require database_path('migrations/2026_11_04_000001_separate_xlsx_hidden_content_limits.php');
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    private function assertRejectedUpdate(XlsxVersionFact $facts, string $field, int $value): void
    {
        try {
            DB::transaction(fn (): int => XlsxVersionFact::query()->whereKey($facts->page_version_uid)->update([$field => $value]));
            $this->fail('Out-of-budget XLSX facts were accepted: ' . $field);
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    private function facts(): XlsxVersionFact
    {
        $page = Page::factory()->create(['type' => PageType::Xlsx]);
        $version = PageVersion::factory()->forPage($page)->create();
        $derivative = PageVersionDerivative::query()->forceCreate([
            'page_version_uid' => $version->uid,
            'kind' => ArtifactDerivativeKind::XlsxManifest,
            'storage_path' => 'test/' . $version->uid . '.json',
            'content_hash' => hash('sha256', '{}'),
            'byte_size' => 2,
        ]);

        return XlsxVersionFact::query()->forceCreate([
            'page_version_uid' => $version->uid,
            'manifest_derivative_uid' => $derivative->uid,
            'processor_profile' => 'xlsx-typed-view-v1',
            'manifest_schema' => 'xlsx-view-manifest-v1',
            'engine_name' => 'sheetjs-ce',
            'engine_version' => '0.20.3',
            'package_entry_count' => 30,
            'expanded_bytes' => 1024,
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
}
