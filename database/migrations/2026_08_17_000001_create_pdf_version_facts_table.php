<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('pdf_version_facts', function (Blueprint $table): void {
            // One derived-fact extension row per immutable page version. The row
            // may be refreshed by an explicit reprocess while original path,
            // hash, byte size, and provenance remain immutable on page_versions.
            $table->ulid('page_version_uid')->primary();
            $table->unsignedInteger('page_count');
            $table->string('pdf_version', 16);
            $table->string('extraction_state', 32)->index();
            $table->string('processor_profile', 120);
            $table->timestampsTz();

            $table->foreign('page_version_uid')
                ->references('uid')
                ->on('page_versions')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE pdf_version_facts ADD CONSTRAINT pdf_version_facts_page_count_check '
                . 'CHECK (page_count >= 1)',
        );
        DB::statement(
            'ALTER TABLE pdf_version_facts ADD CONSTRAINT pdf_version_facts_pdf_version_check '
                . "CHECK (pdf_version ~ '^[0-9]+[.][0-9]+$')",
        );
        DB::statement(
            'ALTER TABLE pdf_version_facts ADD CONSTRAINT pdf_version_facts_extraction_state_check '
                . "CHECK (extraction_state IN ('indexed', 'no_embedded_text', 'partially_indexed'))",
        );
        DB::statement(
            'ALTER TABLE pdf_version_facts ADD CONSTRAINT pdf_version_facts_processor_profile_check '
                . 'CHECK (char_length(processor_profile) BETWEEN 1 AND 120)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_version_facts');
    }
};
