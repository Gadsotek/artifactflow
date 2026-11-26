<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->boolean('passage_retrieval_enabled')->default(false);
            $table->bigInteger('passage_retrieval_generation')->default(0);
            $table->integer('passage_retrieval_extractor_revision')->nullable();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE installation_settings ADD CONSTRAINT installation_passage_policy_check CHECK (
                passage_retrieval_generation >= 0 AND
                ((NOT passage_retrieval_enabled AND passage_retrieval_extractor_revision IS NULL) OR
                 (passage_retrieval_enabled AND passage_retrieval_generation > 0 AND
                  passage_retrieval_extractor_revision IS NOT NULL AND passage_retrieval_extractor_revision > 0))
            )
            SQL);

        Schema::create('passage_sets', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->foreignUlid('page_version_uid')->references('uid')->on('page_versions')->cascadeOnDelete();
            $table->bigInteger('generation');
            $table->integer('extractor_revision');
            $table->char('source_hash', 64);
            $table->string('outcome', 16);
            $table->unique(['page_version_uid', 'generation', 'extractor_revision'], 'passage_sets_version_generation_unique');
            $table->index(['generation', 'uid']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE passage_sets ADD CONSTRAINT passage_sets_identity_check CHECK (
                generation > 0 AND extractor_revision > 0 AND source_hash ~ '^[0-9a-f]{64}$'
                AND outcome IN ('complete', 'limited', 'empty')
            )
            SQL);

        Schema::create('passages', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->foreignUlid('passage_set_uid')->references('uid')->on('passage_sets')->cascadeOnDelete();
            $table->smallInteger('ordinal');
            $table->jsonb('heading_path');
            $table->text('excerpt');
            $table->jsonb('source_locator');
            $table->unique(['passage_set_uid', 'ordinal']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE passages ADD CONSTRAINT passages_evidence_check CHECK (
                ordinal BETWEEN 0 AND 255 AND octet_length(excerpt) BETWEEN 1 AND 4096
                AND jsonb_typeof(heading_path) = 'array'
                AND COALESCE(source_locator->>'kind', '') IN ('markdown_source', 'html_source_ranges')
            ), ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                setweight(jsonb_to_tsvector('simple'::regconfig, heading_path, '["string"]'::jsonb), 'A') ||
                setweight(to_tsvector('simple'::regconfig, excerpt), 'B')
            ) STORED
            SQL);
        DB::statement('CREATE INDEX passages_search_vector_gin ON passages USING gin (search_vector)');
    }

    public function down(): void
    {
        Schema::dropIfExists('passages');
        Schema::dropIfExists('passage_sets');
        DB::statement('ALTER TABLE installation_settings DROP CONSTRAINT installation_passage_policy_check');
        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->dropColumn(['passage_retrieval_enabled', 'passage_retrieval_generation', 'passage_retrieval_extractor_revision']);
        });
    }
};
