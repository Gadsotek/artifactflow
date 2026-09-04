<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE pages DROP CONSTRAINT pages_type_check');
        DB::statement(
            'ALTER TABLE pages ADD CONSTRAINT pages_type_check '
                . "CHECK (type IN ('markdown', 'html_artifact', 'image', 'pdf', 'xlsx', 'docx'))",
        );

        Schema::create('page_version_derivatives', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('page_version_uid');
            $table->string('kind', 32);
            $table->string('storage_path', 512)->unique();
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('byte_size');
            $table->timestampsTz();

            $table->unique(['page_version_uid', 'kind']);
            $table->unique(
                ['uid', 'page_version_uid', 'kind'],
                'page_version_derivatives_identity_owner_kind_unique',
            );
            $table->foreign('page_version_uid')
                ->references('uid')
                ->on('page_versions')
                ->cascadeOnDelete();
        });

        Schema::create('xlsx_version_facts', function (Blueprint $table): void {
            $table->ulid('page_version_uid')->primary();
            $table->ulid('manifest_derivative_uid')->unique();
            $table->string('manifest_derivative_kind', 32)->default('xlsx_manifest');
            $table->string('processor_profile', 64);
            $table->string('manifest_schema', 64);
            $table->string('engine_name', 64);
            $table->string('engine_version', 32);
            $table->unsignedInteger('package_entry_count');
            $table->unsignedBigInteger('expanded_bytes');
            $table->unsignedSmallInteger('visible_sheet_count');
            $table->unsignedSmallInteger('omitted_hidden_sheet_count');
            $table->unsignedInteger('projected_row_extent_count');
            $table->unsignedInteger('projected_column_extent_count');
            $table->unsignedInteger('omitted_hidden_row_count');
            $table->unsignedInteger('omitted_hidden_column_count');
            $table->unsignedInteger('cell_count');
            $table->unsignedInteger('formula_count');
            $table->unsignedInteger('uncached_formula_count');
            $table->unsignedInteger('link_count');
            $table->unsignedInteger('merge_count');
            $table->boolean('truncated');
            $table->timestampTz('processed_at');
            $table->timestampsTz();

            $table->foreign('page_version_uid')
                ->references('uid')
                ->on('page_versions')
                ->cascadeOnDelete();
            $table->foreign(
                ['manifest_derivative_uid', 'page_version_uid', 'manifest_derivative_kind'],
                'xlsx_facts_derivative_owner_kind_fk',
            )->references(['uid', 'page_version_uid', 'kind'])
                ->on('page_version_derivatives')
                ->cascadeOnDelete();
        });

        Schema::create('docx_version_facts', function (Blueprint $table): void {
            $table->ulid('page_version_uid')->primary();
            $table->ulid('preview_derivative_uid')->unique();
            $table->string('preview_derivative_kind', 32)->default('docx_preview_pdf');
            $table->string('docx_processor_profile', 64);
            $table->string('pdf_processor_profile', 64);
            $table->string('engine_name', 64);
            $table->string('engine_version', 32);
            $table->unsignedInteger('package_entry_count');
            $table->unsignedBigInteger('expanded_bytes');
            $table->unsignedInteger('relationship_count');
            $table->unsignedInteger('media_count');
            $table->unsignedInteger('external_hyperlink_count');
            $table->unsignedSmallInteger('page_count');
            $table->string('pdf_version', 8);
            $table->string('extraction_state', 32);
            $table->timestampTz('processed_at');
            $table->timestampsTz();

            $table->foreign('page_version_uid')->references('uid')->on('page_versions')->cascadeOnDelete();
            $table->foreign(
                ['preview_derivative_uid', 'page_version_uid', 'preview_derivative_kind'],
                'docx_facts_derivative_owner_kind_fk',
            )->references(['uid', 'page_version_uid', 'kind'])
                ->on('page_version_derivatives')
                ->cascadeOnDelete();
        });

        DB::statement(
            "ALTER TABLE page_version_derivatives ADD CONSTRAINT page_version_derivatives_kind_check CHECK (kind IN ('xlsx_manifest', 'docx_preview_pdf'))",
        );
        DB::statement(
            "ALTER TABLE page_version_derivatives ADD CONSTRAINT page_version_derivatives_hash_check CHECK (content_hash ~ '^[0-9a-f]{64}$')",
        );
        DB::statement(
            'ALTER TABLE page_version_derivatives ADD CONSTRAINT page_version_derivatives_byte_size_check CHECK (byte_size > 0)',
        );
        DB::statement(
            'ALTER TABLE xlsx_version_facts ADD CONSTRAINT xlsx_version_facts_counts_check CHECK ('
                . 'package_entry_count BETWEEN 1 AND 2000 '
                . 'AND expanded_bytes BETWEEN 1 AND 67108864 '
                . 'AND visible_sheet_count BETWEEN 1 AND 20 '
                . 'AND omitted_hidden_sheet_count <= 20 '
                . 'AND visible_sheet_count + omitted_hidden_sheet_count <= 20 '
                . 'AND projected_row_extent_count <= 400000 '
                . 'AND projected_column_extent_count <= 5120 '
                . 'AND omitted_hidden_row_count <= 400000 '
                . 'AND omitted_hidden_column_count <= 5120 '
                . 'AND cell_count <= 100000 '
                . 'AND formula_count <= cell_count '
                . 'AND uncached_formula_count <= formula_count '
                . 'AND link_count <= cell_count '
                . 'AND merge_count <= 1000 '
                . 'AND truncated = false)',
        );
        DB::statement(
            "ALTER TABLE xlsx_version_facts ADD CONSTRAINT xlsx_version_facts_derivative_kind_check CHECK (manifest_derivative_kind = 'xlsx_manifest')",
        );
        DB::statement(
            'ALTER TABLE docx_version_facts ADD CONSTRAINT docx_version_facts_counts_check CHECK ('
                . 'package_entry_count BETWEEN 1 AND 2000 '
                . 'AND expanded_bytes BETWEEN 1 AND 67108864 '
                . 'AND relationship_count <= 4000 '
                . 'AND media_count <= 1024 '
                . 'AND external_hyperlink_count <= 1000 '
                . 'AND page_count BETWEEN 1 AND 250)',
        );
        DB::statement(
            "ALTER TABLE docx_version_facts ADD CONSTRAINT docx_version_facts_extraction_state_check CHECK (extraction_state = 'indexed')",
        );
        DB::statement(
            "ALTER TABLE docx_version_facts ADD CONSTRAINT docx_version_facts_derivative_kind_check CHECK (preview_derivative_kind = 'docx_preview_pdf')",
        );
    }

    public function down(): void
    {
        if (DB::table('pages')->whereIn('type', ['xlsx', 'docx'])->exists()) {
            throw new \RuntimeException(
                'Cannot roll back XLSX/DOCX support while Office artifact pages exist. Delete those pages deliberately before retrying the downgrade.',
            );
        }

        Schema::dropIfExists('docx_version_facts');
        Schema::dropIfExists('xlsx_version_facts');
        Schema::dropIfExists('page_version_derivatives');

        DB::statement('ALTER TABLE pages DROP CONSTRAINT pages_type_check');
        DB::statement(
            'ALTER TABLE pages ADD CONSTRAINT pages_type_check '
                . "CHECK (type IN ('markdown', 'html_artifact', 'image', 'pdf'))",
        );
    }
};
