<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration {
    public function up(): void
    {
        $this->replaceCountsConstraint(2_000, 20_971_520, 327_680);
    }

    public function down(): void
    {
        if (DB::table('xlsx_version_facts')
            ->whereRaw('visible_sheet_count + omitted_hidden_sheet_count > 20')
            ->orWhere('omitted_hidden_row_count', '>', 400_000)
            ->orWhere('omitted_hidden_column_count', '>', 5_120)
            ->exists()) {
            throw new RuntimeException('Cannot narrow XLSX hidden-content limits while retained versions exceed the previous profile.');
        }

        $this->replaceCountsConstraint(20, 400_000, 5_120);
    }

    private function replaceCountsConstraint(int $totalSheets, int $hiddenRows, int $hiddenColumns): void
    {
        // One atomic DDL statement keeps the previous constraint intact on failure.
        DB::statement(
            'ALTER TABLE xlsx_version_facts DROP CONSTRAINT xlsx_version_facts_counts_check, '
                . 'ADD CONSTRAINT xlsx_version_facts_counts_check CHECK ('
                . 'package_entry_count BETWEEN 1 AND 2000 '
                . 'AND expanded_bytes BETWEEN 1 AND 67108864 '
                . 'AND visible_sheet_count BETWEEN 1 AND 20 '
                . 'AND omitted_hidden_sheet_count BETWEEN 0 AND ' . $totalSheets . ' '
                . 'AND visible_sheet_count + omitted_hidden_sheet_count <= ' . $totalSheets . ' '
                . 'AND projected_row_extent_count <= 400000 '
                . 'AND projected_column_extent_count <= 5120 '
                . 'AND omitted_hidden_row_count BETWEEN 0 AND ' . $hiddenRows . ' '
                . 'AND omitted_hidden_column_count BETWEEN 0 AND ' . $hiddenColumns . ' '
                . 'AND cell_count <= 100000 '
                . 'AND formula_count <= cell_count '
                . 'AND uncached_formula_count <= formula_count '
                . 'AND link_count <= cell_count '
                . 'AND merge_count <= 1000 '
                . 'AND truncated = false)',
        );
    }
};
